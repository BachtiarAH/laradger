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
        Schema::table('allocations', function (Blueprint $table) {
            $table->string('type')->default('recurring')->after('target_amount');
            $table->string('period_type')->default('monthly')->after('type');
            $table->date('starts_at')->nullable()->after('period_type');
            $table->date('ends_at')->nullable()->after('starts_at');
            $table->string('roll_forward_mode')->default('reset')->after('ends_at');
            $table->decimal('carry_over_amount', 20, 2)->default(0)->after('roll_forward_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'period_type',
                'starts_at',
                'ends_at',
                'roll_forward_mode',
                'carry_over_amount',
            ]);
        });
    }
};
