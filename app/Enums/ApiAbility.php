<?php

namespace App\Enums;

/**
 * What an issued API key is allowed to do.
 *
 * A key is granted a set of these on issue and can never gain another, so the
 * list is the whole of its power. Sanctum's own `*` wildcard is deliberately
 * not offered here: it is what `createToken()` falls back to for a plain login,
 * and handing it out from an issuance command would make "restricted key" and
 * "unrestricted key" the same request.
 *
 * Abilities are `area:tier`. The area says *which* part of the application, the
 * tier says *how far* inside it — and the two axes never touch. See `ApiArea` for
 * why the areas are independent rather than nested.
 */
enum ApiAbility: string
{
    /** Reading the ledger: journals, accounts, lines, expenses, analytics. */
    case LedgerRead = 'ledger:read';

    /**
     * Creating and changing ledger data.
     *
     * Deliberately excludes deletion. Removing a record and correcting one are
     * different acts, and an automation that can post a journal has no business
     * also being able to erase the accounts that journal refers to.
     */
    case LedgerWrite = 'ledger:write';

    /**
     * Deleting ledger records, plus the state transitions that cannot be walked
     * back from the API — reversing a posted journal.
     */
    case LedgerDestructive = 'ledger:destructive';

    /** Reading allocations, goals, and budgets. */
    case PlanningRead = 'planning:read';

    /** Creating and changing allocations, goals, and budgets. */
    case PlanningWrite = 'planning:write';

    /** Deleting them, plus cancelling an allocation, which has no undo. */
    case PlanningDestructive = 'planning:destructive';

    /** Reading tags and journal templates. */
    case LibraryRead = 'library:read';

    /** Creating and changing tags and journal templates. */
    case LibraryWrite = 'library:write';

    /** Deleting them. */
    case LibraryDestructive = 'library:destructive';

    /**
     * Reading the audit log.
     *
     * Its own ability rather than part of `ledger:read` because a row carries
     * `user_id` plus full `before` and `after` images — so a read-only
     * integration would otherwise see every change every colleague ever made,
     * including allocation targets and goals that were never published. Reading
     * a number is not reading the change history behind it.
     */
    case AuditRead = 'audit:read';

    /** Platform administration: managing staff accounts. Never tenant data. */
    case PlatformAdmin = 'platform:admin';

    /**
     * Every ability name, for error messages and command help.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $ability): string => $ability->value, self::cases());
    }

    /**
     * Resolve a user-supplied ability name, rejecting unknown ones.
     *
     * Issuance is the only place an ability name comes from outside code, and an
     * unrecognised name must fail loudly. Silently dropping it would issue a key
     * that looks more capable than it is, which is the failure that gets noticed
     * last.
     */
    public static function parse(string $name): ?self
    {
        return self::tryFrom(strtolower(trim($name)));
    }

    /**
     * The part of the application this ability governs.
     *
     * Derived from the name rather than repeated on each case, so the two can
     * never disagree about where an ability points.
     */
    public function area(): ApiArea
    {
        [$area] = explode(':', $this->value, 2);

        return ApiArea::from($area);
    }

    /**
     * The tier within the area: `read`, `write`, or `destructive`.
     */
    public function tier(): string
    {
        [, $tier] = explode(':', $this->value, 2);

        return $tier;
    }

    /**
     * The stored abilities that grant this one.
     *
     * The tiers are cumulative *within an area*, and the order is the argument
     * for it: a key that can cancel an allocation can certainly create one, and a
     * key that can create one can certainly read one. Without that, every read
     * would have to be spelled as a second ability at issuance and a caller would
     * have to know the hierarchy to ask for the access it actually needs.
     *
     * Nothing from another area appears here, and that absence is the point: a
     * `ledger:destructive` key cannot read budgets, because budgets are not
     * ledger data. `*` is absent for the same reason Sanctum answers it before
     * this is ever consulted.
     *
     * @return array<int, string>
     */
    public function grantedBy(): array
    {
        $area = $this->area();
        $tiers = $area->tiers();

        // Everything in this area from this tier upwards.
        $from = array_search($this->tier(), $tiers, true);

        if ($from === false) {
            return [$this->value];
        }

        return array_map(
            static fn (string $tier): string => $area->ability($tier),
            array_slice($tiers, $from),
        );
    }

    /**
     * Whether holding this ability already grants $other, making $other
     * redundant to store alongside it.
     *
     * The direction is the whole question: `grantedBy()` lists what satisfies
     * *one* requirement, so the answer is whether this ability's own name
     * appears in the other requirement's list. Reading it the other way round
     * inverts the hierarchy and collapses a key down to its narrowest ability,
     * which is the one mistake here that would silently hand out less access
     * than the issuer asked for.
     */
    public function covers(self $other): bool
    {
        return $this !== $other && in_array($this->value, $other->grantedBy(), true);
    }
}
