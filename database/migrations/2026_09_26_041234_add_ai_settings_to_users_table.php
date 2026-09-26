<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Personal AI provider selection. NULL means "fall back to the
            // environment configuration in config/ai.php".
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            // Stored through the model's 'encrypted' cast (APP_KEY), never
            // returned by the API. ai_api_key_hint holds the last characters
            // so the UI can show which key is configured without exposing it.
            $table->text('ai_api_key')->nullable();
            $table->string('ai_api_key_hint', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'ai_model', 'ai_api_key', 'ai_api_key_hint']);
        });
    }
};
