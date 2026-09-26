<?php

namespace App\Services\Ai\Tools\Tools;

use App\Models\Account;
use App\Services\Ai\Tools\AbstractAiTool;
use App\Services\Ai\Tools\AiToolKind;

class AccountsGetTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'accounts_get';
    }

    public function description(): string
    {
        return 'Get one account with its current posted balance. Use this to check how much money '
            .'is actually available in an account, or how much of it is already reserved by allocations.';
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
                'account_id' => self::uuidSchema('The account id, as returned by accounts_search.'),
            ],
            'required' => ['account_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function title(array $arguments): string
    {
        return 'Look up an account';
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        // Tenant scoped: a foreign account id is a 404, never a leak.
        $account = Account::query()->findOrFail($this->stringArgument($arguments, 'account_id'));

        return [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'currency' => $account->currency,
                'status' => $account->status,
                'is_header' => (bool) $account->is_header,
                'posted_net_balance' => number_format($account->postedNetBalance(), 2, '.', ''),
                'allocated_total' => number_format($account->allocatedTotal(), 2, '.', ''),
            ],
        ];
    }
}
