<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Custom endpoint for the `openai_compatible` provider, which is the
            // escape hatch for any OpenAI-API-compatible gateway (Ollama, vLLM,
            // LM Studio, OpenRouter, Gemini's compat path, ...). Without these the
            // provider is only usable by editing the environment.
            //
            // Like ai_api_key, they only apply to the provider the user selected.
            // Neither is a secret, so neither is encrypted and both are returned
            // to the client as the effective value.
            $table->string('ai_base_uri')->nullable();
            $table->string('ai_endpoint')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ai_base_uri', 'ai_endpoint']);
        });
    }
};
