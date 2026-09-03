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
        Schema::create('account_allocations', function (Blueprint $table) {
            $table->uuid('allocation_id');
            $table->uuid('account_id');
            $table->decimal('amount', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('allocation_id')->references('id')->on('allocations')->onDelete('cascade');
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('restrict');
            $table->primary(['allocation_id', 'account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_allocations');
    }
};
