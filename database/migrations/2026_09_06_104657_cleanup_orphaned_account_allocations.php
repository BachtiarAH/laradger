<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove orphaned reservations belonging to soft-deleted allocations or accounts.
        DB::table('account_allocations')
            ->whereIn('allocation_id', function ($query) {
                $query->select('id')
                    ->from('allocations')
                    ->whereNotNull('deleted_at');
            })
            ->orWhereIn('account_id', function ($query) {
                $query->select('id')
                    ->from('accounts')
                    ->whereNotNull('deleted_at');
            })
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cleanup migration is irreversible since purged pivot rows are not retained.
    }
};
