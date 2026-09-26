<?php

namespace App\Services\Ai\Tools;

enum AiToolKind: string
{
    /** Safe to run immediately: the model needs the answer to think. */
    case Read = 'read';

    /**
     * Never run on the model's say-so. Persisted as a pending draft and only
     * executed after the user reviews and confirms it.
     */
    case Write = 'write';

    /**
     * Runs immediately and changes nothing: the model declaring that it
     * deliberately proposed nothing, and why.
     *
     * Separate from Read because a read is the model gathering facts and this is
     * the model reporting a decision, and from Write because it must never become
     * a draft — there is nothing for a user to approve about "I did not do
     * anything", and a review card for it would be noise.
     *
     * It exists because "no drafts" was otherwise indistinguishable from failure:
     * a turn that correctly declined to double-book a transaction looked exactly
     * like a turn that gave up, and the UI said so.
     */
    case Outcome = 'outcome';

    public function requiresApproval(): bool
    {
        return $this === self::Write;
    }
}
