<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Providers\Concerns\MapsOpenAiMessages;
use App\Services\Ai\Tools\ToolCall;
use Illuminate\Http\Client\Response;

class OpenAiCompatibleProvider extends AbstractAiProvider
{
    use MapsOpenAiMessages;

    public static function name(): string
    {
        return 'openai_compatible';
    }

    /**
     * Self-hosted models are frequently too small or too loosely templated to
     * emit a well-formed tool call, so this stays opt-out via
     * `ai.providers.*.supports_tools` for endpoints known not to.
     */
    public function supportsTools(): bool
    {
        return (bool) ($this->config['supports_tools'] ?? true);
    }

    /**
     * @param  array<int, array{role: string, content: string, tool_calls?: array<int, ToolCall>, tool_call_id?: string|null}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function requestPayload(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $this->config['model'] ?? 'gpt-4o-mini',
            'messages' => $this->wireMessages($messages),
        ];

        $tools = $this->requestedTools($options);

        if ($tools !== []) {
            $payload['tools'] = array_map(
                static fn (array $tool): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'parameters' => $tool['parameters'],
                    ],
                ],
                $tools,
            );

            if (filled($options['tool_choice'] ?? null)) {
                $payload['tool_choice'] = $options['tool_choice'];
            }
        }

        if (($options['structured'] ?? false) === true) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->config['api_key'],
        ];
    }

    protected function endpoint(): string
    {
        return $this->config['endpoint'] ?? '/v1/chat/completions';
    }

    protected function extractContent(Response $response): string
    {
        $content = $response->json('choices.0.message.content');

        return is_string($content) ? $content : '';
    }

    /**
     * @return array<int, ToolCall>
     */
    protected function extractToolCalls(Response $response): array
    {
        $calls = $response->json('choices.0.message.tool_calls') ?? [];

        if (! is_array($calls)) {
            return [];
        }

        $normalized = [];

        foreach ($calls as $call) {
            if (! is_array($call)) {
                continue;
            }

            $name = (string) ($call['function']['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $arguments = $call['function']['arguments'] ?? [];

            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            }

            $normalized[] = new ToolCall(
                id: (string) ($call['id'] ?? ''),
                name: $name,
                arguments: (array) $arguments,
            );
        }

        return $normalized;
    }

    /**
     * @return array<string, int>
     */
    protected function extractUsage(Response $response): array
    {
        $usage = $response->json('usage') ?? [];

        return [
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
        ];
    }
}
