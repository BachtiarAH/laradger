<?php

namespace Database\Seeders;

use App\Models\Journal;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class JournalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Generates a three-month personal finance history (two full previous
     * months plus a partial current month) so budgets, analytics and the
     * overview have realistic posted actuals. A draft journal in the current
     * month demonstrates that drafts never count towards allocatable balance.
     */
    public function run(): void
    {
        $tenantId = TenantContext::id();

        Carbon::setLocale('id');

        $entries = [];

        foreach ([0, 1, 2] as $index) {
            $month = now()->startOfMonth()->subMonths(2)->addMonths($index);
            $monthName = $month->translatedFormat('F Y');
            $prefix = 'JRN-DEMO-'.$month->format('Ymd');
            $date = fn (int $day): string => $month->copy()->day($day)->toDateString();
            $add = function (string $key, int $day, string $description, string $status = 'posted') use (&$entries, $prefix, $date): void {
                $entries[] = [
                    'reference' => $prefix.'-'.$key,
                    'transaction_date' => $date($day),
                    'description' => $description,
                    'status' => $status,
                    'source' => 'manual',
                ];
            };

            if ($index === 0) {
                $add('OPEN', 1, 'Saldo awal pembukaan rekening');
            }

            $add('GAJI', 1, "Gaji bulan {$monthName}");
            $add('MAKAN', 2, "Belanja makanan & minuman {$monthName}");

            if ($index < 2) {
                $add('TOPUP', 9, "Isi ulang GoPay {$monthName}");
                $add('MAKAN2', 11, "Makan di luar (GoPay) {$monthName}");
                $add('TRANSP', 13, "Transportasi harian (GoPay) {$monthName}");
                $add('UTIL', 16, "Tagihan listrik & air {$monthName}");
                $add('BELANJA', 18, "Belanja bulanan {$monthName}");
                $add('BRI_TRF', 20, "Transfer tabungan ke BRI {$monthName}");
                $add('HIBURAN', 22, "Hiburan & rekreasi {$monthName}");
                $add('KES', 24, "Kesehatan & obat {$monthName}");
            } else {
                $add('UTIL_DRAFT', 3, 'Tagihan listrik bulan berjalan (draft)', 'draft');
            }
        }

        foreach ($entries as $entry) {
            Journal::firstOrCreate(
                ['tenant_id' => $tenantId, 'reference' => $entry['reference']],
                $entry,
            );
        }
    }
}
