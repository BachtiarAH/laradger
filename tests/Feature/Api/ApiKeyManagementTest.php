<?php

use App\Enums\ApiAbility;
use App\Models\Account;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyIssuer;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
});

/**
 * The management endpoints a signed-in person uses.
 *
 * Reuses `apiKeyFor()` from ApiKeyTest, which mints a real bearer token. That
 * matters here: whether a request is a session or a key is exactly what these
 * routes decide on, so a stubbed acting-as would answer the wrong question.
 */
describe('listing', function () {
    test('a session sees its own keys', function () {
        apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);
        apiKeyFor($this->user, [ApiAbility::LedgerWrite->value]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/me/api-keys')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    test('keys are scoped to the user, not the tenant', function () {
        apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);

        // No tenant in the URL at all: the same user reaching a second
        // organization must not need a second set of keys.
        $this->actingAs($this->user)
            ->getJson('/api/v1/me/api-keys')
            ->assertOk()
            ->assertJsonPath('data.0.abilities', [ApiAbility::LedgerRead->value]);
    });

    test('another user\'s keys are never listed', function () {
        apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);

        $other = User::factory()->create();
        apiKeyFor($other, [ApiAbility::LedgerDestructive->value]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/me/api-keys')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.abilities', [ApiAbility::LedgerRead->value]);
    });

    test('the login token driving the session is not listed as a key', function () {
        $this->user->createToken('api-token')->plainTextToken;
        apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);

        // If the session token showed up here, "revoke everything" from the UI
        // would delete the credential the user is logged in with.
        $this->actingAs($this->user)
            ->getJson('/api/v1/me/api-keys')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    test('no response ever contains the secret or anything derived from it', function () {
        $token = apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);
        $secret = explode('|', $token)[1];

        $body = $this->actingAs($this->user)->getJson('/api/v1/me/api-keys')->assertOk()->content();

        expect($body)->not->toContain($secret);
    });

    test('a key can list keys, so an agent can see what it has', function () {
        apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);

        // Not forbidden: the ability tiers describe ledger access, and managing
        // credentials is not that. An agent should be able to audit its own.
        $this->withToken(apiKeyFor($this->user, [ApiAbility::LedgerRead->value]))
            ->getJson('/api/v1/me/api-keys')
            ->assertOk();
    });
});

describe('issuing', function () {
    test('a session can mint a key and sees the secret exactly once', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/api-keys', [
                'abilities' => [ApiAbility::LedgerWrite->value],
                'label' => 'nightly import',
            ])
            ->assertCreated();

        $secret = $response->json('data.plain_text_token');

        expect($secret)->toBeString()->not->toBeEmpty();

        // The stored column is a hash; the plaintext is unrecoverable afterwards.
        $stored = PersonalAccessToken::latest('id')->first();
        expect($stored->token)->not->toBe($secret);
    });

    test('the new key works and carries the requested abilities', function () {
        $secret = $this->actingAs($this->user)
            ->postJson('/api/v1/me/api-keys', ['abilities' => [ApiAbility::LedgerWrite->value]])
            ->assertCreated()
            ->json('data.plain_text_token');

        $this->withToken($secret)
            ->postJson("/api/v1/{$this->tenant->slug}/accounts", validAccountPayload())
            ->assertCreated();
    });

    test('redundant abilities are collapsed before storage', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/api-keys', [
                'abilities' => [
                    ApiAbility::LedgerDestructive->value,
                    ApiAbility::LedgerRead->value,
                    ApiAbility::LedgerWrite->value,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.key.abilities', [ApiAbility::LedgerDestructive->value]);
    });

    test('the key is named from the label, without the console prefix', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/api-keys', [
                'abilities' => [ApiAbility::LedgerRead->value],
                'label' => 'nightly import',
            ])
            ->assertCreated()
            ->assertJsonPath('data.key.label', 'nightly import');
    });

    test('an unknown ability is a 422 naming the field', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/api-keys', [
                'abilities' => [ApiAbility::LedgerRead->value, 'ledger:eat'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abilities.1');

        expect(PersonalAccessToken::count())->toBe(0);
    });

    test('at least one ability is required', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/api-keys', ['abilities' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abilities');
    });

    test('a lifetime beyond the ceiling is refused', function () {
        config()->set('api-keys.max_days', 30);

        $this->actingAs($this->user)
            ->postJson('/api/v1/me/api-keys', [
                'abilities' => [ApiAbility::LedgerRead->value],
                'days' => 365,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('days');

        expect(PersonalAccessToken::count())->toBe(0);
    });

    test('an issued key always has an expiry', function () {
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/api-keys', ['abilities' => [ApiAbility::LedgerRead->value]])
            ->assertCreated()
            ->assertJsonPath('data.key.expires_at', fn (?string $value): bool => $value !== null);
    });

    test('the web app\'s own login token can mint', function () {
        // The path the SPA actually takes. `AuthController` hands out a
        // `createToken('api-token')` bearer kept in localStorage — it is a
        // PersonalAccessToken, NOT a cookie session, so anything that detects
        // "a person" by looking for Sanctum's TransientToken refuses the real
        // login path. This test failed that way once already.
        $loginToken = $this->user->createToken('api-token')->plainTextToken;

        asApiKey($loginToken)
            ->postJson('/api/v1/me/api-keys', ['abilities' => [ApiAbility::LedgerRead->value]])
            ->assertCreated();
    });

    test('an unauthenticated request is rejected', function () {
        $this->postJson('/api/v1/me/api-keys', ['abilities' => [ApiAbility::LedgerRead->value]])
            ->assertUnauthorized();
    });
});

describe('a key cannot issue a key', function () {
    /**
     * The reason this whole page is session-gated.
     *
     * If a bearer key could mint keys, the ability hierarchy would only mean
     * something until the first key was leaked: that key could ask for
     * `platform:admin` and own the account. Every ability tier in the codebase
     * would then be a speed bump.
     */
    test('minting with a key is refused whatever it holds', function () {
        apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);

        $this->withToken(apiKeyFor($this->user, [ApiAbility::LedgerRead->value]))
            ->postJson('/api/v1/me/api-keys', ['abilities' => [ApiAbility::LedgerDestructive->value]])
            ->assertForbidden();
    });

    test('even the widest ledger key cannot escalate', function () {
        $this->withToken(apiKeyFor($this->user, [ApiAbility::LedgerDestructive->value]))
            ->postJson('/api/v1/me/api-keys', ['abilities' => [ApiAbility::PlatformAdmin->value]])
            ->assertForbidden();

        expect(PersonalAccessToken::count())->toBe(1);
    });

    test('a login token with a custom device name can still mint', function () {
        // `device_name` is how a real client names its token, so the refusal must
        // key off the `apikey:` prefix rather than off any particular login name.
        $token = $this->user->createToken('MacBook Pro')->plainTextToken;

        asApiKey($token)
            ->postJson('/api/v1/me/api-keys', ['abilities' => [ApiAbility::LedgerRead->value]])
            ->assertCreated();
    });

    test('a platform admin key cannot mint either', function () {
        $this->user->update(['is_admin' => true]);

        $this->withToken(apiKeyFor($this->user, [ApiAbility::PlatformAdmin->value]))
            ->postJson('/api/v1/me/api-keys', ['abilities' => [ApiAbility::LedgerDestructive->value]])
            ->assertForbidden();
    });

    test('a session still can, having only platform access to staff', function () {
        $this->user->update(['is_admin' => true]);

        // Being an admin is not a ledger ability, so the ability middleware
        // cannot be what gates this — hence the check lives in the controller.
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/api-keys', ['abilities' => [ApiAbility::LedgerRead->value]])
            ->assertCreated();
    });
});

describe('revoking', function () {
    test('a session revokes a key and it stops working', function () {
        $secret = apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);
        $id = PersonalAccessToken::latest('id')->first()->id;

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/me/api-keys/{$id}")
            ->assertNoContent();

        expect(PersonalAccessToken::count())->toBe(0);

        // asApiKey, not withToken: the revoke above ran as a session, and the
        // auth manager would otherwise still be holding that identity.
        asApiKey($secret)
            ->getJson("/api/v1/{$this->tenant->slug}/accounts")
            ->assertUnauthorized();
    });

    test('a key can revoke a key, so a leak can be killed unattended', function () {
        $victimId = $this->user->createToken('apikey:other', [ApiAbility::LedgerWrite->value])->accessToken->id;

        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerRead->value]))
            ->deleteJson("/api/v1/me/api-keys/{$victimId}")
            ->assertNoContent();

        expect(PersonalAccessToken::count())->toBe(1);
    });

    test('a key cannot revoke the session token', function () {
        $this->user->createToken('api-token')->plainTextToken;

        // The login token carries no `apikey:` prefix, so it is not reachable
        // through this endpoint at all.
        $loginTokenId = PersonalAccessToken::where('name', 'api-token')->first()->id;

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/me/api-keys/{$loginTokenId}")
            ->assertNotFound();

        expect(PersonalAccessToken::count())->toBe(1);
    });

    test('another user\'s key id is a 404, not a 403', function () {
        $other = User::factory()->create();
        apiKeyFor($other, [ApiAbility::LedgerRead->value]);

        $foreignId = PersonalAccessToken::latest('id')->first()->id;

        // A 403 would confirm the id exists on another account. 404 says nothing.
        $this->actingAs($this->user)
            ->deleteJson("/api/v1/me/api-keys/{$foreignId}")
            ->assertNotFound();

        expect(PersonalAccessToken::count())->toBe(1);
    });

    test('an unknown id is a 404', function () {
        $this->actingAs($this->user)
            ->deleteJson('/api/v1/me/api-keys/does-not-exist')
            ->assertNotFound();
    });

    test('an unauthenticated request is rejected', function () {
        $this->deleteJson('/api/v1/me/api-keys/1')->assertUnauthorized();
    });
});

describe('the ability hierarchy holds through the middleware', function () {
    /**
     * ApiAbility::covers() answers "does this ability already grant that one",
     * and reading it the other way round inverts the hierarchy — which would
     * quietly reduce a destructive key to a read key. The enum's own tests
     * cannot catch that, because the mistake lives in how EnsureApiAbility
     * consults it, so it is pinned here against real requests.
     */
    test('a destructive key writes and reads without holding those abilities', function () {
        $key = apiKeyFor($this->user, [ApiAbility::LedgerDestructive->value]);

        $this->withToken($key)
            ->postJson("/api/v1/{$this->tenant->slug}/accounts", validAccountPayload())
            ->assertCreated();

        $this->withToken($key)
            ->getJson("/api/v1/{$this->tenant->slug}/accounts")
            ->assertOk();
    });

    test('a write key cannot delete, so the tiers are not all equivalent', function () {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->withToken(apiKeyFor($this->user, [ApiAbility::LedgerWrite->value]))
            ->deleteJson("/api/v1/{$this->tenant->slug}/accounts/{$account->id}")
            ->assertForbidden();
    });
});

describe('the page agrees with the console', function () {
    test('a key issued by artisan behaves like one issued by the page', function () {
        $this->artisan('api:issue-key', [
            'email' => $this->user->email,
            '--ability' => [ApiAbility::LedgerRead->value],
            '--label' => 'from console',
        ])->assertSuccessful();

        // Same issuer, so the page must see it — otherwise a key created by an
        // operator would be invisible and un-revocable from the UI.
        $this->actingAs($this->user)
            ->getJson('/api/v1/me/api-keys')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'from console');
    });

    test('the issuer collapses abilities for both callers', function () {
        expect(app(ApiKeyIssuer::class)->normalize([
            ApiAbility::LedgerWrite->value,
            ApiAbility::LedgerRead->value,
        ]))->toBe([ApiAbility::LedgerWrite->value]);
    });
});
