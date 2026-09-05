<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Business records are archived (soft-deleted) instead of physically
     * removed so foreign-key references and audit history stay intact.
     * Pivot tables (journal_tags, journal_template_tags, budget_accounts,
     * account_allocations) are excluded — they only map parents to children
     * and are never soft-deleted themselves.
     */
    public function up(): void
    {
        foreach (['accounts', 'allocations', 'budgets', 'journals', 'journal_lines', 'tags', 'journal_templates', 'journal_template_lines'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['accounts', 'allocations', 'budgets', 'journals', 'journal_lines', 'tags', 'journal_templates', 'journal_template_lines'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
