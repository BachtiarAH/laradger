<?php

use App\Jobs\RunAiDraftRequest;
use App\Models\Account;
use App\Models\AiActionDraft;
use App\Models\AiConversation;
use App\Models\AiDraftRequest;
use App\Models\Journal;
use App\Models\User;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Omni\OmniAssistant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/**
 * The queued, fire-and-forget drafting path — deliberately separate from the
 * synchronous chat in AiAssistantTest.
 */
function draftRequestTenant(User $user): array
{
    $tenant = createTenantForUser($user);
    Sanctum::actingAs($user);

    config(['ai.default' => 'openai']);
    config(['ai.providers.openai.api_key' => 'test-key']);
    config(['ai.providers.openai.base_uri' => 'https://api.openai.com']);
    config(['ai.providers.anthropic.api_key' => null]);
    config(['ai.providers.openai_compatible.api_key' => null]);

    return [$tenant, "/api/v1/{$tenant->slug}"];
}

function journalToolCall(Account $expense, Account $asset): array
{
    return [
        'id' => 'call_1',
        'type' => 'function',
        'function' => [
            'name' => 'journal_create',
            'arguments' => json_encode([
                'transaction_date' => '2026-08-17',
                'description' => 'Queued coffee',
                'lines' => [
                    ['account_id' => $expense->getKey(), 'debit' => '4.50'],
                    ['account_id' => $asset->getKey(), 'credit' => '4.50'],
                ],
            ]),
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    [$this->tenant, $this->base] = draftRequestTenant($this->user);

    $this->cash = Account::factory()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Cash', 'type' => 'asset',
        'code' => 'AS-0001', 'status' => 'active',
    ]);
    $this->coffee = Account::factory()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Coffee', 'type' => 'expense',
        'code' => 'EX-0001', 'status' => 'active',
    ]);
});

test('submitting a prompt queues it and does nothing yet', function () {
    Queue::fake();

    $this->postJson("{$this->base}/ai/draft-requests", [
        'prompt' => 'Record 4.50 of coffee paid in cash',
    ])->assertStatus(202)
        ->assertJsonPath('data.status', AiDraftRequest::STATUS_QUEUED)
        ->assertJsonPath('data.drafts_count', 0)
        ->assertJsonPath('data.prompt', 'Record 4.50 of coffee paid in cash');

    Queue::assertPushed(RunAiDraftRequest::class, 1);

    // Queued means queued: no provider call, no draft, no journal.
    Http::assertNothingSent();
    expect(AiDraftRequest::withoutGlobalScopes()->count())->toBe(1)
        ->and(AiActionDraft::withoutGlobalScopes()->count())->toBe(0)
        ->and(Journal::withoutGlobalScopes()->count())->toBe(0);
});

test('the prompt is required', function () {
    $this->postJson("{$this->base}/ai/draft-requests", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['prompt']);
});

test('a queued request appears in the list as in queue', function () {
    AiDraftRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);

    $this->getJson("{$this->base}/ai/draft-requests")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', AiDraftRequest::STATUS_QUEUED);
});

test('the job drafts the actions and marks the request completed', function () {
    $draftRequest = AiDraftRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'prompt' => 'Record 4.50 of coffee paid in cash',
    ]);

    Http::fakeSequence()
        ->push(['choices' => [['message' => ['tool_calls' => [journalToolCall($this->coffee, $this->cash)]]]]])
        ->push(['choices' => [['message' => ['content' => 'Draft prepared.']]]]);

    TenantContext::forget();
    auth()->forgetGuards();

    expect(TenantContext::hasTenant())->toBeFalse();

    (new RunAiDraftRequest($draftRequest->getKey()))->handle(app(OmniAssistant::class));

    $draftRequest->refresh();

    expect($draftRequest->status)->toBe(AiDraftRequest::STATUS_COMPLETED)
        ->and($draftRequest->drafts_count)->toBe(1)
        ->and($draftRequest->error)->toBeNull()
        ->and($draftRequest->started_at)->not->toBeNull()
        ->and($draftRequest->ai_conversation_id)->not->toBeNull();

    $draft = AiActionDraft::withoutGlobalScopes()->firstOrFail();

    expect($draft->status)->toBe(AiActionDraft::STATUS_PENDING)
        ->and($draft->ai_draft_request_id)->toBe($draftRequest->getKey())
        // Proposed only. The job never applies anything.
        ->and(Journal::withoutGlobalScopes()->count())->toBe(0);

    // The prompt is the first message of the conversation it ran in. Queried
    // unscoped: the job clears the tenant context on the way out, so the
    // conversation's own scope is fail-closed here.
    $conversation = AiConversation::withoutGlobalScopes()
        ->findOrFail($draftRequest->ai_conversation_id);

    expect($conversation->messages()->withoutGlobalScopes()->where('role', 'user')->firstOrFail()->content)
        ->toBe('Record 4.50 of coffee paid in cash');
});

test('the job reports in progress while it runs and done afterwards', function () {
    $draftRequest = AiDraftRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);

    // A provider that stalls lets us observe the running state.
    Http::fake(function () use ($draftRequest) {
        $fresh = AiDraftRequest::withoutGlobalScopes()->find($draftRequest->getKey());

        expect($fresh->status)->toBe(AiDraftRequest::STATUS_RUNNING)
            ->and($fresh->started_at)->not->toBeNull();

        return Http::response(['choices' => [['message' => ['content' => 'nothing to do']]]]);
    });

    TenantContext::forget();

    (new RunAiDraftRequest($draftRequest->getKey()))->handle(app(OmniAssistant::class));

    expect($draftRequest->refresh()->status)->toBe(AiDraftRequest::STATUS_COMPLETED)
        ->and($draftRequest->drafts_count)->toBe(0);
});

test('a failing provider marks the request failed with a safe message', function () {
    $draftRequest = AiDraftRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);

    Http::fake(['https://api.openai.com/*' => Http::response([], 500)]);

    TenantContext::forget();

    expect(fn () => (new RunAiDraftRequest($draftRequest->getKey()))->handle(app(OmniAssistant::class)))
        ->toThrow(AiProviderException::class);

    $draftRequest->refresh();

    expect($draftRequest->status)->toBe(AiDraftRequest::STATUS_FAILED)
        ->and($draftRequest->error)->toBeString()
        ->and($draftRequest->error)->not->toContain('api.openai.com')
        ->and($draftRequest->error)->not->toContain('test-key')
        ->and(AiActionDraft::withoutGlobalScopes()->count())->toBe(0);
});

test('the job clears the tenant context afterwards', function () {
    $draftRequest = AiDraftRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);

    Http::fake(['https://api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => 'nothing to do']]],
    ])]);

    TenantContext::forget();

    (new RunAiDraftRequest($draftRequest->getKey()))->handle(app(OmniAssistant::class));

    expect(TenantContext::hasTenant())->toBeFalse();
});

test('one user cannot see or submit on another users requests', function () {
    $theirs = AiDraftRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);

    $other = User::factory()->create();
    $this->tenant->users()->attach($other, ['role' => 'member']);
    Sanctum::actingAs($other);

    $this->getJson("{$this->base}/ai/draft-requests")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
