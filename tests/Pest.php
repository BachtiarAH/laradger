<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests get the application container (config, Http fake) but no database,
// so a pure unit test does not pay for a transaction per test.
pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function createTenantForUser(User $user): Tenant
{
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($user, ['role' => 'owner']);

    return $tenant;
}

/**
 * Mint a real bearer token for a user.
 *
 * These tests deliberately do not use `Sanctum::actingAs()`. That helper stands
 * in a token whose abilities are stubbed expectations rather than stored data,
 * so it cannot represent a restricted key at all. A key is the thing under test
 * here, so it goes through the real token lookup.
 */
function apiKeyFor(User $user, array $abilities, ?int $days = 30): string
{
    return $user->createToken('apikey:test', $abilities, now()->addDays($days))->plainTextToken;
}

/**
 * Present an API key, discarding any credential resolved earlier in the test.
 *
 * `withToken()` only rewrites a default header. The auth manager caches the user
 * it resolved on the first guarded request, and Sanctum asks that cache rather
 * than re-reading the header, so a second `withToken()` in the same test keeps
 * authenticating as the *first* key. Forgetting the guards is what makes a
 * credential switch observable.
 *
 * This is a test-harness artefact, not product behaviour: in production each
 * request gets a fresh container, so a new header is a new identity. Several
 * tests assert a denial and then a success with a wider key on the same
 * endpoint, which is exactly the pairing that would silently pass against the
 * wrong key.
 */
function asApiKey(string $token): Illuminate\Foundation\Testing\TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

/**
 * A minimally valid account payload, for tests that only need a creatable record.
 */
function validAccountPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Petty Cash',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ], $overrides);
}
