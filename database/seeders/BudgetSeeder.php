<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Budget;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class BudgetSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = TenantContext::id();
        $user = User::query()->where('email', 'test@example.com')->first();

        if (! $user) {
            return;
        }

        $expenseAccounts = Account::query()
            ->whereIn('code', ['5100', '5200', '5300'])
            ->pluck('id', 'code');

        $budgets = [
            [
                'name' => 'Monthly Living Expenses',
                'amount' => 2500000,
                'starts_at' => '2026-08-01',
                'ends_at' => '2026-08-31',
                'account_codes' => ['5100', '5200', '5300'],
            ],
            [
                'name' => 'Utilities Budget',
                'amount' => 500000,
                'starts_at' => '2026-08-01',
                'ends_at' => '2026-08-31',
                'account_codes' => ['5200'],
            ],
        ];

        foreach ($budgets as $data) {
            $accountCodes = $data['account_codes'];
            unset($data['account_codes']);

            $budget = Budget::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $data['name']],
                [...$data, 'user_id' => $user->id],
            );

            $accountIds = collect($accountCodes)
                ->map(fn (string $code) => $expenseAccounts->get($code))
                ->filter()
                ->values();

            $budget->accounts()->syncWithoutDetaching($accountIds);
        }
    }
}
