<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->string('budget_type')->default('expense')->after('amount');
            $table->index(['tenant_id', 'budget_type', 'starts_at']);
        });

        // Backfill existing rows that may have NULL due to default not applied in some DBs
        DB::table('budgets')->whereNull('budget_type')->update(['budget_type' => 'expense']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'budget_type', 'starts_at']);
            $table->dropColumn('budget_type');
        });
    }
};
