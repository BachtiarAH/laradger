<?php

namespace App\Tenancy;

use App\Models\Tenant;

class TenantContext
{
    private static ?Tenant $current = null;

    private static bool $systemContext = false;

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

    public static function isSystemContext(): bool
    {
        return static::$systemContext;
    }

    public static function enableSystemContext(): void
    {
        static::$systemContext = true;
    }

    public static function disableSystemContext(): void
    {
        static::$systemContext = false;
    }

    /**
     * Run a callback with explicit system context (bypasses fail-closed tenant scope).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runInSystemContext(callable $callback): mixed
    {
        $previous = static::$systemContext;
        static::$systemContext = true;

        try {
            return $callback();
        } finally {
            static::$systemContext = $previous;
        }
    }

    public static function forget(): void
    {
        static::$current = null;
    }

    public static function flush(): void
    {
        static::$current = null;
        static::$systemContext = false;
    }
}
