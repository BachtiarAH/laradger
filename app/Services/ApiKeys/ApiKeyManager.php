<?php

namespace App\Services\ApiKeys;

use App\Models\User;
use Illuminate\Support\Collection;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-user management of issued API keys.
 *
 * Scoped to a user rather than a tenant on purpose: a key is a credential of the
 * *account*, and the same user reaches several tenants with one token. Scoping
 * keys per tenant would mean the key stops working the moment the automation
 * touches a second organization.
 *
 * Two rules are enforced here rather than in the controller, because they are
 * about credentials and not about request shape:
 *
 * - Only a person may mint. A bearer key that could issue another key could
 *   always issue one wider than itself, so the entire ability hierarchy would
 *   collapse to whatever a leaked read-only key decided to ask for.
 * - Listing and revoking stay open to keys. An agent holding a leaked key
 *   should be able to kill it without waiting for a human.
 */
class ApiKeyManager
{
    public function __construct(private readonly ApiKeyIssuer $issuer) {}

    /**
     * Every issued key for this user, newest first.
     *
     * Filtered by the configured name prefix, exactly as `api:revoke-key` does.
     * Abilities would be the wrong filter: the token the browser session is
     * currently using carries Sanctum's `*`, which says what it can do rather
     * than what it is for, and "revoke all" must never reach the credential
     * someone is logged in with.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    public function forUser(User $user): Collection
    {
        return $user->tokens()
            ->where('name', 'like', $this->prefix().'%')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Mint a key for this user. Session-authenticated callers only.
     *
     * @param  array<int, string>  $abilities
     *
     * @throws \RuntimeException on an unknown ability or an out-of-range lifetime
     */
    public function create(User $user, array $abilities, ?int $days, ?string $label): NewAccessToken
    {
        return $this->issuer->issue($user, $abilities, $days, $label);
    }

    /**
     * Revoke one of this user's keys.
     *
     * Scoped through `forUser()`, so a token id belonging to someone else is
     * indistinguishable from one that does not exist. Both are a 404: confirming
     * that an id exists on another account would leak the fact itself.
     *
     * @throws NotFoundHttpException when the id is not one of this user's keys
     */
    public function revoke(User $user, string $tokenId): void
    {
        $token = $this->forUser($user)->firstWhere('id', $tokenId);

        if ($token === null) {
            throw new NotFoundHttpException('API key not found.');
        }

        $token->delete();
    }

    /**
     * Whether this credential may issue further keys.
     *
     * The test is "is this credential an issued API key?", not "is this a
     * session?", and the two are not the same thing here. This app's web client
     * does not use Sanctum's cookie sessions at all — `AuthController` hands it a
     * `createToken('api-token')` bearer token which the SPA keeps in
     * `localStorage`. That credential is a `PersonalAccessToken`, exactly like a
     * key, so detecting it as a session by token type would refuse the real login
     * path while allowing the very credentials it was meant to exclude.
     *
     * The `apikey:` name prefix is the marker instead, and it is already the
     * project's answer to "is this an issued key?" — `api:revoke-key` filters on
     * the same prefix so that revoking keys cannot reach the token someone is
     * logged in with. Anything without that prefix is a person.
     */
    public function canIssue(?object $accessToken): bool
    {
        if ($accessToken instanceof TransientToken) {
            return true;
        }

        if (! $accessToken instanceof PersonalAccessToken) {
            // No resolvable credential (a route reached before auth ran).
            // `auth:sanctum` answers 401 for the real case; do not pre-empt it.
            return true;
        }

        return ! str_starts_with((string) $accessToken->name, $this->prefix());
    }

    private function prefix(): string
    {
        return (string) config('api-keys.name_prefix');
    }
}
