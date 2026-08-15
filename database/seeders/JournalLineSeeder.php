<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Journal;
use Illuminate\Database\Seeder;

class JournalLineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $journalLines = [
            'JRN-0001' => [
                ['account' => '1100', 'debit' => 1000000.00, 'credit' => 0.00, 'description' => 'Cash from capital injection'],
                ['account' => '3100', 'debit' => 0.00, 'credit' => 1000000.00, 'description' => 'Owner equity contribution'],
            ],
            'JRN-0002' => [
                ['account' => '1300', 'debit' => 500000.00, 'credit' => 0.00, 'description' => 'Inventory purchased'],
                ['account' => '2100', 'debit' => 0.00, 'credit' => 500000.00, 'description' => 'Amount owed to supplier'],
            ],
            'JRN-0003' => [
                ['account' => '1100', 'debit' => 750000.00, 'credit' => 0.00, 'description' => 'Cash received from sale'],
                ['account' => '4100', 'debit' => 0.00, 'credit' => 750000.00, 'description' => 'Sales revenue recognized'],
            ],
            'JRN-0004' => [
                ['account' => '5100', 'debit' => 250000.00, 'credit' => 0.00, 'description' => 'Rent expense incurred'],
                ['account' => '1100', 'debit' => 0.00, 'credit' => 250000.00, 'description' => 'Rent paid from cash'],
            ],
            'JRN-0005' => [
                ['account' => '5200', 'debit' => 120000.00, 'credit' => 0.00, 'description' => 'Utilities expense accrued'],
                ['account' => '2100', 'debit' => 0.00, 'credit' => 120000.00, 'description' => 'Utilities payable'],
            ],
        ];

        foreach ($journalLines as $reference => $lines) {
            $journal = Journal::where('reference', $reference)->first();

            if (! $journal) {
                continue;
            }

            foreach ($lines as $lineData) {
                $account = Account::where('code', $lineData['account'])->first();

                $journal->lines()->firstOrCreate([
                    'account_id' => $account->id,
                    'debit' => $lineData['debit'],
                    'credit' => $lineData['credit'],
                    'description' => $lineData['description'],
                ]);
            }
        }
    }
}
