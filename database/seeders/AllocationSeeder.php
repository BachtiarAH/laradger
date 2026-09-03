<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class AllocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds a few savings goals and reserves part of the posted Jago/BRI
     * balances for them (allocations never touch the ledger). The seeded
     * amounts stay safely below each account's available balance.
     */
    public function run(): void
    {
        $tenantId = TenantContext::id();
        $user = User::query()->where('email', 'test@example.com')->first();

        if (! $user) {
            return;
        }

        $funds = [
            [
                'name' => 'Dana Darurat',
                'description' => 'Cadangan 3–6 bulan pengeluaran.',
                'target_amount' => 15_000_000,
                'reservations' => [
                    ['JAGO', 3_000_000],
                    ['BRI', 1_000_000],
                ],
            ],
            [
                'name' => 'Laptop',
                'description' => 'Tabungan ganti laptop.',
                'target_amount' => 20_000_000,
                'reservations' => [
                    ['JAGO', 2_500_000],
                ],
            ],
            [
                'name' => 'Liburan',
                'description' => 'Liburan akhir tahun.',
                'target_amount' => 10_000_000,
                'reservations' => [
                    ['JAGO', 1_500_000],
                ],
            ],
            [
                'name' => 'Pernikahan',
                'description' => 'Dana pernikahan — belum dialokasikan.',
                'target_amount' => 50_000_000,
                'reservations' => [],
            ],
        ];

        foreach ($funds as $data) {
            $reservations = $data['reservations'];
            unset($data['reservations']);

            $allocation = Allocation::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $data['name']],
                $data,
            );

            $accountIds = collect($reservations)
                ->mapWithKeys(function (array $reservation) {
                    $accountId = Account::where('code', $reservation[0])->value('id');

                    return $accountId ? [$accountId => ['amount' => $reservation[1]]] : [];
                })
                ->all();

            $allocation->accounts()->syncWithoutDetaching($accountIds);

            AuditLog::firstOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => $user->id, 'action' => 'allocation.created', 'reason' => 'Demo seed: '.$data['name']],
                [
                    'user_id' => $user->id,
                    'action' => 'allocation.created',
                    'after' => [
                        'name' => $data['name'],
                        'target_amount' => number_format((float) $data['target_amount'], 2, '.', ''),
                    ],
                    'reason' => 'Demo seed: '.$data['name'],
                ],
            );
        }
    }
}
