<?php

use App\Models\Account;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\Gateway\AiProviderConfigResolver;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

describe('openai compatible settings', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->tenant = createTenantForUser($this->user);
        Sanctum::actingAs($this->user);

        config(['ai.default' => 'openai']);
        config(['ai.providers.openai.api_key' => null]);
        config(['ai.providers.anthropic.api_key' => null]);
        config([
            'ai.providers.openai_compatible.api_key' => null,
            'ai.providers.openai_compatible.base_uri' => null,
            'ai.providers.openai_compatible.model' => null,
            'ai.providers.openai_compatible.endpoint' => '/v1/chat/completions',
        ]);
    });

    test('stores a base url and endpoint without an environment variable', function () {
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai_compatible',
            'model' => 'llama3.1',
            'api_key' => 'ollama',
            'base_uri' => 'http://localhost:11434',
            'endpoint' => '/v1/chat/completions',
        ])->assertOk()
            ->assertJsonPath('data.provider', 'openai_compatible')
            ->assertJsonPath('data.base_uri', 'http://localhost:11434')
            ->assertJsonPath('data.endpoint', '/v1/chat/completions')
            ->assertJsonPath('data.source', 'user');

        expect($this->user->refresh())
            ->ai_provider->toBe('openai_compatible')
            ->ai_base_uri->toBe('http://localhost:11434')
            ->ai_endpoint->toBe('/v1/chat/completions');
    });

    test('rejects a base url that is not http or https', function (string $url) {
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai_compatible',
            'base_uri' => $url,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['base_uri']);
    })->with([
        'file scheme' => ['file:///etc/passwd'],
        'gopher scheme' => ['gopher://example.com'],
        'no scheme' => ['localhost:11434'],
        'scheme-relative' => ['//evil.example.com'],
    ]);

    test('rejects an endpoint that is not a plain path', function (string $endpoint) {
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai_compatible',
            'endpoint' => $endpoint,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint']);
    })->with([
        'absolute url' => ['https://evil.example.com/steal'],
        'no leading slash' => ['v1/chat/completions'],
    ]);

    test('an endpoint can never redirect the request to another host', function () {
        // The HTTP client joins base and endpoint textually with a leading-slash
        // trim, so a protocol-relative endpoint stays a path on the same host.
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $this->postJson('/api/v1/me/ai/test', [
            'provider' => 'openai_compatible',
            'api_key' => 'ollama',
            'base_uri' => 'http://localhost:11434',
            'endpoint' => '//evil.example.com/chat',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'http://localhost:11434/evil.example.com/chat');
    });

    test('a blank base url is ignored rather than clearing the stored one', function () {
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai_compatible',
            'api_key' => 'ollama',
            'base_uri' => 'http://localhost:11434',
        ])->assertOk();

        $this->putJson('/api/v1/me/ai', ['model' => 'llama3.1'])->assertOk()
            ->assertJsonPath('data.base_uri', 'http://localhost:11434');

        expect($this->user->refresh())
            ->ai_base_uri->toBe('http://localhost:11434')
            ->ai_model->toBe('llama3.1');
    });

    test('clearing removes the stored base url and endpoint', function () {
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai_compatible',
            'api_key' => 'ollama',
            'base_uri' => 'http://localhost:11434',
            'endpoint' => '/v1/chat/completions',
        ])->assertOk();

        // Clearing also drops the provider selection, so the response falls back
        // to the environment default rather than reporting the cleared values.
        $this->deleteJson('/api/v1/me/ai')->assertOk()
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.source', 'environment');

        expect($this->user->refresh())
            ->ai_provider->toBeNull()
            ->ai_base_uri->toBeNull()
            ->ai_endpoint->toBeNull()
            ->ai_api_key->toBeNull();
    });

    test('clearing falls back to the environment base url', function () {
        config([
            'ai.default' => 'openai_compatible',
            'ai.providers.openai_compatible.base_uri' => 'https://gateway.internal/v1',
            'ai.providers.openai_compatible.endpoint' => '/chat/completions',
        ]);

        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai_compatible',
            'api_key' => 'ollama',
            'base_uri' => 'http://localhost:11434',
        ])->assertOk()
            ->assertJsonPath('data.base_uri', 'http://localhost:11434');

        $this->deleteJson('/api/v1/me/ai')->assertOk()
            ->assertJsonPath('data.base_uri', 'https://gateway.internal/v1')
            ->assertJsonPath('data.endpoint', '/chat/completions');
    });

    test('a stored endpoint only applies to the provider the user selected', function () {
        $this->user->forceFill([
            'ai_provider' => 'openai_compatible',
            'ai_base_uri' => 'http://localhost:11434',
        ])->save();

        config(['ai.providers.openai.base_uri' => 'https://api.openai.com']);

        $resolver = app(AiProviderConfigResolver::class);

        expect($resolver->resolve('openai_compatible', $this->user)['base_uri'])
            ->toBe('http://localhost:11434')
            ->and($resolver->resolve('openai', $this->user)['base_uri'])
            ->toBe('https://api.openai.com');
    });
});

describe('openai compatible connection test', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        config(['ai.default' => 'openai']);
        config(['ai.providers.openai.api_key' => null]);
        config([
            'ai.providers.openai_compatible.api_key' => null,
            'ai.providers.openai_compatible.base_uri' => null,
            'ai.providers.openai_compatible.endpoint' => '/v1/chat/completions',
        ]);
    });

    test('calls the configured base url and endpoint', function () {
        Http::fake([
            'localhost:11434/*' => Http::response([
                'choices' => [['message' => ['content' => 'ok']]],
            ]),
        ]);

        $this->postJson('/api/v1/me/ai/test', [
            'provider' => 'openai_compatible',
            'api_key' => 'ollama',
            'model' => 'llama3.1',
            'base_uri' => 'http://localhost:11434',
            'endpoint' => '/v1/chat/completions',
        ])->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.model', 'llama3.1')
            ->assertJsonPath('data.base_uri', 'http://localhost:11434');

        Http::assertSent(fn ($request) => $request->url() === 'http://localhost:11434/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer ollama')
            && $request->data()['model'] === 'llama3.1');
    });

    test('joins a base url that already carries a path prefix', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'ok']]],
            ]),
        ]);

        $this->postJson('/api/v1/me/ai/test', [
            'provider' => 'openai_compatible',
            'api_key' => 'gemini-key',
            'base_uri' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'endpoint' => '/chat/completions',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions');
    });

    test('fails with an actionable message when no base url is set', function () {
        $this->postJson('/api/v1/me/ai/test', [
            'provider' => 'openai_compatible',
            'api_key' => 'ollama',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['api_key'])
            ->assertJsonPath('errors.api_key.0', 'Set a base URL first, for example http://localhost:11434 for Ollama.');
    });

    test('does not persist transient endpoint values', function () {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $this->postJson('/api/v1/me/ai/test', [
            'provider' => 'openai_compatible',
            'api_key' => 'ollama',
            'base_uri' => 'http://localhost:11434',
        ])->assertOk();

        expect($this->user->refresh())
            ->ai_api_key->toBeNull()
            ->ai_base_uri->toBeNull();
    });
});

test('the ai draft endpoint works through a user configured openai compatible provider', function () {
    $user = User::factory()->create();
    $tenant = createTenantForUser($user);
    Sanctum::actingAs($user);

    config(['ai.default' => 'openai']);
    config(['ai.providers.openai.api_key' => null]);
    config(['ai.providers.anthropic.api_key' => null]);
    config(['ai.providers.openai_compatible.api_key' => null]);
    config(['ai.providers.openai_compatible.base_uri' => null]);

    Account::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Cash', 'type' => 'asset']);
    Account::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Coffee', 'type' => 'expense']);

    $user->forceFill([
        'ai_provider' => 'openai_compatible',
        'ai_model' => 'llama3.1',
        'ai_api_key' => 'ollama',
        'ai_api_key_hint' => '...lama',
        'ai_base_uri' => 'http://localhost:11434',
        'ai_endpoint' => '/v1/chat/completions',
    ])->save();

    Http::fake([
        'localhost:11434/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'draft' => [
                            'transaction_date' => '2026-08-17',
                            'description' => 'Coffee purchase',
                            'lines' => [
                                ['account_name' => 'Coffee', 'account_type' => 'expense', 'debit' => '10.00', 'credit' => null],
                                ['account_name' => 'Cash', 'account_type' => 'asset', 'debit' => null, 'credit' => '10.00'],
                            ],
                            'tags' => ['coffee'],
                        ],
                    ]),
                ],
            ]],
        ]),
    ]);

    $this->postJson("/api/v1/{$tenant->slug}/journals/ai-draft", [
        'statement' => 'Spent $10 on coffee',
    ])->assertOk()
        ->assertJsonPath('data.description', 'Coffee purchase');

    Http::assertSent(fn ($request) => $request->url() === 'http://localhost:11434/v1/chat/completions'
        && $request->data()['model'] === 'llama3.1');
});

test('the compatible provider constant is the only one with a custom base url', function () {
    expect(AiSettingsService::COMPATIBLE_PROVIDER)->toBe('openai_compatible');
});
