<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('journal_templates', function (Blueprint $table) {
            $table->uuid('allocation_id')->nullable()->after('last_run_at');
            $table->foreign('allocation_id')->references('id')->on('allocations')->nullOnDelete();
            $table->index(['tenant_id', 'allocation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_templates', function (Blueprint $table) {
            $table->dropForeign(['allocation_id']);
            $table->dropIndex(['tenant_id', 'allocation_id']);
            $table->dropColumn('allocation_id');
        });
    }
};
