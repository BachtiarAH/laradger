<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenantId = TenantContext::id();

        $chartOfAccounts = [
            [
                'code' => '1000',
                'name' => 'Assets',
                'type' => 'asset',
                'currency' => 'IDR',
                'status' => 'active',
                'children' => [
                    ['code' => '1100', 'name' => 'Cash on Hand', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active'],
                    ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active'],
                    ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active'],
                ],
            ],
            [
                'code' => '2000',
                'name' => 'Liabilities',
                'type' => 'liability',
                'currency' => 'IDR',
                'status' => 'active',
                'children' => [
                    ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active'],
                    ['code' => '2200', 'name' => 'Loan Payable', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active'],
                ],
            ],
            [
                'code' => '3000',
                'name' => 'Equity',
                'type' => 'equity',
                'currency' => 'IDR',
                'status' => 'active',
                'children' => [
                    ['code' => '3100', 'name' => 'Owner\'s Equity', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active'],
                    ['code' => '3200', 'name' => 'Retained Earnings', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active'],
                ],
            ],
            [
                'code' => '4000',
                'name' => 'Income',
                'type' => 'income',
                'currency' => 'IDR',
                'status' => 'active',
                'children' => [
                    ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active'],
                    ['code' => '4200', 'name' => 'Interest Income', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active'],
                ],
            ],
            [
                'code' => '5000',
                'name' => 'Expenses',
                'type' => 'expense',
                'currency' => 'IDR',
                'status' => 'active',
                'children' => [
                    ['code' => '5100', 'name' => 'Rent Expense', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active'],
                    ['code' => '5200', 'name' => 'Utilities Expense', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active'],
                    ['code' => '5300', 'name' => 'Operating Expenses', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active'],
                ],
            ],
        ];

        foreach ($chartOfAccounts as $accountData) {
            $parent = Account::firstOrCreate(
                Arr::only($accountData, ['tenant_id', 'code']),
                Arr::except($accountData, ['children']),
            );

            foreach ($accountData['children'] as $childData) {
                $parent->children()->firstOrCreate(
                    Arr::only($childData, ['tenant_id', 'code']),
                    Arr::except($childData, ['tenant_id', 'code']),
                );
            }
        }
    }
}
