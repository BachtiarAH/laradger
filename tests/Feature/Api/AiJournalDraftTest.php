<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

describe('ai draft generation', function () {
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
    });

    test('returns a draft journal generated from the statement', function () {
        Account::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Cash', 'type' => 'asset']);
        Account::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Groceries', 'type' => 'expense']);

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'draft' => [
                                    'transaction_date' => '2026-08-17',
                                    'description' => 'Groceries purchase',
                                    'lines' => [
                                        ['account_name' => 'Groceries', 'account_type' => 'expense', 'debit' => '45.50', 'credit' => null, 'description' => 'Groceries'],
                                        ['account_name' => 'Cash', 'account_type' => 'asset', 'debit' => null, 'credit' => '45.50', 'description' => 'Paid with cash'],
                                    ],
                                    'tags' => ['groceries', 'cash'],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->postJson('/api/v1/journals/ai-draft', [
            'statement' => 'Spent $45.50 on groceries at the supermarket with cash',
        ])->assertOk()
            ->assertJsonPath('data.transaction_date', '2026-08-17')
            ->assertJsonPath('data.description', 'Groceries purchase')
            ->assertJsonPath('data.lines.0.account_name', 'Groceries')
            ->assertJsonPath('data.lines.0.debit', '45.50')
            ->assertJsonPath('data.lines.1.account_name', 'Cash')
            ->assertJsonPath('data.lines.1.credit', '45.50')
            ->assertJsonPath('data.tags', ['groceries', 'cash']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.openai.com')
                && $request->data()['messages'][0]['content'] !== '';
        });
    });

    test('statement is required', function () {
        $this->postJson('/api/v1/journals/ai-draft', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['statement']);
    });

    test('returns 502 when the provider is not configured', function () {
        config(['ai.providers.openai.api_key' => null]);

        $this->postJson('/api/v1/journals/ai-draft', [
            'statement' => 'Spent $10 on coffee',
        ])->assertStatus(502)
            ->assertJsonValidationErrors(['statement']);
    });

    test('returns 502 when the provider returns an invalid draft', function () {
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'draft' => [
                                    'description' => 'No lines',
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->postJson('/api/v1/journals/ai-draft', [
            'statement' => 'Spent $10 on coffee',
        ])->assertStatus(502)
            ->assertJsonValidationErrors(['statement']);
    });

    test('returns 502 when the provider is unreachable', function () {
        Http::fake([
            'https://api.openai.com/*' => Http::response([], 500),
        ]);

        $this->postJson('/api/v1/journals/ai-draft', [
            'statement' => 'Spent $10 on coffee',
        ])->assertStatus(502)
            ->assertJsonValidationErrors(['statement']);
    });

    test('falls back to the anthropic provider when openai fails', function () {
        config(['ai.providers.anthropic.api_key' => 'test-anthropic-key']);

        Http::fake([
            'https://api.openai.com/*' => Http::response([], 500),
            'https://api.anthropic.com/*' => Http::response([
                'content' => [
                    ['text' => json_encode([
                        'draft' => [
                            'transaction_date' => '2026-08-17',
                            'description' => 'Coffee purchase',
                            'lines' => [
                                ['account_name' => 'Coffee', 'account_type' => 'expense', 'debit' => '10.00', 'credit' => null],
                                ['account_name' => 'Cash', 'account_type' => 'asset', 'debit' => null, 'credit' => '10.00'],
                            ],
                            'tags' => ['coffee'],
                        ],
                    ])],
                ],
            ]),
        ]);

        $this->postJson('/api/v1/journals/ai-draft', [
            'statement' => 'Spent $10 on coffee',
        ])->assertOk()
            ->assertJsonPath('data.description', 'Coffee purchase')
            ->assertJsonPath('data.lines.0.account_name', 'Coffee');
    });
});

test('guests cannot generate an ai draft', function () {
    $this->postJson('/api/v1/journals/ai-draft', [
        'statement' => 'Spent $10 on coffee',
    ])->assertUnauthorized();
});
