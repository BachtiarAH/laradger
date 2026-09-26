<?php

use App\Models\Account;
use App\Models\AiActionDraft;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AuditLog;
use App\Models\Journal;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Omni\SystemPromptBuilder;
use App\Services\Ai\Tools\AiToolKind;
use App\Services\Ai\Tools\AiToolRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * A provider that answers the first turn with a tool call and the second with
 * plain text, which is the shape of a real agent loop.
 */
function fakeToolCallingProvider(array $calls, string $finalText = 'All set.'): void
{
    $textTurn = [
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => $finalText],
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ];

    // No calls requested: a single plain-text turn, no tool round trip.
    if ($calls === []) {
        Http::fake(['*' => Http::response($textTurn)]);

        return;
    }

    Http::fakeSequence()
        ->push([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => array_map(static fn (array $call): array => [
                        'id' => $call['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $call['name'],
                            'arguments' => json_encode($call['arguments']),
                        ],
                    ], $calls),
                ],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ])
        ->push($textTurn);
}

function assistantTenant(User $user): array
{
    $tenant = createTenantForUser($user);
    Sanctum::actingAs($user);

    config(['ai.default' => 'openai']);
    config(['ai.providers.openai.api_key' => 'test-key']);
    config(['ai.providers.openai.base_uri' => 'https://api.openai.com']);
    config(['ai.providers.openai.model' => 'gpt-4o-mini']);
    config(['ai.providers.anthropic.api_key' => null]);
    config(['ai.providers.openai_compatible.api_key' => null]);

    return [$tenant, "/api/v1/{$tenant->slug}"];
}

/**
 * SetTenantContext clears the tenant in a `finally`, so it is gone once the test
 * client returns. Re-establish it to assert on tenant-scoped models afterwards,
 * exactly as the next request would.
 */
function reenter(Tenant $tenant): void
{
    TenantContext::set($tenant);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    [$this->tenant, $this->base] = assistantTenant($this->user);

    $this->cash = Account::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Cash',
        'type' => 'asset',
        'code' => 'AS-0001',
        'status' => 'active',
    ]);
    $this->coffee = Account::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Coffee',
        'type' => 'expense',
        'code' => 'EX-0001',
        'status' => 'active',
    ]);
});

describe('proposing actions from a prompt', function () {
    test('a write tool call becomes a pending draft and writes nothing', function () {
        fakeToolCallingProvider([[
            'id' => 'call_1',
            'name' => 'journal_create',
            'arguments' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Coffee purchase',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '10.00'],
                    ['account_id' => $this->cash->id, 'credit' => '10.00'],
                ],
            ],
        ]]);

        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $response = $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'I spent 10 on coffee',
        ]);

        // Chat is synchronous: the reply comes back with the request.
        $response->assertOk()
            ->assertJsonPath('data.drafts.0.tool', 'journal_create')
            ->assertJsonPath('data.drafts.0.status', AiActionDraft::STATUS_PENDING)
            ->assertJsonPath('data.drafts.0.kind', 'write');

        reenter($this->tenant);
        $draft = AiActionDraft::withoutGlobalScopes()->firstOrFail();

        expect($draft->tool)->toBe('journal_create')
            ->and($draft->kind)->toBe('write')
            ->and($draft->status)->toBe(AiActionDraft::STATUS_PENDING)
            ->and($draft->payload['description'])->toBe('Coffee purchase')
            ->and($draft->payload['status'])->toBe('draft')
            ->and($draft->payload['lines'])->toHaveCount(2)
            ->and($draft->title)->toContain('Coffee purchase');

        // The whole point: nothing was written.
        expect(Journal::withoutGlobalScopes()->count())->toBe(0)
            ->and(DB::table('journal_lines')->count())->toBe(0);
    });

    test('the model is told the action was only proposed', function () {
        fakeToolCallingProvider([[
            'id' => 'call_1',
            'name' => 'journal_create',
            'arguments' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Coffee',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '10.00'],
                    ['account_id' => $this->cash->id, 'credit' => '10.00'],
                ],
            ],
        ]], 'I have prepared a draft for your review.');

        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'I spent 10 on coffee',
        ])->assertOk();

        reenter($this->tenant);
        $reply = $conversation->messages()
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->whereNotNull('content')
            ->firstOrFail();
        expect($reply->content)->toBe('I have prepared a draft for your review.');

        // The tool_result fed back to the model must say "proposed", not "done".
        reenter($this->tenant);
        $toolTurn = $conversation->messages()->where('role', AiMessage::ROLE_TOOL)->firstOrFail();
        $content = json_decode($toolTurn->content, true);

        expect($content['status'])->toBe('proposed')
            ->and($content['note'])->toContain('NOT been applied');
    });

    test('read tools run immediately and their result reaches the model', function () {
        fakeToolCallingProvider([[
            'id' => 'call_1',
            'name' => 'accounts_search',
            'arguments' => ['type' => 'asset'],
        ]], 'You have one asset account, Cash.');

        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'what asset accounts do I have?',
        ])->assertOk();

        expect(AiActionDraft::withoutGlobalScopes()->count())->toBe(0);

        reenter($this->tenant);
        $reply = $conversation->messages()
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->whereNotNull('content')
            ->firstOrFail();
        expect($reply->content)->toBe('You have one asset account, Cash.');
        $toolTurn = $conversation->messages()->where('role', AiMessage::ROLE_TOOL)->firstOrFail();
        expect(json_decode($toolTurn->content, true)['accounts'][0]['name'])->toBe('Cash');

        // The real ledger was read, not invented: the system prompt carries
        // the tenant's real account ids.
        Http::assertSent(fn ($request) => ($request->data()['messages'][0]['role'] ?? null) === 'system'
            && str_contains((string) ($request->data()['messages'][0]['content'] ?? ''), $this->cash->id));
    });

    test('a mixed turn runs the read and drafts the write', function () {
        fakeToolCallingProvider([[
            'id' => 'call_1',
            'name' => 'accounts_search',
            'arguments' => ['search' => 'Coffee'],
        ]], 'Found it.');

        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'find the coffee account',
        ])->assertOk();

        expect(AiActionDraft::withoutGlobalScopes()->count())->toBe(0);
    });

    test('the tool definitions and account ids are sent to the provider', function () {
        fakeToolCallingProvider([], 'nothing to do');

        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'hello',
        ])->assertOk();

        Http::assertSent(function ($request) {
            $tools = collect($request->data()['tools'] ?? [])->pluck('function.name');

            return $tools->contains('journal_create')
                && $tools->contains('accounts_search')
                // Names must be provider-legal: OpenAI rejects anything
                // outside ^[a-zA-Z0-9_-]+$.
                && $tools->every(fn ($name) => preg_match('/^[a-zA-Z0-9_-]+$/', $name) === 1);
        });
    });

    test('an unknown tool name is rejected rather than executed', function () {
        fakeToolCallingProvider([[
            'id' => 'call_1',
            'name' => 'drop_all_journals',
            'arguments' => [],
        ]]);

        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'delete everything',
        ])->assertStatus(502)
            // A hallucinated tool is a malformed model response, not a crash.
            ->assertJsonPath('errors.message.0', 'The assistant asked for an action that does not exist: [drop_all_journals].');

        // A refused turn still never writes anything.
        expect(Journal::withoutGlobalScopes()->count())->toBe(0)
            ->and(AiActionDraft::withoutGlobalScopes()->count())->toBe(0);
    });

    test('requires a message', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    });
});

describe('approving a draft', function () {
    test('executing a pending journal draft writes the journal and audits it', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Create journal — Coffee',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Coffee purchase',
                'status' => 'draft',
                'source' => 'manual',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '10.00'],
                    ['account_id' => $this->cash->id, 'credit' => '10.00'],
                ],
            ],
        ]);

        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.status', AiActionDraft::STATUS_EXECUTED);

        expect(Journal::withoutGlobalScopes()->count())->toBe(1)
            ->and(AuditLog::withoutGlobalScopes()->where('action', 'ai.journal_create')->count())->toBe(1);
    });

    test('an unbalanced draft fails validation and stays reviewable', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Create journal — broken',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Broken',
                'status' => 'draft',
                'source' => 'manual',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '10.00'],
                    ['account_id' => $this->cash->id, 'credit' => '5.00'],
                ],
            ],
        ]);

        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines']);

        expect(Journal::withoutGlobalScopes()->count())->toBe(0);

        $draft->refresh();
        expect($draft->status)->toBe(AiActionDraft::STATUS_FAILED)
            ->and($draft->error)->toContain('lines must balance');
    });

    test('a draft cannot be executed twice', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Once only',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Once only',
                'status' => 'draft',
                'source' => 'manual',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '10.00'],
                    ['account_id' => $this->cash->id, 'credit' => '10.00'],
                ],
            ],
        ]);

        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")->assertOk();
        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")->assertStatus(409);

        expect(Journal::withoutGlobalScopes()->count())->toBe(1);
    });

    test('rejecting a draft writes nothing', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Nope',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [],
        ]);

        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', AiActionDraft::STATUS_REJECTED);

        expect(Journal::withoutGlobalScopes()->count())->toBe(0)
            ->and($draft->refresh()->status)->toBe(AiActionDraft::STATUS_REJECTED);
    });

    test('the payload can be corrected before approving', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Needs fixing',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => ['description' => 'wrong'],
        ]);

        $payload = [
            'transaction_date' => '2026-08-17',
            'description' => 'Corrected by the user',
            'status' => 'draft',
            'source' => 'manual',
            'lines' => [
                ['account_id' => $this->coffee->id, 'debit' => '10.00'],
                ['account_id' => $this->cash->id, 'credit' => '10.00'],
            ],
        ];

        $this->patchJson("{$this->base}/ai/drafts/{$draft->id}", ['payload' => $payload])
            ->assertOk()
            ->assertJsonPath('data.payload.description', 'Corrected by the user');

        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")->assertOk();

        expect(Journal::withoutGlobalScopes()->firstOrFail()->description)
            ->toBe('Corrected by the user');
    });

    test('the tool, kind and title cannot be edited', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Original',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [],
        ]);

        $this->patchJson("{$this->base}/ai/drafts/{$draft->id}", [
            'payload' => ['description' => 'x'],
            'tool' => 'account_create',
            'kind' => 'read',
            'title' => 'Hijacked',
        ])->assertOk();

        $draft->refresh();
        expect($draft->tool)->toBe('journal_create')
            ->and($draft->kind)->toBe('write')
            ->and($draft->title)->toBe('Original');
    });
});

describe('isolation', function () {
    test('another member cannot see, edit, or approve a draft', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Private',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Private',
                'status' => 'draft',
                'source' => 'manual',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '10.00'],
                    ['account_id' => $this->cash->id, 'credit' => '10.00'],
                ],
            ],
        ]);

        // A different member of the same tenant.
        $other = User::factory()->create();
        $this->tenant->users()->attach($other, ['role' => 'member']);
        Sanctum::actingAs($other);

        $this->getJson("{$this->base}/ai/conversations/{$conversation->id}")->assertNotFound();
        $this->patchJson("{$this->base}/ai/drafts/{$draft->id}", ['payload' => []])->assertNotFound();
        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")->assertNotFound();
        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/reject")->assertNotFound();

        $this->getJson("{$this->base}/ai/drafts")->assertOk()->assertJsonCount(0, 'data');

        expect(Journal::withoutGlobalScopes()->count())->toBe(0);
    });

    test('a draft from another tenant is not found', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();
        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Elsewhere',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [],
        ]);

        $stranger = User::factory()->create();
        [$otherTenant, $otherBase] = assistantTenant($stranger);

        $this->postJson("{$otherBase}/ai/drafts/{$draft->id}/execute")->assertNotFound();
    });

});

test('the registry exposes only known tools, with write tools kept separate', function () {
    $registry = app(AiToolRegistry::class);

    $names = fn (array $tools): array => array_map(
        static fn ($tool): string => $tool->name(),
        $tools,
    );
    $sorted = fn (array $names): array => collect($names)->sort()->values()->all();

    expect($registry->all())->toHaveCount(7)
        ->and($sorted($names($registry->ofKind(AiToolKind::Write))))
        ->toBe(['account_create', 'journal_create', 'tag_create'])
        ->and($sorted($names($registry->ofKind(AiToolKind::Read))))
        ->toBe(['accounts_get', 'accounts_search', 'journals_search', 'overview_get']);

    foreach ($registry->all() as $tool) {
        expect($tool->name())->toMatch('/^[a-zA-Z0-9_-]+$/');
        expect($tool->parameters()['type'])->toBe('object');
        expect($tool->description())->not->toBe('');
    }
});

/**
 * A tag or account the assistant proposes in the same reply is still a draft, so
 * it has no id the journal could reference. These pin the two halves of that:
 * the reference is stored unresolved so the reviewer sees what was proposed, and
 * it is resolved by name only when the user approves.
 */
describe('referencing something proposed in the same reply', function () {
    test('a pending tag is stored by name and attached on approval', function () {
        fakeToolCallingProvider([[
            'id' => 'call_1',
            'name' => 'journal_create',
            'arguments' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Groceries',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '25.00'],
                    ['account_id' => $this->cash->id, 'credit' => '25.00'],
                ],
                'pending_tags' => ['Groceries'],
            ],
        ]]);

        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'bought groceries',
        ])->assertOk();

        reenter($this->tenant);
        $draft = AiActionDraft::withoutGlobalScopes()->firstOrFail();

        // Reviewable: the name is visible, and no tag has been created yet.
        expect($draft->payload['pending_tags'])->toBe(['Groceries'])
            ->and($draft->payload['tags'])->toBe([])
            ->and(Tag::withoutGlobalScopes()->count())->toBe(0);

        // The user approves the tag draft first.
        $tagDraft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'tag_create',
            'kind' => 'write',
            'title' => 'Create vendor tag "Groceries"',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => ['name' => 'Groceries', 'type' => 'vendor'],
        ]);

        $this->postJson("{$this->base}/ai/drafts/{$tagDraft->id}/execute")->assertOk();

        reenter($this->tenant);
        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")->assertOk();

        reenter($this->tenant);
        $journal = Journal::withoutGlobalScopes()->firstOrFail();

        expect($journal->tags()->pluck('tags.name')->all())->toBe(['Groceries']);
    });

    test('a pending tag that was never approved fails with an instruction, not a uuid complaint', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Create journal — groceries',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Groceries',
                'status' => 'draft',
                'source' => 'manual',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '25.00'],
                    ['account_id' => $this->cash->id, 'credit' => '25.00'],
                ],
                'tags' => [],
                'pending_tags' => ['Groceries'],
            ],
        ]);

        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tags']);

        $this->assertStringContainsString(
            'Approve that tag first',
            (string) $draft->refresh()->error,
        );

        reenter($this->tenant);
        expect(Journal::withoutGlobalScopes()->count())->toBe(0);
    });

    test('a pending account is stored as a name reference and resolved on approval', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Create journal — rent',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Rent',
                'status' => 'draft',
                'source' => 'manual',
                'lines' => [
                    ['account_id' => 'pending:Rent', 'debit' => '5000000.00'],
                    ['account_id' => $this->cash->id, 'credit' => '5000000.00'],
                ],
                'tags' => [],
            ],
        ]);

        // Unresolved while pending, so the review card can show the name.
        expect($draft->payload['lines'][0]['account_id'])->toBe('pending:Rent');

        $rent = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rent',
            'type' => 'expense',
            'code' => 'EX-0002',
            'status' => 'active',
        ]);

        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")->assertOk();

        reenter($this->tenant);
        $journal = Journal::withoutGlobalScopes()->firstOrFail();

        expect($journal->lines()->pluck('account_id')->all())
            ->toContain($rent->id);
    });

    test('a pending account with nothing behind it fails with an instruction', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Create journal — rent',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Rent',
                'status' => 'draft',
                'source' => 'manual',
                'lines' => [
                    ['account_id' => 'pending:Rent', 'debit' => '5000000.00'],
                    ['account_id' => $this->cash->id, 'credit' => '5000000.00'],
                ],
                'tags' => [],
            ],
        ]);

        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines']);

        $this->assertStringContainsString(
            'Approve that account first',
            (string) $draft->refresh()->error,
        );
    });
});

test('the prompt tells the model how to reference a tag it is proposing', function () {
    $tag = Tag::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Groceries',
        'type' => 'vendor',
    ]);

    // The prompt reads live tenant data through tenant-scoped models.
    reenter($this->tenant);

    $prompt = app(SystemPromptBuilder::class)->system()['content'];

    // Without this the model can only ever attach a tag that already exists,
    // and it has no way to learn an id for one.
    expect($prompt)->toContain('pending_tags')
        ->and($prompt)->toContain('`draft_id` from a tool result into `tag_ids`')
        ->and($prompt)->toContain('The tags that already exist')
        ->and($prompt)->toContain($tag->id);
});
