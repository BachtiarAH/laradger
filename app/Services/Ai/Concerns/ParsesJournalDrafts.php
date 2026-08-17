<?php

namespace App\Services\Ai\Concerns;

use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\JournalDraft;

trait ParsesJournalDrafts
{
    /**
     * Parse and validate a raw JSON string into a JournalDraft.
     */
    protected function parseDraft(string $json): JournalDraft
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
