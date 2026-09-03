<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\JournalTemplate;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class JournalTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Recurring templates that the scheduler turns into draft journals each
     * month: salary, utility bill, and two automatic transfers.
     */
    public function run(): void
    {
        $tenantId = TenantContext::id();

        $templates = [
            [
                'name' => 'Gaji Bulanan',
                'description' => 'Penerimaan gaji tiap awal bulan.',
                'day_of_month' => 1,
                'lines' => [
                    ['JAGO', 8_500_000, 0, 'Gaji masuk'],
                    ['GAJI', 0, 8_500_000, 'Pendapatan gaji'],
                ],
            ],
            [
                'name' => 'Isi Ulang GoPay',
                'description' => 'Top-up GoPay untuk pengeluaran harian.',
                'day_of_month' => 9,
                'lines' => [
                    ['GOPAY', 500_000, 0, 'Isi ulang GoPay'],
                    ['JAGO', 0, 500_000, 'Top-up dari Jago'],
                ],
            ],
            [
                'name' => 'Tagihan Listrik & Air',
                'description' => 'Pembayaran tagihan bulanan.',
                'day_of_month' => 16,
                'lines' => [
                    ['UTIL', 600_000, 0, 'Tagihan listrik & air'],
                    ['JAGO', 0, 600_000, 'Dibayar dari Jago'],
                ],
            ],
            [
                'name' => 'Transfer Tabungan BRI',
                'description' => 'Pindah dana ke rekening tabungan BRI.',
                'day_of_month' => 20,
                'lines' => [
                    ['BRI', 1_000_000, 0, 'Transfer masuk ke BRI'],
                    ['JAGO', 0, 1_000_000, 'Transfer dari Jago'],
                ],
            ],
        ];

        $accountIds = Account::query()->pluck('id', 'code');

        foreach ($templates as $data) {
            $lines = $data['lines'];
            unset($data['lines']);

            $template = JournalTemplate::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $data['name']],
                [
                    ...$data,
                    'period_type' => 'monthly',
                    'is_active' => true,
                    'day_of_week' => null,
                ],
            );

            foreach ($lines as $index => [$code, $debit, $credit, $description]) {
                $accountId = $accountIds->get($code);

                if (! $accountId) {
                    continue;
                }

                $template->lines()->firstOrCreate([
                    'account_id' => $accountId,
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => $description,
                ], [
                    'line_number' => $index + 1,
                ]);
            }

            // Align the schedule with the template's monthly occurrence.
            $template->update(['next_run_at' => $template->nextRunDate()]);
        }
    }
}
