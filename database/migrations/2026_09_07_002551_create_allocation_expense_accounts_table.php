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
        Schema::create('allocation_expense_accounts', function (Blueprint $table) {
            $table->uuid('allocation_id');
            $table->uuid('account_id');
            $table->timestamps();

            $table->foreign('allocation_id')->references('id')->on('allocations')->onDelete('cascade');
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->primary(['allocation_id', 'account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('allocation_expense_accounts');
    }
};
