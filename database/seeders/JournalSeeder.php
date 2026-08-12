<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class JournalSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::first();

        $journals = [
            [
                'transaction_date' => now()->subMonths(2),
                'description' => 'Initial capital injection',
                'reference' => 'JRN-0001',
                'status' => 'posted',
                'source' => 'manual',
            ],
            [
                'transaction_date' => now()->subMonths(1),
                'description' => 'Purchase of inventory on credit',
                'reference' => 'JRN-0002',
                'status' => 'posted',
                'source' => 'manual',
            ],
            [
                'transaction_date' => now()->subWeeks(2),
                'description' => 'Cash sale of goods',
                'reference' => 'JRN-0003',
                'status' => 'posted',
                'source' => 'system',
            ],
            [
                'transaction_date' => now()->subWeek(),
                'description' => 'Monthly rent payment',
                'reference' => 'JRN-0004',
                'status' => 'posted',
                'source' => 'manual',
            ],
            [
                'transaction_date' => now(),
                'description' => 'Draft adjusting entry for utilities',
                'reference' => 'JRN-0005',
                'status' => 'draft',
                'source' => 'manual',
            ],
        ];

        foreach ($journals as $journalData) {
            $user->journals()->create($journalData);
        }
    }
}
