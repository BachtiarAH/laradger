<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            // The assistant turn runs on the queue so the user can say what they
            // want and walk away. This is how they find out it finished.
            $table->string('status', 20)->default('idle')->index();
            $table->text('error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn(['status', 'error', 'queued_at', 'started_at', 'completed_at']);
        });
    }
};
