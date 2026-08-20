<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\Response;

class AnthropicProvider extends AbstractAiProvider
{
    public static function name(): string
    {
        return 'anthropic';
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
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
            'max_tokens' => 1024,
            'messages' => collect($messages)
                ->reject(fn (array $message): bool => $message['role'] === 'system')
                ->values()
                ->all(),
        ];

        if (filled($system)) {
            $payload['system'] = $system;
        }

        return $payload;
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
        $content = $response->json('content.0.text');

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
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
        ];
    }
}
