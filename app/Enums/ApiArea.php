<?php

namespace App\Enums;

/**
 * The independent areas an API key can be granted access to.
 *
 * Areas are deliberately **not** nested. A key that can read budgets cannot
 * thereby read journals, because those are separate things an integration might
 * genuinely want separately — a reporting script has no reason to see every
 * journal entry in the organisation, and being unable to grant that separation
 * means the only safe choice is a blanket `ledger:read` that leaks the ledger to
 * something that never asked for it.
 *
 * Within one area the tiers still climb (`read` → `write` → `destructive`);
 * across areas they do not. That distinction is the whole design: narrowing
 * within an area is a convenience, narrowing across areas is the security
 * boundary.
 */
enum ApiArea: string
{
    /** Money itself: journals, accounts, journal lines, expenses, AI drafting. */
    case Ledger = 'ledger';

    /** Intent behind the money: allocations, goals, budgets. */
    case Planning = 'planning';

    /** Reusable definitions built on top: tags and journal templates. */
    case Library = 'library';

    /** Who changed what, and from what to what. */
    case Audit = 'audit';

    /** Staff account management. Not tenant data. */
    case Platform = 'platform';

    /**
     * The tiers available in this area, narrowest first.
     *
     * `Audit` and `Platform` hold only their single tier because nothing in them
     * is created or destroyed through the API — the audit log is written by the
     * application, and platform accounts are managed by existing staff.
     *
     * @return array<int, string>
     */
    public function tiers(): array
    {
        return match ($this) {
            self::Ledger, self::Planning, self::Library => ['read', 'write', 'destructive'],
            self::Audit, self::Platform => ['read'],
        };
    }

    /**
     * Whether this area has the given tier.
     */
    public function has(string $tier): bool
    {
        return in_array($tier, $this->tiers(), true);
    }

    /**
     * The ability name for a tier, e.g. `ledger:read`.
     *
     * `platform` is the one area whose single ability is not named after its
     * tier: managing staff accounts *is* the whole of what it grants, so calling
     * it `platform:admin` says what it is instead of restating the area name.
     * Mapping that here rather than at each call site keeps the middleware from
     * building `platform:read`, which no such ability would ever answer.
     */
    public function ability(string $tier): string
    {
        return match ($this) {
            self::Platform => 'platform:admin',
            default => "{$this->value}:{$tier}",
        };
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $area): string => $area->value, self::cases());
    }
}
