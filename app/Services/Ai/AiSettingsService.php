<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Ai\Gateway\AiProviderConfigResolver;
use Illuminate\Support\Facades\Log;

class AiSettingsService
{
    /**
     * The only provider whose base URL and endpoint path are user-supplied.
     * Every other provider has a fixed public endpoint.
     */
    public const COMPATIBLE_PROVIDER = 'openai_compatible';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiProviderConfigResolver $configs,
    ) {}

    /**
     * The user's AI settings, masked for display, together with every
     * registered provider so the UI can render a selector without hardcoding
     * provider names.
     *
     * @return array<string, mixed>
     */
    public function settingsFor(User $user): array
    {
        $selected = $user->aiProviderName() ?? (string) config('ai.default', 'openai');
        $resolved = $this->configs->resolve($selected, $user);

        $providers = collect(AiGateway::providerNames())
            ->map(function (string $name) use ($user): array {
                $fromUser = $user->aiProviderName() === $name && $user->hasAiApiKey();

                return [
                    'name' => $name,
                    'label' => (string) config("ai.labels.{$name}", $name),
                    'default_model' => $this->filledOrNull(config("ai.providers.{$name}.model")),
                    'configured' => $fromUser || filled(config("ai.providers.{$name}.api_key")),
                    'source' => $fromUser ? 'user' : 'environment',
                ];
            })
            ->all();

        $fromEnvironment = ! $user->hasAiApiKey();

        return [
            'provider' => $selected,
            'model' => $this->filledOrNull($resolved['model'] ?? null),
            // The effective endpoint, not the stored one, so the UI always shows
            // what will actually be called. Neither value is a secret.
            'base_uri' => $this->filledOrNull($resolved['base_uri'] ?? null),
            'endpoint' => $this->filledOrNull($resolved['endpoint'] ?? null),
            'has_key' => ! $fromEnvironment || filled(config("ai.providers.{$selected}.api_key")),
            // Only ever the user's own key. A server-configured key is reported
            // as present but never partially disclosed.
            'key_hint' => $user->ai_api_key_hint,
            'source' => $fromEnvironment ? 'environment' : 'user',
            'providers' => $providers,
        ];
    }

    /**
     * Persist the user's provider selection. A blank value is ignored rather
     * than treated as a clear, so saving the model alone never wipes a key or
     * endpoint the user cannot see. Clearing is an explicit DELETE.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): void
    {
        $attributes = [];

        if (array_key_exists('provider', $data)) {
            $attributes['ai_provider'] = $data['provider'];
        }

        if (array_key_exists('model', $data)) {
            $attributes['ai_model'] = $data['model'];
        }

        if (filled($data['api_key'] ?? null)) {
            $attributes['ai_api_key'] = $data['api_key'];
            $attributes['ai_api_key_hint'] = AiProviderConfigResolver::hintFor($data['api_key']);
        }

        foreach (['base_uri', 'endpoint'] as $field) {
            if (filled($data[$field] ?? null)) {
                $attributes['ai_'.$field] = $data[$field];
            }
        }

        $user->forceFill($attributes)->save();
    }

    /**
     * Drop the stored key and provider selection, returning the user to the
     * environment configuration.
     */
    public function clear(User $user): void
    {
        $user->forceFill([
            'ai_provider' => null,
            'ai_model' => null,
            'ai_api_key' => null,
            'ai_api_key_hint' => null,
            'ai_base_uri' => null,
            'ai_endpoint' => null,
        ])->save();
    }

    /**
     * Verify credentials against the provider before they are stored.
     *
     * Transient values in $overrides are used for the call but never saved, so
     * a user can paste a key, test it, and only then commit it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function test(User $user, array $overrides = []): array
    {
        $name = (string) ($overrides['provider'] ?? $this->configs->defaultFor($user));
        $config = $this->configs->resolve($name, $user);

        foreach (['api_key', 'model', 'base_uri', 'endpoint'] as $key) {
            if (filled($overrides[$key] ?? null)) {
                $config[$key] = $overrides[$key];
            }
        }

        // Without a base URL the HTTP client has nothing to resolve the
        // endpoint against, which would surface as a confusing connection
        // error. Only the compatible provider depends on a custom one.
        if ($name === self::COMPATIBLE_PROVIDER && blank($config['base_uri'] ?? null)) {
            throw AiProviderException::unavailable(
                'Set a base URL first, for example http://localhost:11434 for Ollama.'
            );
        }

        $provider = $this->gateway->providerFor($name, $config);

        if (! $provider->isConfigured()) {
            throw AiProviderException::unavailable('No API key was provided for this provider.');
        }

        $start = hrtime(true);

        try {
            $provider->chat([
                ['role' => 'user', 'content' => 'Reply with the single word: ok'],
            ]);
        } catch (AiProviderException $e) {
            Log::warning('An AI provider connection test failed.', [
                'provider' => $name,
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);

            throw AiProviderException::unavailable('The provider rejected the connection test.');
        }

        return [
            'ok' => true,
            'provider' => $name,
            'model' => $this->filledOrNull($config['model'] ?? null),
            'base_uri' => $this->filledOrNull($config['base_uri'] ?? null),
            'latency_ms' => (int) round((hrtime(true) - $start) / 1_000_000),
        ];
    }

    /**
     * Environment values arrive as empty strings when a key is present but
     * blank, which is not the same as a usable value.
     */
    private function filledOrNull(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }
}
