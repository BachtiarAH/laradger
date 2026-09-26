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

    public function requiresApproval(): bool
    {
        return $this === self::Write;
    }
}
