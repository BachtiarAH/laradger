<?php

namespace App\Services\ApiKeys;

use App\Enums\ApiAbility;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

/**
 * Mints scoped, expiring API keys.
 *
 * Every rule that decides what a key may become lives here rather than in the
 * console command, so the same guarantees hold no matter who calls it later.
 * Three of them are the reason this is a service and not three lines in
 * `handle()`:
 *
 * - A key must expire. `createToken()` accepts null and would then mint a
 *   credential valid forever, which for an unattended client is the same shape
 *   as a leaked one.
 * - A key must expire *soon*. The ceiling stops a caller from asking for a
 *   lifetime longer than the rotation policy it is being issued under.
 * - A key must be narrowed. `ledger:destructive` already grants read and write,
 *   so storing all three would hand back a key list that reads as more powerful
 *   than it is, and invites a later edit that "adds" an ability it already had.
 */
class ApiKeyIssuer
{
    /**
     * @throws RuntimeException when no usable ability was requested.
     */
    public function issue(
        User $user,
        array $abilities,
        ?int $days = null,
        ?string $label = null,
    ): NewAccessToken {
        $abilities = $this->normalize($abilities);

        if ($abilities === []) {
            throw new RuntimeException('An API key must be granted at least one ability.');
        }

        $expiresAt = $this->expiry($days);

        return $user->createToken(
            $this->name($user, $label),
            $abilities,
            $expiresAt,
        );
    }

    /**
     * Validate, deduplicate, and collapse the requested abilities.
     *
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     *
     * @throws RuntimeException on an unrecognised ability name.
     */
    public function normalize(array $abilities): array
    {
        $resolved = [];

        foreach ($abilities as $ability) {
            $parsed = ApiAbility::parse((string) $ability);

            if ($parsed === null) {
                throw new RuntimeException(sprintf(
                    "'%s' is not a known ability. Valid abilities: %s.",
                    $ability,
                    implode(', ', ApiAbility::names()),
                ));
            }

            $resolved[$parsed->value] = $parsed;
        }

        $collapsed = [];

        foreach ($resolved as $ability) {
            // Keep the ability only if no other requested ability already grants
            // it, preferring the widest one when two are mutually redundant.
            $isRedundant = false;

            foreach ($resolved as $other) {
                if ($other->covers($ability)) {
                    $isRedundant = true;

                    break;
                }
            }

            if (! $isRedundant) {
                $collapsed[] = $ability->value;
            }
        }

        return array_values(array_unique($collapsed));
    }

    /**
     * @throws RuntimeException when the requested lifetime is out of range.
     */
    private function expiry(?int $days): Carbon
    {
        $default = (int) config('api-keys.default_days');
        $max = (int) config('api-keys.max_days');

        $days ??= $default;

        if ($days < 1) {
            throw new RuntimeException('An API key must be valid for at least one day.');
        }

        if ($days > $max) {
            throw new RuntimeException("An API key cannot be valid for more than {$max} days.");
        }

        return Carbon::now()->addDays($days);
    }

    private function name(User $user, ?string $label): string
    {
        $prefix = (string) config('api-keys.name_prefix');

        $label = $label !== null ? trim($label) : '';

        if ($label === '') {
            $label = Carbon::now()->format('Y-m-d H:i');
        }

        return $prefix.$user->email.' '.$label;
    }
}
