<?php

namespace App\Services\Ai\Tools\Tools;

use App\Models\Journal;
use App\Services\Ai\Tools\AbstractAiTool;
use App\Services\Ai\Tools\AiToolKind;

class JournalsSearchTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'journals_search';
    }

    public function description(): string
    {
        return 'List journal entries, newest first, optionally filtered by status, date range, account, '
            .'or free text. Use this to find an existing entry before proposing a reversal, or to answer '
            .'questions about what has already been recorded.';
    }

    public function kind(): AiToolKind
    {
        return AiToolKind::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'posted', 'archived'],
                    'description' => 'Optional status filter.',
                ],
                'from' => ['type' => 'string', 'description' => 'Optional inclusive start date, YYYY-MM-DD.'],
                'to' => ['type' => 'string', 'description' => 'Optional inclusive end date, YYYY-MM-DD.'],
                'account_id' => self::uuidSchema('Optional: only entries touching this account.'),
                'search' => ['type' => 'string', 'description' => 'Optional partial match on the description.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function title(array $arguments): string
    {
        return 'Search journals';
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));

        $journals = Journal::query()
            ->with('lines.account')
            ->when(filled($arguments['status'] ?? null), fn ($query) => $query->where('status', $arguments['status']))
            ->when(filled($arguments['from'] ?? null), fn ($query) => $query->whereDate('transaction_date', '>=', $arguments['from']))
            ->when(filled($arguments['to'] ?? null), fn ($query) => $query->whereDate('transaction_date', '<=', $arguments['to']))
            ->when(filled($arguments['account_id'] ?? null), fn ($query) => $query->whereHas('lines', fn ($lines) => $lines->where('account_id', $arguments['account_id'])))
            ->when(filled($arguments['search'] ?? null), fn ($query) => $query->where('description', 'like', '%'.$this->stringArgument($arguments, 'search').'%'))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return [
            'journals' => $journals->map(fn (Journal $journal): array => [
                'id' => $journal->id,
                'reference' => $journal->reference,
                'transaction_date' => $journal->transaction_date?->toDateString(),
                'description' => $journal->description,
                'status' => $journal->status,
                'total_debit' => number_format((float) $journal->lines->sum('debit'), 2, '.', ''),
                'total_credit' => number_format((float) $journal->lines->sum('credit'), 2, '.', ''),
                'lines' => $journal->lines->map(fn ($line): array => [
                    'account_id' => $line->account_id,
                    'account_name' => $line->account?->name,
                    'debit' => number_format((float) $line->debit, 2, '.', ''),
                    'credit' => number_format((float) $line->credit, 2, '.', ''),
                ])->all(),
            ])->all(),
            'count' => $journals->count(),
        ];
    }
}
