<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\JournalDraft;

interface JournalDraftProvider
{
    /**
     * Generate a draft journal entry from a natural-language statement.
     *
     * @param  array<int, array<string, mixed>>  $accounts
     */
    public function draft(string $statement, array $accounts): JournalDraft;

    /**
     * The provider is unavailable (missing configuration, unreachable, etc.).
     */
    public static function isConfigured(): bool;
}
