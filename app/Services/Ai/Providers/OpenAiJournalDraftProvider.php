<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AbstractJournalDraftProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\JournalDraft;
use Illuminate\Http\Client\Response;

class OpenAiJournalDraftProvider extends AbstractJournalDraftProvider
{
    public static function name(): string
    {
        return 'openai';
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
        return '/v1/chat/completions';
    }

    protected function extractDraft(Response $response): JournalDraft
    {
        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || blank($content)) {
            throw AiProviderException::invalidResponse('The AI provider returned an empty response.');
        }

        return $this->parseDraft($content);
    }
}
