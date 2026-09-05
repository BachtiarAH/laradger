<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenantId = TenantContext::id();

        // Main chart of accounts structure following PSAK/GAAP standards
        $accounts = [
            // 1 - ASSETS
            ['code' => '1', 'name' => 'ASSETS', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1', 'name' => 'Current Assets', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-1', 'name' => 'Cash and Cash Equivalents', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-1-1', 'name' => 'Cash on Hand', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-1-2', 'name' => 'Cash in Bank - BCA', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-1-3', 'name' => 'Cash in Bank - BRI', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-1-4', 'name' => 'Cash in Bank - Mandiri', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-1-5', 'name' => 'Digital Wallets - GoPay', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-1-6', 'name' => 'Digital Wallets - OVO', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-1-7', 'name' => 'Digital Wallets - Dana', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-2', 'name' => 'Accounts Receivable', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-2-1', 'name' => 'Trade Receivables', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-2-2', 'name' => 'Other Receivables', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-3', 'name' => 'Inventory', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-3-1', 'name' => 'Raw Materials', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-3-2', 'name' => 'Work in Progress', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-1-3-3', 'name' => 'Finished Goods', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2', 'name' => 'Non-Current Assets', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-1', 'name' => 'Fixed Assets', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-1-1', 'name' => 'Land', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-1-2', 'name' => 'Buildings', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-1-3', 'name' => 'Machinery and Equipment', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-1-4', 'name' => 'Vehicles', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-1-5', 'name' => 'Furniture and Fixtures', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-1-6', 'name' => 'Computer Equipment', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-2', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-2-1', 'name' => 'Accumulated Depreciation - Buildings', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-2-2', 'name' => 'Accumulated Depreciation - Equipment', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '1-2-2-3', 'name' => 'Accumulated Depreciation - Vehicles', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],

            // 2 - LIABILITIES
            ['code' => '2', 'name' => 'LIABILITIES', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1', 'name' => 'Current Liabilities', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1-1', 'name' => 'Accounts Payable', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1-1-1', 'name' => 'Trade Payables', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1-1-2', 'name' => 'Other Payables', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1-2', 'name' => 'Accrued Expenses', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1-2-1', 'name' => 'Accrued Salaries', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1-2-2', 'name' => 'Accrued Utilities', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1-2-3', 'name' => 'Accrued Interest', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1-3', 'name' => 'Short-term Debt', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1-3-1', 'name' => 'Bank Loans - Short Term', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-1-3-2', 'name' => 'Credit Card Payables', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-2', 'name' => 'Non-Current Liabilities', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-2-1', 'name' => 'Long-term Debt', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-2-1-1', 'name' => 'Bank Loans - Long Term', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-2-1-2', 'name' => 'Mortgage Payable', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '2-2-2', 'name' => 'Deferred Tax Liabilities', 'type' => 'liability', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],

            // 3 - EQUITY
            ['code' => '3', 'name' => 'EQUITY', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '3-1', 'name' => 'Owner\'s Equity', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '3-1-1', 'name' => 'Capital Stock', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '3-1-1-1', 'name' => 'Common Stock', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '3-1-1-2', 'name' => 'Preferred Stock', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '3-1-2', 'name' => 'Additional Paid-in Capital', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '3-1-3', 'name' => 'Retained Earnings', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '3-1-4', 'name' => 'Opening Balance Equity', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '3-2', 'name' => 'Reserves', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '3-2-1', 'name' => 'Legal Reserve', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '3-2-2', 'name' => 'General Reserve', 'type' => 'equity', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],

            // 4 - REVENUE
            ['code' => '4', 'name' => 'REVENUE', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-1', 'name' => 'Operating Revenue', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-1-1', 'name' => 'Sales Revenue', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-1-1-1', 'name' => 'Product Sales', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-1-1-2', 'name' => 'Service Revenue', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-1-2', 'name' => 'Other Operating Income', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-1-2-1', 'name' => 'Consulting Fees', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-1-2-2', 'name' => 'Commission Income', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-2', 'name' => 'Non-Operating Revenue', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-2-1', 'name' => 'Interest Income', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-2-2', 'name' => 'Dividend Income', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-2-3', 'name' => 'Rental Income', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '4-2-4', 'name' => 'Gain on Sale of Assets', 'type' => 'income', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],

            // 5 - COST OF GOODS SOLD
            ['code' => '5', 'name' => 'COST OF GOODS SOLD', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '5-1', 'name' => 'Direct Materials', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '5-1-1', 'name' => 'Raw Materials Cost', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '5-1-2', 'name' => 'Packaging Materials', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '5-2', 'name' => 'Direct Labor', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '5-2-1', 'name' => 'Production Wages', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '5-2-2', 'name' => 'Production Overtime', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '5-3', 'name' => 'Manufacturing Overhead', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '5-3-1', 'name' => 'Factory Utilities', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '5-3-2', 'name' => 'Factory Maintenance', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '5-3-3', 'name' => 'Depreciation - Production Equipment', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],

            // 6 - OPERATING EXPENSES
            ['code' => '6', 'name' => 'OPERATING EXPENSES', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-1', 'name' => 'Selling Expenses', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-1-1', 'name' => 'Sales Commissions', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-1-2', 'name' => 'Advertising and Promotion', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-1-3', 'name' => 'Marketing Expenses', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-1-4', 'name' => 'Delivery and Shipping', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2', 'name' => 'Administrative Expenses', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-1', 'name' => 'Salaries and Wages', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-1-1', 'name' => 'Office Salaries', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-1-2', 'name' => 'Management Salaries', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-2', 'name' => 'Employee Benefits', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-2-1', 'name' => 'Health Insurance', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-2-2', 'name' => 'Retirement Contributions', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-3', 'name' => 'Office Expenses', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-3-1', 'name' => 'Office Supplies', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-3-2', 'name' => 'Utilities', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-3-3', 'name' => 'Telephone and Internet', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-3-4', 'name' => 'Rent Expense', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-3-5', 'name' => 'Insurance Expense', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-4', 'name' => 'Professional Fees', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-4-1', 'name' => 'Legal Fees', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-4-2', 'name' => 'Accounting Fees', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-4-3', 'name' => 'Consulting Fees', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-5', 'name' => 'Depreciation Expense', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-5-1', 'name' => 'Depreciation - Office Equipment', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-5-2', 'name' => 'Depreciation - Furniture', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-6', 'name' => 'Travel and Entertainment', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-6-1', 'name' => 'Business Travel', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-6-2', 'name' => 'Business Meals', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '6-2-6-3', 'name' => 'Client Entertainment', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],

            // 7 - OTHER EXPENSES
            ['code' => '7', 'name' => 'OTHER EXPENSES', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '7-1', 'name' => 'Financial Expenses', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '7-1-1', 'name' => 'Interest Expense', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '7-1-2', 'name' => 'Bank Charges', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '7-1-3', 'name' => 'Foreign Exchange Loss', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '7-2', 'name' => 'Non-Operating Expenses', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '7-2-1', 'name' => 'Loss on Sale of Assets', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '7-2-2', 'name' => 'Bad Debt Expense', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
            ['code' => '7-2-3', 'name' => 'Charitable Contributions', 'type' => 'expense', 'currency' => 'IDR', 'status' => 'active', 'parent_id' => null],
        ];

        // First pass: create all accounts
        $accountMap = [];
        foreach ($accounts as $accountData) {
            $account = Account::firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => $accountData['code']],
                $accountData,
            );
            $accountMap[$accountData['code']] = $account->id;
        }

        // Second pass: establish parent-child relationships
        foreach ($accounts as $accountData) {
            $code = $accountData['code'];
            $parentCode = $this->getParentCode($code);

            if ($parentCode && isset($accountMap[$parentCode])) {
                Account::where('code', $code)
                    ->where('tenant_id', $tenantId)
                    ->update(['parent_id' => $accountMap[$parentCode]]);
            }
        }

        // Third pass: mark all parents as header (is_header=true). Use query builder to
        // avoid triggering Eloquent events that would reject non-header parents.
        $parentIds = collect($accounts)
            ->map(fn (array $data) => $this->getParentCode($data['code']))
            ->filter()
            ->filter(fn (string $code) => isset($accountMap[$code]))
            ->map(fn (string $code) => $accountMap[$code])
            ->unique()
            ->values()
            ->all();

        if ($parentIds !== []) {
            DB::table('accounts')
                ->whereIn('id', $parentIds)
                ->where('tenant_id', $tenantId)
                ->update(['is_header' => true]);
        }
    }

    /**
     * Get parent code from child code
     * Examples:
     * - 1-1-1-1 -> 1-1-1
     * - 1-1-1 -> 1-1
     * - 1-1 -> 1
     * - 1 -> null
     */
    private function getParentCode(string $code): ?string
    {
        $lastDash = strrpos($code, '-');
        if ($lastDash === false) {
            return null;
        }

        return substr($code, 0, $lastDash);
    }
}
