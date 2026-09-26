<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A drafting request is a prompt submitted to be worked on in the
        // background. It is deliberately separate from a chat conversation:
        // chatting is synchronous and conversational, drafting is fire-and-
        // forget, and the user tracks each submitted prompt here.
        Schema::create('ai_draft_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            // The conversation the turn ran in, so the messages and drafts it
            // produced have somewhere to live.
            $table->uuid('ai_conversation_id')->nullable()->index();
            $table->text('prompt');
            $table->string('status', 20)->default('queued')->index();
            $table->text('error')->nullable();
            $table->unsignedInteger('drafts_count')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('ai_conversation_id')->references('id')->on('ai_conversations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_draft_requests');
    }
};
