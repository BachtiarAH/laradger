<?php

namespace App\Services\Ai\Providers\Concerns;

use App\Services\Ai\Tools\ToolCall;
use Illuminate\Support\Str;

/**
 * Message translation for the OpenAI chat-completions wire format, shared by
 * the OpenAI provider and any OpenAI-compatible endpoint.
 */
trait MapsOpenAiMessages
{
    /**
     * @param  array<int, array{role: string, content: string, tool_calls?: array<int, ToolCall>, tool_call_id?: string|null}>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected function wireMessages(array $messages): array
    {
        return array_values(array_map(function (array $message): array {
            $role = $message['role'];
            $content = (string) ($message['content'] ?? '');
            $toolCalls = (array) ($message['tool_calls'] ?? []);

            if ($role === 'tool') {
                return [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($message['tool_call_id'] ?? ''),
                    'content' => $content,
                ];
            }

            if ($toolCalls === []) {
                return [
                    'role' => $role,
                    'content' => $content,
                ];
            }

            return [
                'role' => $role,
                // The API rejects a null content on a tool-calling turn only if
                // it is an empty string, so send null when there is no prose.
                'content' => $content === '' ? null : $content,
                'tool_calls' => array_map(static fn (ToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->name,
                        'arguments' => json_encode($call->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ],
                ], $toolCalls),
            ];
        }, array_filter(
            $messages,
            // A leading tool result with no preceding call is meaningless to the
            // API; drop it rather than let the provider 400.
            static fn (array $message): bool => $message['role'] !== 'tool'
                || filled($message['tool_call_id'] ?? null),
        )));
    }

    /**
     * A tool_choice value forcing the model to pick one of the given tools.
     *
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    protected function requiredToolChoice(array $names): array
    {
        return ['type' => 'function', 'function' => ['name' => (string) Str::first($names)]];
    }
}
