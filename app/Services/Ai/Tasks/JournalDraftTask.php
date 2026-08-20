<?php

namespace App\Services\Ai\Tasks;

use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Gateway\AiGateway;

class JournalDraftTask implements AiTask
{
    public function __construct(
        private readonly AiGateway $gateway,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    public function draft(string $statement, array $accounts): JournalDraft
    {
        return $this->gateway->run($this, [
            'statement' => $statement,
            'accounts' => $accounts,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function statement(array $context): ?string
    {
        return $context['statement'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function prompt(array $context): string
    {
        return $this->buildPrompt(
            (string) ($context['statement'] ?? ''),
            (array) ($context['accounts'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array{role: string, content: string}>
     */
    public function messages(array $context): array
    {
        return [
            ['role' => 'system', 'content' => $this->prompt($context)],
            ['role' => 'user', 'content' => (string) ($context['statement'] ?? '')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return ['structured' => true];
    }

    public function interpret(string $content, string $recordId): JournalDraft
    {
        $draft = $this->parseDraft($content);
        $draft->record_id = $recordId;

        return $draft;
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    private function buildPrompt(string $statement, array $accounts): string
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

    /**
     * Parse and validate a raw JSON string into a JournalDraft.
     */
    private function parseDraft(string $json): JournalDraft
    {
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $data = is_array($payload) ? ($payload['draft'] ?? $payload) : [];

        if (! is_array($data)) {
            throw AiProviderException::invalidResponse('The AI provider returned an unparseable draft.');
        }

        $lines = $data['lines'] ?? null;

        if (! is_array($lines) || $lines === []) {
            throw AiProviderException::invalidResponse('The AI provider did not return any journal lines.');
        }

        $normalized = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                throw AiProviderException::invalidResponse('The AI provider returned an invalid journal line.');
            }

            $debit = array_key_exists('debit', $line) && $line['debit'] !== null ? (string) $line['debit'] : null;
            $credit = array_key_exists('credit', $line) && $line['credit'] !== null ? (string) $line['credit'] : null;

            if (($debit === null && $credit === null) || ($debit !== null && $credit !== null)) {
                throw AiProviderException::invalidResponse('Each journal line must have exactly one of debit or credit.');
            }

            if (($debit !== null && ! is_numeric($debit)) || ($credit !== null && ! is_numeric($credit))) {
                throw AiProviderException::invalidResponse('Journal line amounts must be numeric.');
            }

            $normalized[] = [
                'account_name' => (string) ($line['account_name'] ?? ''),
                'account_type' => (string) ($line['account_type'] ?? ''),
                'debit' => $debit,
                'credit' => $credit,
                'description' => isset($line['description']) ? (string) $line['description'] : null,
            ];
        }

        return new JournalDraft(
            transaction_date: isset($data['transaction_date']) ? (string) $data['transaction_date'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            lines: $normalized,
            tags: array_map('strval', (array) ($data['tags'] ?? [])),
        );
    }
}
