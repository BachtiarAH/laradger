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
        Schema::create('journal_template_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('journal_template_id');
            $table->uuid('account_id');
            $table->unsignedInteger('line_number')->default(0);
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->foreign('journal_template_id')->references('id')->on('journal_templates')->onDelete('cascade');
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('restrict');
            $table->index('line_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_template_lines');
    }
};
