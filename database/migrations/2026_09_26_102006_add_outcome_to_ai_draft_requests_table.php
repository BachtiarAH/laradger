<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_draft_requests', function (Blueprint $table): void {
            // A turn that proposed nothing used to be indistinguishable from a
            // turn that failed: both were a completed request with zero drafts, and
            // both told the user to go ask the assistant. These three columns let
            // the model say which one happened, so the UI can report a decision
            // instead of an error.
            $table->string('outcome')->nullable()->after('drafts_count');
            $table->text('outcome_reason')->nullable()->after('outcome');
            // The entry that already exists. Without it, "we did not double-book
            // this" is true but useless: the user still has to go find it.
            $table->string('outcome_reference')->nullable()->after('outcome_reason');
        });
    }

    public function down(): void
    {
        Schema::table('ai_draft_requests', function (Blueprint $table): void {
            $table->dropColumn(['outcome', 'outcome_reason', 'outcome_reference']);
        });
    }
};
