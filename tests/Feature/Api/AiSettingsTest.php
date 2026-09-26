<?php

use App\Models\User;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Ai\Gateway\AiProviderConfigResolver;
use Laravel\Sanctum\Sanctum;

describe('ai provider settings', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        config(['ai.default' => 'openai']);
        config(['ai.providers.openai.api_key' => null]);
        config(['ai.providers.openai.model' => 'gpt-4o-mini']);
        config(['ai.providers.anthropic.api_key' => null]);
        config(['ai.providers.openai_compatible.api_key' => null]);
    });

    test('returns the environment defaults when nothing is stored', function () {
        $this->getJson('/api/v1/me/ai')
            ->assertOk()
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.model', 'gpt-4o-mini')
            ->assertJsonPath('data.has_key', false)
            ->assertJsonPath('data.source', 'environment')
            ->assertJsonPath('data.providers.0.name', 'openai')
            ->assertJsonPath('data.providers.0.configured', false);
    });

    test('stores a provider, model and api key', function () {
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'anthropic',
            'model' => 'claude-3-5-haiku-20241022',
            'api_key' => 'sk-ant-test-key-1234',
        ])->assertOk()
            ->assertJsonPath('data.provider', 'anthropic')
            ->assertJsonPath('data.model', 'claude-3-5-haiku-20241022')
            ->assertJsonPath('data.has_key', true)
            ->assertJsonPath('data.key_hint', '...1234')
            ->assertJsonPath('data.source', 'user');

        expect($this->user->refresh())
            ->ai_provider->toBe('anthropic')
            ->ai_model->toBe('claude-3-5-haiku-20241022')
            ->and($this->user->ai_api_key)->toBe('sk-ant-test-key-1234');
    });

    test('never returns the stored api key', function () {
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai',
            'api_key' => 'sk-super-secret-value',
        ])->assertOk()
            ->assertJsonMissing(['api_key' => 'sk-super-secret-value'])
            ->assertJsonMissingPath('data.api_key');

        $this->getJson('/api/v1/me/ai')
            ->assertOk()
            ->assertJsonMissing(['api_key' => 'sk-super-secret-value'])
            ->assertJsonMissingPath('data.api_key');
    });

    test('the stored key is encrypted at rest', function () {
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai',
            'api_key' => 'sk-plaintext-should-not-appear',
        ])->assertOk();

        $raw = DB::table('users')->where('id', $this->user->id)->value('ai_api_key');

        expect($raw)->toBeString()
            ->and($raw)->not->toContain('sk-plaintext-should-not-appear');
    });

    test('a blank api key is ignored rather than clearing the stored one', function () {
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai',
            'api_key' => 'sk-keep-this-key',
        ])->assertOk();

        $this->putJson('/api/v1/me/ai', ['model' => 'gpt-4o'])->assertOk();

        expect($this->user->refresh())->ai_api_key->toBe('sk-keep-this-key')
            ->and($this->user->ai_model)->toBe('gpt-4o');
    });

    test('clearing removes the key and returns to the environment', function () {
        config(['ai.providers.openai.api_key' => 'env-key-abcd']);

        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai',
            'api_key' => 'sk-user-key',
        ])->assertOk()->assertJsonPath('data.source', 'user');

        $this->deleteJson('/api/v1/me/ai')
            ->assertOk()
            ->assertJsonPath('data.source', 'environment')
            ->assertJsonPath('data.has_key', true);

        expect($this->user->refresh())
            ->ai_provider->toBeNull()
            ->ai_model->toBeNull()
            ->ai_api_key->toBeNull();
    });

    test('rejects an unknown provider', function () {
        $this->putJson('/api/v1/me/ai', ['provider' => 'skynet'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['provider']);
    });

    test('accepts a short arbitrary key, as local servers require', function () {
        // Ollama and LM Studio accept any non-empty string as a key.
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai_compatible',
            'api_key' => 'ollama',
        ])->assertOk()
            ->assertJsonPath('data.has_key', true)
            ->assertJsonPath('data.key_hint', '...lama');

        expect($this->user->refresh())->ai_api_key->toBe('ollama');
    });

    test('one user never sees another user key', function () {
        $other = User::factory()->create();
        $other->forceFill([
            'ai_provider' => 'openai',
            'ai_api_key' => 'sk-other-user-key',
            'ai_api_key_hint' => '...-key',
        ])->save();

        $this->getJson('/api/v1/me/ai')
            ->assertOk()
            ->assertJsonPath('data.source', 'environment')
            ->assertJsonMissing(['api_key' => 'sk-other-user-key']);
    });
});

test('guests cannot read or change ai settings', function () {
    $this->getJson('/api/v1/me/ai')->assertUnauthorized();
    $this->putJson('/api/v1/me/ai', ['api_key' => 'sk-nope'])->assertUnauthorized();
    $this->deleteJson('/api/v1/me/ai')->assertUnauthorized();
    $this->postJson('/api/v1/me/ai/test', ['api_key' => 'sk-nope'])->assertUnauthorized();
});

describe('ai connection test', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        config(['ai.default' => 'openai']);
        config(['ai.providers.openai.api_key' => null]);
        config(['ai.providers.openai.base_uri' => 'https://api.openai.com']);
    });

    test('verifies transient credentials without persisting them', function () {
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'ok']]],
            ]),
        ]);

        $this->postJson('/api/v1/me/ai/test', [
            'provider' => 'openai',
            'api_key' => 'sk-transient-key',
        ])->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.provider', 'openai');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-transient-key'));

        expect($this->user->refresh())->ai_api_key->toBeNull();
    });

    test('uses the stored key when no transient key is supplied', function () {
        $this->putJson('/api/v1/me/ai', [
            'provider' => 'openai',
            'api_key' => 'sk-stored-key',
        ])->assertOk();

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'ok']]],
            ]),
        ]);

        $this->postJson('/api/v1/me/ai/test')->assertOk()
            ->assertJsonPath('data.ok', true);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-stored-key'));
    });

    test('fails with 422 when no key is available at all', function () {
        $this->postJson('/api/v1/me/ai/test')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_key']);
    });

    test('never leaks the raw provider error when the key is rejected', function () {
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'error' => ['message' => 'Incorrect API key provided: sk-***'],
            ], 401),
        ]);

        $this->postJson('/api/v1/me/ai/test', ['api_key' => 'sk-bad-key'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_key'])
            ->assertJsonMissing(['Incorrect API key provided: sk-***']);
    });
});

test('the gateway routes to the key a user stored in-app', function () {
    $user = User::factory()->create();
    createTenantForUser($user);
    Sanctum::actingAs($user);

    config(['ai.default' => 'openai']);
    config(['ai.providers.openai.api_key' => null]);
    config(['ai.providers.openai.base_uri' => 'https://api.openai.com']);
    config(['ai.providers.openai.model' => 'gpt-4o-mini']);
    config(['ai.providers.anthropic.api_key' => null]);
    config(['ai.providers.openai_compatible.api_key' => null]);

    Http::fake([
        'https://api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ]),
    ]);

    // Without a stored key the provider is unavailable.
    expect(app(AiGateway::class)->driver('openai')->isConfigured())
        ->toBeFalse();

    $user->forceFill([
        'ai_provider' => 'openai',
        'ai_api_key' => 'sk-user-in-app-key',
        'ai_model' => 'gpt-4o',
        'ai_api_key_hint' => '...-key',
    ])->save();

    expect(app(AiProviderConfigResolver::class)->resolve('openai', $user))
        ->api_key->toBe('sk-user-in-app-key')
        ->model->toBe('gpt-4o');
});

test('a stored user key never leaks into another provider', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'ai_provider' => 'openai',
        'ai_api_key' => 'sk-user-in-app-key',
    ])->save();

    config(['ai.providers.anthropic.api_key' => 'env-anthropic-key']);

    expect(app(AiProviderConfigResolver::class)->resolve('anthropic', $user))
        ->api_key->toBe('env-anthropic-key');
});
