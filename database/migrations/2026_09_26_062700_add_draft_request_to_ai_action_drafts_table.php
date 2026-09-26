<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_action_drafts', function (Blueprint $table) {
            // Which submitted prompt produced this draft. Nullable: a draft can
            // also come from an interactive chat turn.
            $table->uuid('ai_draft_request_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_action_drafts', function (Blueprint $table) {
            $table->dropColumn('ai_draft_request_id');
        });
    }
};
