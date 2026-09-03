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
        Schema::create('journal_template_tags', function (Blueprint $table) {
            $table->uuid('journal_template_id');
            $table->uuid('tag_id');
            $table->timestamps();

            $table->foreign('journal_template_id')->references('id')->on('journal_templates')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
            $table->primary(['journal_template_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_template_tags');
    }
};
