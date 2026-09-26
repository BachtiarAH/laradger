<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_action_draft_dependencies', function (Blueprint $table): void {
            // Composite key, no surrogate id: a pivot row is the pairing itself,
            // and naming the same tag twice is still one dependency. A uuid here
            // would also need a whole pivot model just to be filled in.
            //
            // draft_id     — the draft that cannot run on its own (the journal)
            // depends_on_id — the draft it needs first (the tag or account)
            $table->uuid('draft_id');
            $table->uuid('depends_on_id');

            $table->timestamps();

            $table->primary(['draft_id', 'depends_on_id']);
            // Reverse lookups: "which drafts break if I discard this tag?"
            $table->index('depends_on_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_action_draft_dependencies');
    }
};
