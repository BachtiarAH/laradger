<?php

namespace App\Tenancy;

use App\Models\Tenant;

class TenantContext
{
    private static ?Tenant $current = null;

    public static function set(?Tenant $tenant): void
    {
        static::$current = $tenant;
    }

    public static function current(): ?Tenant
    {
        return static::$current;
    }

    public static function id(): ?string
    {
        return static::$current?->id;
    }

    public static function hasTenant(): bool
    {
        return static::$current !== null;
    }

    public static function forget(): void
    {
        static::$current = null;
    }
}
