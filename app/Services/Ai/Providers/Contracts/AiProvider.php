<?php

namespace App\Services\Ai\Providers\Contracts;

use App\Services\Ai\Providers\ProviderResponse;

interface AiProvider
{
    public static function name(): string;

    /**
     * The provider is unavailable (missing configuration, unreachable, etc.).
     */
    public static function isConfigured(): bool;

    /**
     * Send a chat completion and return the provider's textual response.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): ProviderResponse;
}
