<?php

namespace App\Services\Ai\Providers\Contracts;

use App\Services\Ai\Providers\ProviderResponse;
use App\Services\Ai\Tools\ToolCall;

interface AiProvider
{
    public static function name(): string;

    /**
     * The provider is unavailable (missing configuration, unreachable, etc.).
     *
     * Resolved from the instance configuration rather than the config
     * repository so a per-user API key stored in-app is honoured.
     */
    public function isConfigured(): bool;

    /**
     * Whether the adapter can translate the normalized tool-calling message
     * format onto its own wire format.
     *
     * The assistant refuses to run against a provider that returns false rather
     * than silently degrading to unstructured text, because a model that cannot
     * call tools cannot propose actions.
     */
    public function supportsTools(): bool;

    /**
     * Send a chat completion and return the provider's textual response.
     *
     * Supported `$options` keys:
     *  - `structured`: bool, ask for a JSON object response
     *  - `tools`: array<int, array{name: string, description: string, parameters: array}>,
     *    tool definitions to expose. `tool_choice` may narrow this.
     *
     * `$messages` entries are `{role, content, tool_calls?, tool_call_id?}` where
     * `tool_calls` is a list of `ToolCall`. Providers own the translation to and
     * from their wire format; nothing above this layer should know those shapes.
     *
     * @param  array<int, array{role: string, content: string, tool_calls?: array<int, ToolCall>, tool_call_id?: string|null}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): ProviderResponse;
}
