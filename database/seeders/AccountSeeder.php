<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenantId = TenantContext::id();

        $accounts = [
            // Where the money actually lives.
            ['code' => 'JAGO', 'name' => 'Jago', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active'],
            ['code' => 'BRI', 'name' => 'BRI', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active'],
            ['code' => 'GOPAY', 'name' => 'GoPay', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active'],
            ['code' => 'CASH', 'name' => 'Cash', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active'],
            // Counter-account for opening balances / money brought in.
            ['code' => 'MODAL', 'name' => 'Opening Balance', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active'],
            // Income.
            ['code' => 'GAJI', 'name' => 'Salary Income', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active'],
            // Expense categories (budget targets measure these).
            ['code' => 'MAKAN', 'name' => 'Food & Beverage', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active'],
            ['code' => 'TRANSPORT', 'name' => 'Transport', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active'],
            ['code' => 'UTIL', 'name' => 'Electricity & Water', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active'],
            ['code' => 'BELANJA', 'name' => 'Shopping', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active'],
            ['code' => 'HIBURAN', 'name' => 'Entertainment', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active'],
            ['code' => 'KESEHATAN', 'name' => 'Health', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active'],
        ];

        foreach ($accounts as $accountData) {
            Account::firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => $accountData['code']],
                $accountData,
            );
        }
    }
}
