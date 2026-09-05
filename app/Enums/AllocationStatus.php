<?php

namespace App\Enums;

enum AllocationStatus: string
{
    case Active = 'active';
    case Upcoming = 'upcoming';
    case Fulfilled = 'fulfilled';
    case Skipped = 'skipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether this status contributes to allocated totals / Safe Money.
     */
    public function countsTowardsAllocated(): bool
    {
        return in_array($this, [self::Active, self::Upcoming], true);
    }
}
