<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Journal;
use Illuminate\Database\Seeder;

class JournalLineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Mirrors the plan in JournalSeeder: every journal reference gets balanced
     * debit/credit lines (debit where the money went, credit where it came
     * from), using the personal chart of accounts created by AccountSeeder.
     */
    public function run(): void
    {
        $amountFor = fn (string $key, int $index): int => match ($key) {
            'GAJI' => [8_000_000, 8_500_000, 8_500_000][$index],
            'MAKAN' => [1_200_000, 1_400_000, 150_000][$index],
            'BELANJA' => [300_000, 350_000][$index],
            'HIBURAN' => [400_000, 450_000][$index],
            'KES' => [250_000, 200_000][$index],
            default => 0,
        };

        $linesFor = function (string $key, int $amount): array {
            return match ($key) {
                'OPEN' => [
                    ['JAGO', 2_000_000, 0, 'Setoran awal'],
                    ['CASH', 500_000, 0, 'Uang tunai di dompet'],
                    ['MODAL', 0, 2_500_000, 'Saldo awal pemilik'],
                ],
                'GAJI' => [
                    ['JAGO', $amount, 0, 'Gaji masuk ke rekening'],
                    ['GAJI', 0, $amount, 'Pendapatan gaji bulanan'],
                ],
                'MAKAN' => [
                    ['MAKAN', $amount, 0, 'Belanja makanan & minuman'],
                    ['JAGO', 0, $amount, 'Dibayar dari Jago'],
                ],
                'TOPUP' => [
                    ['GOPAY', 500_000, 0, 'Isi ulang saldo GoPay'],
                    ['JAGO', 0, 500_000, 'Top-up dari Jago'],
                ],
                'MAKAN2' => [
                    ['MAKAN', 350_000, 0, 'Makan di luar'],
                    ['GOPAY', 0, 350_000, 'Dibayar pakai GoPay'],
                ],
                'TRANSP' => [
                    ['TRANSPORT', 150_000, 0, 'Transportasi harian'],
                    ['GOPAY', 0, 150_000, 'Dibayar pakai GoPay'],
                ],
                'UTIL' => [
                    ['UTIL', 600_000, 0, 'Tagihan listrik & air'],
                    ['JAGO', 0, 600_000, 'Dibayar dari Jago'],
                ],
                'BELANJA' => [
                    ['BELANJA', $amount, 0, 'Belanja bulanan'],
                    ['JAGO', 0, $amount, 'Dibayar dari Jago'],
                ],
                'BRI_TRF' => [
                    ['BRI', 1_000_000, 0, 'Transfer masuk ke BRI'],
                    ['JAGO', 0, 1_000_000, 'Transfer dari Jago'],
                ],
                'HIBURAN' => [
                    ['HIBURAN', $amount, 0, 'Hiburan & rekreasi'],
                    ['JAGO', 0, $amount, 'Dibayar dari Jago'],
                ],
                'KES' => [
                    ['KESEHATAN', $amount, 0, 'Kesehatan & obat'],
                    ['JAGO', 0, $amount, 'Dibayar dari Jago'],
                ],
                'UTIL_DRAFT' => [
                    ['UTIL', 500_000, 0, 'Estimasi tagihan listrik'],
                    ['JAGO', 0, 500_000, 'Belum diposting'],
                ],
                default => [],
            };
        };

        foreach ([0, 1, 2] as $index) {
            $month = now()->startOfMonth()->subMonths(2)->addMonths($index);
            $prefix = 'JRN-DEMO-'.$month->format('Ymd');

            $keys = $index === 0 ? ['OPEN', 'GAJI', 'MAKAN'] : ['GAJI', 'MAKAN'];

            if ($index < 2) {
                array_push($keys, 'TOPUP', 'MAKAN2', 'TRANSP', 'UTIL', 'BELANJA', 'BRI_TRF', 'HIBURAN', 'KES');
            } else {
                $keys[] = 'UTIL_DRAFT';
            }

            foreach ($keys as $key) {
                $journal = Journal::where('reference', $prefix.'-'.$key)->first();

                if (! $journal) {
                    continue;
                }

                foreach ($linesFor($key, $amountFor($key, $index)) as [$code, $debit, $credit, $description]) {
                    $accountId = Account::where('code', $code)->value('id');

                    if (! $accountId) {
                        continue;
                    }

                    $journal->lines()->firstOrCreate([
                        'account_id' => $accountId,
                        'debit' => $debit,
                        'credit' => $credit,
                        'description' => $description,
                    ]);
                }
            }
        }
    }
}
