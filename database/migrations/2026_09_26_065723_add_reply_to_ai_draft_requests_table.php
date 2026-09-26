<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_draft_requests', function (Blueprint $table) {
            // The assistant's answer to the prompt. A fire-and-forget request
            // still needs somewhere to put a question it cannot ask in person,
            // otherwise the turn looks like a silent failure.
            $table->text('reply')->nullable()->after('prompt');
        });
    }

    public function down(): void
    {
        Schema::table('ai_draft_requests', function (Blueprint $table) {
            $table->dropColumn('reply');
        });
    }
};
