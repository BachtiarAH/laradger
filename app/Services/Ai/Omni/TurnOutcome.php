<?php

namespace App\Services\Ai\Omni;

/**
 * How a turn ended when it deliberately proposed nothing.
 *
 * A turn that creates drafts needs no outcome: `drafts_count` already says so.
 * This exists for the other case, which used to be indistinguishable from a
 * failure — a completed turn with zero drafts could mean the model correctly
 * declined to double-book a transaction, or could mean it gave up halfway. The
 * queued path showed both as "no drafts were made, ask the assistant", which sent
 * the user to ask about something the assistant had already answered.
 *
 * Reported by the model through `record_no_action`, then stamped onto
 * `ai_draft_requests` by the job — the same arrangement as drafts, because the
 * assistant is shared with the chat path and has no request to write to.
 */
enum TurnOutcome: string
{
    /**
     * The ledger already holds this transaction and a second entry was
     * deliberately not created. Always carries the existing entry's reference.
     */
    case AlreadyRecorded = 'already_recorded';

    /** The request contained nothing bookable — a question, or no transaction. */
    case NothingToRecord = 'nothing_to_record';

    /**
     * The model could not proceed and the user has to decide. The only outcome
     * that is allowed to be a dead end, because the user is the one who can
     * resolve it.
     */
    case NeedsAttention = 'needs_attention';

    /**
     * A reference is what makes this outcome actionable: it is how the user finds
     * the entry that already exists instead of being told to go look for it.
     */
    public function requiresReference(): bool
    {
        return $this === self::AlreadyRecorded;
    }
}
