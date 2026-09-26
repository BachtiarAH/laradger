<?php

use App\Jobs\RunAiAssistantTurn;
use App\Models\Account;
use App\Models\AiActionDraft;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Journal;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Omni\OmniAssistant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

/**
 * The job runs where there is no request: no tenant context and no
 * authenticated user. Both have to be rebuilt or every query fails closed.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = Tenant::factory()->create();
    $this->tenant->users()->attach($this->user, ['role' => 'owner']);

    config([
        'ai.default' => 'openai',
        'ai.providers.openai.api_key' => 'test-key',
        'ai.providers.openai.base_uri' => 'https://api.openai.com',
        'ai.providers.anthropic.api_key' => null,
        'ai.providers.openai_compatible.api_key' => null,
    ]);

    $this->conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();
    $this->message = $this->conversation->messages()->create([
        'user_id' => $this->user->id,
        'role' => AiMessage::ROLE_USER,
        'content' => 'I spent 4.50 on coffee',
    ]);

    $this->cash = Account::factory()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Cash', 'type' => 'asset',
        'code' => 'AS-0001', 'status' => 'active',
    ]);
    $this->coffee = Account::factory()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Coffee', 'type' => 'expense',
        'code' => 'EX-0001', 'status' => 'active',
    ]);

    // Start from a clean slate, the way a worker process would.
    TenantContext::forget();
    auth()->forgetGuards();
});

it('rebuilds the tenant context and the acting user', function () {
    $arguments = json_encode([
        'transaction_date' => '2026-08-17',
        'description' => 'Queued coffee',
        'lines' => [
            ['account_id' => $this->coffee->id, 'debit' => '4.50'],
            ['account_id' => $this->cash->id, 'credit' => '4.50'],
        ],
    ]);

    Http::fake([
        'https://api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'journal_create', 'arguments' => $arguments],
                    ]],
                ],
            ]],
        ]),
    ]);

    // No tenant context and no user before the job runs.
    expect(TenantContext::hasTenant())->toBeFalse()
        ->and(auth()->user())->toBeNull();

    (new RunAiAssistantTurn($this->conversation->id, $this->message->id))
        ->handle(app(OmniAssistant::class));

    // The system prompt had to read accounts, which is only possible with the
    // tenant context restored.
    Http::assertSent(fn ($request) => str_contains(
        (string) ($request->data()['messages'][0]['content'] ?? ''),
        $this->cash->id,
    ));

    $draft = AiActionDraft::withoutGlobalScopes()->firstOrFail();

    // The draft is attributed to the conversation owner, not to nobody.
    expect($draft->user_id)->toBe($this->user->id)
        ->and($draft->status)->toBe(AiActionDraft::STATUS_PENDING)
        ->and($this->conversation->refresh()->status)->toBe(AiConversation::STATUS_COMPLETED)
        // Proposed only: the job must not apply anything.
        ->and(Journal::withoutGlobalScopes()->count())->toBe(0);
});

it('marks the turn failed with a safe message when the provider is unreachable', function () {
    Http::fake(['https://api.openai.com/*' => Http::response([], 500)]);

    // The job re-throws so the failure is visible in failed_jobs, while the
    // conversation carries the message the user actually sees.
    expect(fn () => (new RunAiAssistantTurn($this->conversation->id, $this->message->id))
        ->handle(app(OmniAssistant::class)))
        ->toThrow(AiProviderException::class);

    $conversation = $this->conversation->refresh();

    expect($conversation->status)->toBe(AiConversation::STATUS_FAILED)
        ->and($conversation->error)->toBeString()
        // Never the raw provider payload or the endpoint it tried.
        ->and($conversation->error)->not->toContain('api.openai.com')
        ->and($conversation->error)->not->toContain('test-key')
        ->and(AiActionDraft::withoutGlobalScopes()->count())->toBe(0);
});

it('clears the tenant context afterwards so it cannot leak into the next job', function () {
    Http::fake(['https://api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => 'nothing to do']]],
    ])]);

    (new RunAiAssistantTurn($this->conversation->id, $this->message->id))
        ->handle(app(OmniAssistant::class));

    expect(TenantContext::hasTenant())->toBeFalse();
});

it('does nothing when the conversation was deleted before the job ran', function () {
    $this->conversation->forceDelete();

    (new RunAiAssistantTurn($this->conversation->id, $this->message->id))
        ->handle(app(OmniAssistant::class));

    expect(AiActionDraft::withoutGlobalScopes()->count())->toBe(0);
});
