<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\Response;

class OpenAiProvider extends AbstractAiProvider
{
    public static function name(): string
    {
        return 'openai';
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function requestPayload(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $this->config['model'] ?? 'gpt-4o-mini',
            'messages' => $messages,
        ];

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
        return '/v1/chat/completions';
    }

    protected function extractContent(Response $response): string
    {
        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || blank($content)) {
            throw AiProviderException::invalidResponse(
                'The AI provider returned an empty response.',
                rawResponse: $response->json() ?? [],
            );
        }

        return $content;
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
