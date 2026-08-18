<?php

use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use App\Services\Ai\AiCallRecord;
use App\Services\Ai\Contracts\AiCallRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant', $this->tenant->slug);

    config(['ai.default' => 'openai']);
    config(['ai.providers.openai.api_key' => 'test-key']);
    config(['ai.providers.openai.base_uri' => 'https://api.openai.com']);
    config(['ai.providers.anthropic.api_key' => null]);
    config(['ai.providers.openai_compatible.api_key' => null]);
    config(['ai.recording.enabled' => true]);
    config(['ai.recording.driver' => 'file']);
    config(['ai.recording.file.path' => 'logs/ai-calls-test.jsonl']);

    $this->logPath = storage_path('logs/ai-calls-test.jsonl');

    if (file_exists($this->logPath)) {
        unlink($this->logPath);
    }
});

function fakeOpenAiDraftResponse(array $draft, array $usage = []): array
{
    return [
        'choices' => [
            ['message' => ['content' => json_encode(['draft' => $draft])]],
        ],
        'usage' => $usage ?: [
            'prompt_tokens' => 120,
            'completion_tokens' => 45,
            'total_tokens' => 165,
        ],
    ];
}

function readJsonlLines(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }

    return collect(explode("\n", trim((string) file_get_contents($path))))
        ->filter()
        ->map(fn (string $line) => json_decode($line, true))
        ->all();
}

test('records the ai call with prompt, draft, usage, and latency', function () {
    Http::fake([
        'https://api.openai.com/*' => Http::response(fakeOpenAiDraftResponse([
            'transaction_date' => '2026-08-17',
            'description' => 'Groceries purchase',
            'lines' => [
                ['account_name' => 'Groceries', 'account_type' => 'expense', 'debit' => '45.50', 'credit' => null],
                ['account_name' => 'Cash', 'account_type' => 'asset', 'debit' => null, 'credit' => '45.50'],
            ],
            'tags' => ['groceries'],
        ])),
    ]);

    $response = $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $45.50 on groceries with cash',
    ]);

    $response->assertOk();
    $recordId = $response->json('data.record_id');
    expect($recordId)->toBeString();

    $lines = readJsonlLines($this->logPath);
    expect($lines)->toHaveCount(1);

    $record = $lines[0];
    expect($record['id'])->toBe($recordId)
        ->and($record['type'])->toBe('ai_call')
        ->and($record['provider'])->toBe('openai')
        ->and($record['model'])->toBe('gpt-4o-mini')
        ->and($record['user_id'])->toBe($this->user->id)
        ->and($record['tenant_id'])->toBe($this->tenant->id)
        ->and($record['statement'])->toBe('Spent $45.50 on groceries with cash')
        ->and($record['prompt'])->toContain('double-entry bookkeeping assistant')
        ->and($record['prompt'])->toContain('Spent $45.50 on groceries with cash')
        ->and($record['draft']['description'])->toBe('Groceries purchase')
        ->and($record['usage'])->toBe([
            'prompt_tokens' => 120,
            'completion_tokens' => 45,
            'total_tokens' => 165,
        ])
        ->and($record['success'])->toBeTrue()
        ->and($record['latency_ms'])->toBeInt()
        ->and($record['error'])->toBeNull();
});

test('records the failed ai call with an error message', function () {
    Http::fake([
        'https://api.openai.com/*' => Http::response([], 500),
    ]);

    $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $10 on coffee',
    ])->assertStatus(502);

    $lines = readJsonlLines($this->logPath);
    expect($lines)->toHaveCount(1);

    $record = $lines[0];
    expect($record['type'])->toBe('ai_call')
        ->and($record['success'])->toBeFalse()
        ->and($record['error'])->toBeString();
});

test('does not record when recording is disabled', function () {
    config(['ai.recording.enabled' => false]);

    Http::fake([
        'https://api.openai.com/*' => Http::response(fakeOpenAiDraftResponse([
            'transaction_date' => '2026-08-17',
            'description' => 'Groceries purchase',
            'lines' => [
                ['account_name' => 'Groceries', 'account_type' => 'expense', 'debit' => '45.50', 'credit' => null],
                ['account_name' => 'Cash', 'account_type' => 'asset', 'debit' => null, 'credit' => '45.50'],
            ],
            'tags' => ['groceries'],
        ])),
    ]);

    $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $45.50 on groceries with cash',
    ])->assertOk();

    expect(readJsonlLines($this->logPath))->toBeEmpty();
});

test('uses the custom system prompt when configured', function () {
    config(['ai.prompt' => 'You are a bookkeeper. Accounts:\n:accounts\nStatement:\n:statement\nReturn JSON.']);

    Http::fake([
        'https://api.openai.com/*' => Http::response(fakeOpenAiDraftResponse([
            'transaction_date' => '2026-08-17',
            'description' => 'Coffee',
            'lines' => [
                ['account_name' => 'Cash', 'account_type' => 'asset', 'debit' => null, 'credit' => '10.00'],
                ['account_name' => 'Coffee', 'account_type' => 'expense', 'debit' => '10.00', 'credit' => null],
            ],
            'tags' => [],
        ])),
    ]);

    $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $10 on coffee',
    ])->assertOk();

    $lines = readJsonlLines($this->logPath);
    $record = $lines[0];
    expect($record['prompt'])->toContain('You are a bookkeeper.')
        ->and($record['prompt'])->toContain('Statement:')
        ->and($record['prompt'])->toContain('Spent $10 on coffee')
        ->and($record['prompt'])->not->toContain('double-entry bookkeeping assistant');
});

test('records a confirmation when a journal is created with ai_record_id', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Cash', 'type' => 'asset']);
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Groceries', 'type' => 'expense']);

    Http::fake([
        'https://api.openai.com/*' => Http::response(fakeOpenAiDraftResponse([
            'transaction_date' => '2026-08-17',
            'description' => 'Groceries purchase',
            'lines' => [
                ['account_name' => 'Groceries', 'account_type' => 'expense', 'debit' => '45.50', 'credit' => null],
                ['account_name' => 'Cash', 'account_type' => 'asset', 'debit' => null, 'credit' => '45.50'],
            ],
            'tags' => ['groceries'],
        ])),
    ]);

    $recordId = $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $45.50 on groceries with cash',
    ])->json('data.record_id');

    $cash = Account::where('name', 'Cash')->first();
    $groceries = Account::where('name', 'Groceries')->first();

    $this->postJson('/api/v1/journals', [
        'transaction_date' => '2026-08-17',
        'description' => 'Groceries purchase',
        'status' => 'draft',
        'source' => 'manual',
        'ai_record_id' => $recordId,
        'lines' => [
            ['account_id' => $groceries->id, 'debit' => '45.50'],
            ['account_id' => $cash->id, 'credit' => '45.50'],
        ],
    ])->assertCreated();

    $lines = readJsonlLines($this->logPath);
    expect($lines)->toHaveCount(2);

    $confirmation = $lines[1];
    $journal = Journal::latest('created_at')->first();

    expect($confirmation['type'])->toBe('confirmation')
        ->and($confirmation['id'])->toBe($recordId)
        ->and($confirmation['journal_id'])->toBe($journal->id)
        ->and($confirmation['tenant_id'])->toBe($this->tenant->id);
});

test('the recording driver can be swapped via service injection', function () {
    $fakeRecorder = new class implements AiCallRecorder
    {
        public array $records = [];

        public function record(AiCallRecord $record): void
        {
            $this->records[] = $record;
        }

        public function confirm(string $recordId, Journal $journal): void
        {
            $this->records[] = $recordId;
        }
    };

    $this->app->instance(AiCallRecorder::class, $fakeRecorder);

    Http::fake([
        'https://api.openai.com/*' => Http::response(fakeOpenAiDraftResponse([
            'transaction_date' => '2026-08-17',
            'description' => 'Groceries purchase',
            'lines' => [
                ['account_name' => 'Groceries', 'account_type' => 'expense', 'debit' => '45.50', 'credit' => null],
                ['account_name' => 'Cash', 'account_type' => 'asset', 'debit' => null, 'credit' => '45.50'],
            ],
            'tags' => ['groceries'],
        ])),
    ]);

    $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $45.50 on groceries with cash',
    ])->assertOk();

    expect($fakeRecorder->records)->toHaveCount(1)
        ->and($fakeRecorder->records[0])->toBeInstanceOf(AiCallRecord::class)
        ->and($fakeRecorder->records[0]->success)->toBeTrue();

    expect(readJsonlLines($this->logPath))->toBeEmpty();
});

test('uses the openai-compatible provider when configured as default', function () {
    config(['ai.default' => 'openai_compatible']);
    config(['ai.providers.openai_compatible.api_key' => 'compat-key']);
    config(['ai.providers.openai_compatible.base_uri' => 'https://custom-gateway.example.com']);
    config(['ai.providers.openai_compatible.model' => 'custom-model']);

    Http::fake([
        'https://custom-gateway.example.com/*' => Http::response(fakeOpenAiDraftResponse([
            'transaction_date' => '2026-08-17',
            'description' => 'Coffee purchase',
            'lines' => [
                ['account_name' => 'Coffee', 'account_type' => 'expense', 'debit' => '10.00', 'credit' => null],
                ['account_name' => 'Cash', 'account_type' => 'asset', 'debit' => null, 'credit' => '10.00'],
            ],
            'tags' => ['coffee'],
        ])),
    ]);

    $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $10 on coffee',
    ])->assertOk()
        ->assertJsonPath('data.description', 'Coffee purchase');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'custom-gateway.example.com');
    });

    $lines = readJsonlLines($this->logPath);
    expect($lines[0]['provider'])->toBe('openai_compatible')
        ->and($lines[0]['model'])->toBe('custom-model');
});

test('uses a custom endpoint for the openai-compatible provider', function () {
    config(['ai.default' => 'openai_compatible']);
    config(['ai.providers.openai_compatible.api_key' => 'gemini-key']);
    config(['ai.providers.openai_compatible.base_uri' => 'https://generativelanguage.googleapis.com/v1beta/openai']);
    config(['ai.providers.openai_compatible.model' => 'gemini-3.7-flash']);
    config(['ai.providers.openai_compatible.endpoint' => '/chat/completions']);

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response(fakeOpenAiDraftResponse([
            'transaction_date' => '2026-08-17',
            'description' => 'Coffee purchase',
            'lines' => [
                ['account_name' => 'Coffee', 'account_type' => 'expense', 'debit' => '10.00', 'credit' => null],
                ['account_name' => 'Cash', 'account_type' => 'asset', 'debit' => null, 'credit' => '10.00'],
            ],
            'tags' => ['coffee'],
        ])),
    ]);

    $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $10 on coffee',
    ])->assertOk()
        ->assertJsonPath('data.description', 'Coffee purchase');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';
    });
});

test('records the raw response when the provider returns an error', function () {
    Http::fake([
        'https://api.openai.com/*' => Http::response([
            'error' => [
                'message' => 'Invalid API key',
            ],
        ], 401),
    ]);

    $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $10 on coffee',
    ])->assertStatus(502);

    $lines = readJsonlLines($this->logPath);
    expect($lines[0]['success'])->toBeFalse()
        ->and($lines[0]['error'])->toBe('The AI provider returned an error.')
        ->and($lines[0]['raw_response']['error']['message'])->toBe('Invalid API key');
});

test('logs the error and raw response when the provider fails', function () {
    Log::spy();

    Http::fake([
        'https://api.openai.com/*' => Http::response([
            'error' => [
                'message' => 'Invalid API key',
            ],
        ], 401),
    ]);

    $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $10 on coffee',
    ])->assertStatus(502);

    Log::shouldHaveReceived('error')
        ->once()
        ->with(
            'AI provider returned an error.',
            Mockery::on(function ($context) {
                return $context['provider'] === 'openai'
                    && $context['prompt'] !== ''
                    && $context['raw_response']['error']['message'] === 'Invalid API key';
            })
        );
});
