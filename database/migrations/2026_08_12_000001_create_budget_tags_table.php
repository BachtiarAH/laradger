<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_tags', function (Blueprint $table) {
            $table->uuid('budget_id');
            $table->uuid('tag_id');
            $table->timestamps();

            $table->foreign('budget_id')->references('id')->on('budgets')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
            $table->primary(['budget_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_tags');
    }
};
