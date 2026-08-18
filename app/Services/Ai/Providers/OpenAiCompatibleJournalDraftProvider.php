<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AbstractJournalDraftProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\JournalDraft;
use Illuminate\Http\Client\Response;

class OpenAiCompatibleJournalDraftProvider extends AbstractJournalDraftProvider
{
    public static function name(): string
    {
        return 'openai_compatible';
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    protected function requestPayload(string $statement, array $accounts): array
    {
        return [
            'model' => $this->config['model'] ?? 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->buildPrompt($statement, $accounts),
                ],
                [
                    'role' => 'user',
                    'content' => $statement,
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ];
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

    protected function extractDraft(Response $response): JournalDraft
    {
        $content = $response->json('choices.0.message.content');

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
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
        ];
    }
}
