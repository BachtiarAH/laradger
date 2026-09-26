<?php

namespace App\Services\Ai\Tools\Tools;

use App\Models\Account;
use App\Services\Ai\Tools\AbstractAiTool;
use App\Services\Ai\Tools\AiToolKind;

class AccountsSearchTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'accounts_search';
    }

    public function description(): string
    {
        return 'List chart-of-accounts entries, optionally filtered by type or a name search. '
            .'Call this before creating a journal so you reference real account ids, and to check '
            .'whether an account already exists before proposing to create one.';
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
                'type' => [
                    'type' => 'string',
                    'enum' => ['asset', 'liability', 'equity', 'income', 'expense'],
                    'description' => 'Optional account type filter.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Optional partial match on the account name.',
                ],
                'leaf_only' => [
                    'type' => 'boolean',
                    'description' => 'When true, return only accounts that can hold journal lines.',
                ],
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
        return 'Look up accounts';
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $accounts = Account::query()
            ->when(filled($arguments['type'] ?? null), fn ($query) => $query->where('type', $arguments['type']))
            ->when(filled($arguments['search'] ?? null), fn ($query) => $query->where('name', 'like', '%'.$this->stringArgument($arguments, 'search').'%'))
            ->when(($arguments['leaf_only'] ?? false) === true, fn ($query) => $query->where('is_header', false))
            ->orderBy('type')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'code', 'name', 'type', 'is_header', 'status']);

        return [
            'accounts' => $accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'is_header' => (bool) $account->is_header,
                'status' => $account->status,
                'can_post_to' => $account->isLeaf() && $account->status === 'active',
            ])->all(),
            'count' => $accounts->count(),
        ];
    }
}
