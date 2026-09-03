<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Terminated => 'Terminated',
        };
    }

    public function isBlocked(): bool
    {
        return $this !== self::Active;
    }
}
