<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Journal;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class AuditLogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenantId = TenantContext::id();
        $user = User::query()->where('email', 'test@example.com')->first();

        if (! $user) {
            return;
        }

        // The most recent salary journal is a nice anchor for journal audit rows.
        $journal = Journal::query()
            ->where('reference', 'like', 'JRN-DEMO-%-GAJI')
            ->latest('transaction_date')
            ->first();

        $auditLogs = [
            [
                'action' => 'journal.created',
                'before' => null,
                'after' => ['status' => 'posted'],
                'reason' => 'Gaji bulanan dicatat dari template',
            ],
            [
                'action' => 'journal.posted',
                'before' => ['status' => 'draft'],
                'after' => ['status' => 'posted'],
                'reason' => 'Journal disetujui dan diposting ke ledger',
            ],
            [
                'action' => 'journal.updated',
                'before' => ['description' => 'Penerimaan gaji'],
                'after' => ['description' => 'Gaji bulan ini'],
                'reason' => 'Perbaikan deskripsi journal',
            ],
        ];

        foreach ($auditLogs as $logData) {
            AuditLog::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'action' => $logData['action'],
                    'reason' => $logData['reason'],
                ],
                [...$logData, 'journal_id' => $journal?->id],
            );
        }
    }
}
