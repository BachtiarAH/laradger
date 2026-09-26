<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->string('title')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Child of ai_conversations and deliberately has no tenant_id: it is
        // reached through the parent, which already carries the tenant scope.
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ai_conversation_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('role', 20);
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable();
            // Required to replay history: an assistant turn carrying tool_calls
            // must be followed by one `tool` turn per call, or the provider
            // rejects the request as malformed.
            $table->string('tool_call_id')->nullable();
            $table->timestamps();

            $table->foreign('ai_conversation_id')->references('id')->on('ai_conversations')->cascadeOnDelete();
        });

        // Also a child of ai_conversations. `tool` is the allow-listed action
        // name; `payload` is exactly what will be sent to the real endpoint when
        // the user approves, so the review UI cannot disagree with reality.
        Schema::create('ai_action_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ai_conversation_id')->index();
            $table->uuid('ai_message_id')->nullable()->index();
            $table->uuid('user_id')->index();
            $table->string('tool', 100);
            $table->string('kind', 20);
            $table->string('title');
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->foreign('ai_conversation_id')->references('id')->on('ai_conversations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_action_drafts');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
