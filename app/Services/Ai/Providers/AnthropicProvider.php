<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Tools\ToolCall;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

class AnthropicProvider extends AbstractAiProvider
{
    public static function name(): string
    {
        return 'anthropic';
    }

    public function supportsTools(): bool
    {
        return true;
    }

    /**
     * @param  array<int, array{role: string, content: string, tool_calls?: array<int, ToolCall>, tool_call_id?: string|null}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function requestPayload(array $messages, array $options = []): array
    {
        $system = collect($messages)
            ->where('role', 'system')
            ->pluck('content')
            ->implode("\n\n");

        $payload = [
            'model' => $this->config['model'] ?? 'claude-3-5-haiku-20241022',
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->config['max_tokens'] ?? 2048),
            // Anthropic carries conversation turns as content blocks and has no
            // `tool` role: a tool result is a user turn holding tool_result
            // blocks, so the normalized form has to be reshaped here.
            'messages' => $this->wireMessages($messages),
        ];

        if (filled($system)) {
            $payload['system'] = $system;
        }

        $tools = $this->requestedTools($options);

        if ($tools !== []) {
            $payload['tools'] = array_map(
                static fn (array $tool): array => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'input_schema' => $tool['parameters'],
                ],
                $tools,
            );

            if (($options['tool_choice_mode'] ?? null) === 'required') {
                $payload['tool_choice'] = ['type' => 'any'];
            } elseif (filled($options['tool_choice'] ?? null)) {
                $payload['tool_choice'] = ['type' => 'tool', 'name' => (string) $options['tool_choice']];
            }
        }

        return $payload;
    }

    /**
     * @param  array<int, array{role: string, content: string, tool_calls?: array<int, ToolCall>, tool_call_id?: string|null}>  $messages
     * @return array<int, array{role: string, content: array<int, array<string, mixed>>}>
     */
    private function wireMessages(array $messages): array
    {
        $turns = [];

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                continue;
            }

            if ($role === 'tool') {
                $turns[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => (string) ($message['tool_call_id'] ?? ''),
                        'content' => $content,
                    ]],
                ];

                continue;
            }

            $blocks = [];

            if ($content !== '') {
                $blocks[] = ['type' => 'text', 'text' => $content];
            }

            foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => $call->id,
                    'name' => $call->name,
                    'input' => (object) $call->arguments,
                ];
            }

            if ($blocks === []) {
                continue;
            }

            $turns[] = ['role' => $role, 'content' => $blocks];
        }

        return $this->mergeAdjacentUserTurns($turns);
    }

    /**
     * Anthropic rejects two consecutive turns with the same role, and a tool
     * result always lands in a user turn immediately after the assistant turn
     * that requested it — so a user turn followed by its own text has to be
     * folded together.
     *
     * @param  array<int, array{role: string, content: array<int, array<string, mixed>>}>  $turns
     * @return array<int, array{role: string, content: array<int, array<string, mixed>>}>
     */
    private function mergeAdjacentUserTurns(array $turns): array
    {
        $merged = [];

        foreach ($turns as $turn) {
            $previous = $merged[array_key_last($merged)] ?? null;

            if ($previous !== null && $previous['role'] === $turn['role'] && $turn['role'] === 'user') {
                $previous['content'] = array_merge($previous['content'], $turn['content']);
                $merged[array_key_last($merged)] = $previous;

                continue;
            }

            $merged[] = $turn;
        }

        return array_values($merged);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'x-api-key' => $this->config['api_key'],
            'anthropic-version' => $this->config['version'] ?? '2023-06-01',
        ];
    }

    protected function endpoint(): string
    {
        return '/v1/messages';
    }

    protected function extractContent(Response $response): string
    {
        $blocks = $response->json('content');

        if (! is_array($blocks)) {
            return '';
        }

        return $this->textBlocks($blocks);
    }

    /**
     * @return array<int, ToolCall>
     */
    protected function extractToolCalls(Response $response): array
    {
        $blocks = $response->json('content') ?? [];

        if (! is_array($blocks)) {
            return [];
        }

        $calls = [];

        foreach ($blocks as $block) {
            if (! $this->isBlock($block, 'tool_use', ['name', 'input'])) {
                continue;
            }

            $name = (string) ($block['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $calls[] = new ToolCall(
                id: (string) ($block['id'] ?? ''),
                name: $name,
                arguments: (array) ($block['input'] ?? []),
            );
        }

        return $calls;
    }

    /**
     * @param  array<int, mixed>  $blocks
     */
    private function textBlocks(array $blocks): string
    {
        return Collection::make($blocks)
            ->filter(fn (mixed $block): bool => $this->isBlock($block, 'text', ['text']))
            ->map(fn (array $block): string => (string) ($block['text'] ?? ''))
            ->filter()
            ->implode("\n");
    }

    /**
     * Whether a content block is of the given type.
     *
     * A missing `type` is tolerated: Anthropic always sends one, but proxies
     * fronting an Anthropic-compatible model frequently do not, and dropping the
     * whole reply over it would be a silent data loss.
     *
     * @param  array<int, string>  $requiredKeys
     */
    private function isBlock(mixed $block, string $type, array $requiredKeys): bool
    {
        if (! is_array($block)) {
            return false;
        }

        if (($block['type'] ?? null) !== null) {
            return $block['type'] === $type;
        }

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $block)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    protected function extractUsage(Response $response): array
    {
        $usage = $response->json('usage') ?? [];

        return [
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
        ];
    }
}
