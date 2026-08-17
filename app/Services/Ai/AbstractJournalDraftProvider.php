<?php

namespace App\Services\Ai;

use App\Services\Ai\Concerns\ParsesJournalDrafts;
use App\Services\Ai\Contracts\AiCallRecorder;
use App\Services\Ai\Contracts\JournalDraftProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractJournalDraftProvider implements JournalDraftProvider
{
    use ParsesJournalDrafts;

    /**
     * @var array<string, mixed>
     */
    protected array $config;

    protected ?AiCallRecorder $recorder;

    protected ?Response $lastResponse = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config, ?AiCallRecorder $recorder = null)
    {
        $this->config = $config;
        $this->recorder = $recorder;
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
        $start = hrtime(true);

        $record = AiCallRecord::start(
            provider: static::name(),
            model: $this->config['model'] ?? 'default',
            user_id: auth()->id(),
            tenant_id: TenantContext::id(),
            statement: $statement,
            prompt: $this->buildPrompt($statement, $accounts),
        );

        try {
            $response = Http::baseUrl($this->config['base_uri'] ?? '')
                ->timeout($this->config['timeout'] ?? 30)
                ->withHeaders($this->headers())
                ->asJson()
                ->post($this->endpoint(), $this->requestPayload($statement, $accounts));

            $this->lastResponse = $response;

            $draft = $this->parseResponse($response);
            $draft->record_id = $record->id;

            $this->recorder?->record(
                $record->finish(
                    latencyMs: $this->elapsedMs($start),
                    success: true,
                    draft: $draft->toArray(),
                    rawResponse: $response->json(),
                    usage: $this->extractUsage($response),
                ),
            );

            return $draft;
        } catch (AiProviderException $e) {
            Log::error('AI provider returned an error.', [
                'provider' => static::name(),
                'model' => $this->config['model'] ?? 'default',
                'error' => $e->getMessage(),
                'raw_response' => $this->lastResponse?->json(),
            ]);

            $this->recorder?->record(
                $record->finish(
                    latencyMs: $this->elapsedMs($start),
                    success: false,
                    error: $e->getMessage(),
                    rawResponse: $this->lastResponse?->json(),
                ),
            );

            throw $e;
        } catch (ConnectionException $e) {
            Log::error('The AI provider could not be reached.', [
                'provider' => static::name(),
                'model' => $this->config['model'] ?? 'default',
                'error' => $e->getMessage(),
            ]);

            $this->recorder?->record(
                $record->finish(
                    latencyMs: $this->elapsedMs($start),
                    success: false,
                    error: 'The AI provider could not be reached.',
                ),
            );

            throw AiProviderException::unavailable('The AI provider could not be reached.');
        } catch (\Throwable $e) {
            Log::error('The AI provider request failed.', [
                'provider' => static::name(),
                'model' => $this->config['model'] ?? 'default',
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            $this->recorder?->record(
                $record->finish(
                    latencyMs: $this->elapsedMs($start),
                    success: false,
                    error: 'The AI provider request failed.',
                ),
            );

            throw AiProviderException::unavailable('The AI provider request failed.');
        }
    }

    /**
     * @return array<string, string>
     */
    abstract protected function headers(): array;

    abstract protected function endpoint(): string;

    /**
     * @return array<string, int|string>
     */
    abstract protected function extractUsage(Response $response): array;

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

        $custom = config('ai.prompt');

        if (filled($custom)) {
            return str_replace(
                [':accounts', ':statement'],
                [$accountList, $statement],
                $custom,
            );
        }

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

    private function elapsedMs(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
