<?php

namespace App\Services\Ai\Gateway;

use App\Models\User;

class AiProviderConfigResolver
{
    /**
     * Resolve the effective configuration for a provider.
     *
     * A user's in-app settings only apply to the provider they selected, so
     * every other provider keeps its environment configuration and gateway
     * fallback keeps working. The environment remains the fallback whenever a
     * user has not stored a value.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $provider, ?User $user = null): array
    {
        $config = (array) config("ai.providers.{$provider}", []);

        if ($user === null || $user->aiProviderName() !== $provider) {
            return $config;
        }

        if ($user->hasAiApiKey()) {
            $config['api_key'] = $user->ai_api_key;
        }

        if (filled($user->ai_model)) {
            $config['model'] = $user->ai_model;
        }

        // Custom endpoint settings. Only the `openai_compatible` provider reads
        // these, but resolving them generically keeps a proxy or self-hosted
        // gateway reachable for any provider.
        if (filled($user->ai_base_uri)) {
            $config['base_uri'] = $user->ai_base_uri;
        }

        if (filled($user->ai_endpoint)) {
            $config['endpoint'] = $user->ai_endpoint;
        }

        return $config;
    }

    /**
     * The provider a request should be routed to: the user's own selection
     * when they made one, otherwise the environment default.
     */
    public function defaultFor(?User $user = null): string
    {
        $configured = (string) config('ai.default', 'openai');

        $selected = $user?->aiProviderName();

        return filled($selected) ? $selected : $configured;
    }

    /**
     * The last few characters of a key, safe to display in the UI. Nothing
     * else about the key ever leaves the server.
     */
    public static function hintFor(?string $apiKey): ?string
    {
        if (blank($apiKey)) {
            return null;
        }

        return '...'.substr($apiKey, -4);
    }
}
