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
        $user = User::first('id');
        $journal = Journal::first('id');

        $auditLogs = [
            [
                'action' => 'journal.created',
                'before' => null,
                'after' => ['status' => 'posted'],
                'reason' => 'Initial journal entry recorded',
            ],
            [
                'action' => 'journal.posted',
                'before' => ['status' => 'draft'],
                'after' => ['status' => 'posted'],
                'reason' => 'Journal approved and posted to ledger',
            ],
            [
                'action' => 'journal.updated',
                'before' => ['description' => 'Monthly rent'],
                'after' => ['description' => 'Monthly rent payment'],
                'reason' => 'Corrected journal description',
            ],
            [
                'action' => 'journal.archived',
                'before' => ['status' => 'posted'],
                'after' => ['status' => 'archived'],
                'reason' => 'Historical entry archived after reconciliation',
            ],
        ];

        foreach ($auditLogs as $logData) {
            AuditLog::firstOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => $user->id, 'action' => $logData['action']],
                [...$logData, 'user_id' => $user->id, 'journal_id' => $journal?->id],
            );
        }
    }
}
