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
        Schema::table('journals', function (Blueprint $table) {
            $table->uuid('allocation_id')->nullable()->after('reverse_from_id');
            $table->uuid('goal_id')->nullable()->after('allocation_id');

            $table->index(['tenant_id', 'allocation_id']);
            $table->index(['tenant_id', 'goal_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'allocation_id']);
            $table->dropIndex(['tenant_id', 'goal_id']);
            $table->dropColumn(['allocation_id', 'goal_id']);
        });
    }
};
