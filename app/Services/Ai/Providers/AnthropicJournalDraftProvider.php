<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AbstractJournalDraftProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\JournalDraft;
use Illuminate\Http\Client\Response;

class AnthropicJournalDraftProvider extends AbstractJournalDraftProvider
{
    public static function name(): string
    {
        return 'anthropic';
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    protected function requestPayload(string $statement, array $accounts): array
    {
        return [
            'model' => $this->config['model'] ?? 'claude-3-5-haiku-20241022',
            'max_tokens' => 1024,
            'system' => $this->buildPrompt($statement, $accounts),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $statement,
                ],
            ],
        ];
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

    protected function extractDraft(Response $response): JournalDraft
    {
        $content = $response->json('content.0.text');

        if (! is_string($content) || blank($content)) {
            throw AiProviderException::invalidResponse('The AI provider returned an empty response.');
        }

        return $this->parseDraft($content);
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
