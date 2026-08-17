<?php

namespace App\Services\Ai;

use App\Services\Ai\Concerns\ParsesJournalDrafts;
use App\Services\Ai\Contracts\JournalDraftProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class AbstractJournalDraftProvider implements JournalDraftProvider
{
    use ParsesJournalDrafts;

    /**
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function isConfigured(): bool
    {
        return ! blank(config('ai.providers.'.static::name().'.api_key'));
    }

    abstract public static function name(): string;

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    abstract protected function requestPayload(string $statement, array $accounts): array;

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    public function draft(string $statement, array $accounts): JournalDraft
    {
        try {
            $response = Http::baseUrl($this->config['base_uri'] ?? '')
                ->timeout($this->config['timeout'] ?? 30)
                ->withHeaders($this->headers())
                ->asJson()
                ->post($this->endpoint(), $this->requestPayload($statement, $accounts));

            return $this->parseResponse($response);
        } catch (AiProviderException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw AiProviderException::unavailable('The AI provider could not be reached.');
        } catch (\Throwable $e) {
            throw AiProviderException::unavailable('The AI provider request failed.');
        }
    }

    /**
     * @return array<string, string>
     */
    abstract protected function headers(): array;

    abstract protected function endpoint(): string;

    protected function parseResponse(Response $response): JournalDraft
    {
        if ($response->failed()) {
            throw AiProviderException::unavailable('The AI provider returned an error.');
        }

        return $this->extractDraft($response);
    }

    abstract protected function extractDraft(Response $response): JournalDraft;

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    protected function buildPrompt(string $statement, array $accounts): string
    {
        $accountList = collect($accounts)
            ->map(fn (array $account) => sprintf(
                '- %s (type: %s)',
                $account['name'],
                $account['type'],
            ))
            ->implode("\n");

        return <<<PROMPT
        You are a double-entry bookkeeping assistant. Convert the user's natural-language
        statement into a draft journal entry.

        The tenant's chart of accounts is:
        {$accountList}

        Rules:
        - Use the accounts listed above when they clearly match. Do not invent accounts.
        - Every journal must balance: total debits must equal total credits.
        - Each line must have exactly one of debit or credit (a positive number).
        - account_type must be one of: asset, liability, equity, income, expense.
        - transaction_date should be the date the statement refers to, or today (YYYY-MM-DD) if unknown.
        - description is a short memo for the journal.
        - tags are short lowercase keywords derived from the statement.

        Respond ONLY with JSON in this exact shape, with no markdown fences:
        {"draft":{"transaction_date":"YYYY-MM-DD","description":"...","lines":[{"account_name":"...","account_type":"...","debit":"12.34","credit":null,"description":"optional"}],"tags":["tag"]}}

        Statement: {$statement}
        PROMPT;
    }
}
