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

        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        $budgets = [
            [
                'name' => 'Gaji Bulanan',
                'description' => 'Ekspektasi gaji masuk setiap bulan.',
                'amount' => 8_500_000,
                'budget_type' => 'income',
                'account_codes' => ['GAJI'],
            ],
            [
                'name' => 'Makanan & Minuman',
                'description' => 'Anggaran belanja harian.',
                'amount' => 1_800_000,
                'budget_type' => 'expense',
                'account_codes' => ['MAKAN'],
            ],
            [
                'name' => 'Transportasi',
                'amount' => 500_000,
                'budget_type' => 'expense',
                'account_codes' => ['TRANSPORT'],
            ],
            [
                'name' => 'Listrik & Air',
                'amount' => 700_000,
                'budget_type' => 'expense',
                'account_codes' => ['UTIL'],
            ],
            [
                'name' => 'Hiburan & Kesehatan',
                'amount' => 900_000,
                'budget_type' => 'expense',
                'account_codes' => ['HIBURAN', 'KESEHATAN'],
            ],
            [
                'name' => 'Belanja Bulanan',
                'amount' => 1_000_000,
                'budget_type' => 'expense',
                'account_codes' => ['BELANJA'],
            ],
        ];

        foreach ($budgets as $data) {
            $accountCodes = $data['account_codes'];
            unset($data['account_codes']);

            $budget = Budget::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $data['name']],
                [
                    ...$data,
                    'user_id' => $user->id,
                    'period_type' => 'monthly',
                    'is_recurring' => true,
                    'starts_at' => $from->toDateString(),
                    'ends_at' => $to->toDateString(),
                ],
            );

            $accountIds = Account::query()
                ->whereIn('code', $accountCodes)
                ->pluck('id');

            $budget->accounts()->syncWithoutDetaching($accountIds);
        }
    }
}
