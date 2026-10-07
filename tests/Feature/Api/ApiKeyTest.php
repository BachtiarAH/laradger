<?php

use App\Enums\ApiAbility;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Goal;
use App\Models\Journal;
use App\Models\Tag;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyIssuer;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    $this->base = "/api/v1/{$this->tenant->slug}";
});

describe('issuance', function () {
    test('a key is read-only unless abilities are named', function () {
        $this->artisan('api:issue-key', ['email' => $this->user->email])
            ->expectsOutputToContain('issuing a read-only key')
            ->assertSuccessful();

        $token = $this->user->tokens()->latest('id')->first();

        expect($token->abilities)->toBe([ApiAbility::LedgerRead->value]);
    });

    test('a key always carries an expiry', function () {
        $this->artisan('api:issue-key', ['email' => $this->user->email])->assertSuccessful();

        expect($this->user->tokens()->latest('id')->first()->expires_at)->not->toBeNull();
    });

    test('an unrecognised ability is rejected rather than silently dropped', function () {
        $this->artisan('api:issue-key', [
            'email' => $this->user->email,
            '--ability' => ['ledger:read', 'ledger:eat'],
        ])->assertExitCode(2);

        // The key must not exist at all: a partial grant is indistinguishable
        // from the intended one once it is stored.
        expect($this->user->tokens()->count())->toBe(0);
    });

    test('a lifetime beyond the configured ceiling is rejected', function () {
        config()->set('api-keys.max_days', 30);

        $this->artisan('api:issue-key', [
            'email' => $this->user->email,
            '--days' => 365,
        ])->assertExitCode(2);

        expect($this->user->tokens()->count())->toBe(0);
    });

    test('issuing for an unknown account fails', function () {
        $this->artisan('api:issue-key', ['email' => 'nobody@example.com'])->assertExitCode(2);
    });

    test('a destructive key is not also issued read and write', function () {
        $issuer = app(ApiKeyIssuer::class);

        expect($issuer->normalize([
            ApiAbility::LedgerDestructive->value,
            ApiAbility::LedgerRead->value,
            ApiAbility::LedgerWrite->value,
        ]))->toBe([ApiAbility::LedgerDestructive->value]);
    });

    test('a write key keeps read because read is granted by write', function () {
        expect(app(ApiKeyIssuer::class)->normalize([
            ApiAbility::LedgerWrite->value,
            ApiAbility::LedgerRead->value,
        ]))->toBe([ApiAbility::LedgerWrite->value]);
    });

    test('a key with no ability is refused', function () {
        expect(fn () => app(ApiKeyIssuer::class)->issue($this->user, []))
            ->toThrow(RuntimeException::class, 'at least one ability');
    });
});

describe('enforcement', function () {
    test('a read key can list but not create', function () {
        Account::factory()->create(['tenant_id' => $this->tenant->id]);

        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerRead->value]))
            ->getJson("{$this->base}/accounts")
            ->assertOk();

        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerRead->value]))
            ->postJson("{$this->base}/accounts", validAccountPayload())
            ->assertForbidden();
    });

    test('a denial names the missing ability and what would satisfy it', function () {
        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerRead->value]))
            ->postJson("{$this->base}/accounts", validAccountPayload())
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '[ledger:write]')
                && str_contains($message, '[ledger:destructive]'));
    });

    test('a write key can create but not delete', function () {
        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerWrite->value]))
            ->postJson("{$this->base}/accounts", validAccountPayload())
            ->assertCreated();

        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerWrite->value]))
            ->deleteJson("{$this->base}/accounts/{$account->id}")
            ->assertForbidden();
    });

    test('a destructive key can delete, and still writes', function () {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

        $key = apiKeyFor($this->user, [ApiAbility::LedgerDestructive->value]);

        asApiKey($key)
            ->deleteJson("{$this->base}/accounts/{$account->id}")
            ->assertNoContent();

        asApiKey($key)
            ->postJson("{$this->base}/accounts", validAccountPayload(['name' => 'Savings']))
            ->assertCreated();
    });

    test('a destructive key cannot reach platform administration', function () {
        $this->user->update(['is_admin' => true]);

        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerDestructive->value]))
            ->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '[platform:admin]'));
    });

    test('a platform admin key does not imply ledger access', function () {
        $this->user->update(['is_admin' => true]);

        asApiKey(apiKeyFor($this->user, [ApiAbility::PlatformAdmin->value]))
            ->getJson('/api/v1/admin/users')
            ->assertOk();

        asApiKey(apiKeyFor($this->user, [ApiAbility::PlatformAdmin->value]))
            ->getJson("{$this->base}/accounts")
            ->assertForbidden();
    });

    test('reversing a journal needs destructive, not write', function () {
        $journal = Journal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'posted',
        ]);

        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerWrite->value]))
            ->postJson("{$this->base}/journals/{$journal->id}/reverse")
            ->assertForbidden();

        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerDestructive->value]))
            ->postJson("{$this->base}/journals/{$journal->id}/reverse")
            ->assertCreated();
    });

    test('cancelling an allocation needs planning destructive, not write', function () {
        $allocation = Allocation::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        asApiKey(apiKeyFor($this->user, [ApiAbility::PlanningWrite->value]))
            ->postJson("{$this->base}/allocations/{$allocation->id}/cancel")
            ->assertForbidden();

        asApiKey(apiKeyFor($this->user, [ApiAbility::PlanningDestructive->value]))
            ->postJson("{$this->base}/allocations/{$allocation->id}/cancel")
            ->assertOk();
    });

    test('completing an allocation stays a write', function () {
        $allocation = Allocation::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        asApiKey(apiKeyFor($this->user, [ApiAbility::PlanningWrite->value]))
            ->postJson("{$this->base}/allocations/{$allocation->id}/complete")
            ->assertOk();
    });

    /**
     * The separation the area split exists for.
     *
     * A ledger key used to reach allocations, goals, and budgets implicitly,
     * because those were simply more ledger endpoints. Now that they are their
     * own area, a key that posts journals has no say over a savings goal — which
     * is what makes it safe to hand out a posting key without also handing out
     * authority over the plan.
     */
    test('a ledger key cannot touch the planning area at all', function () {
        $allocation = Allocation::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerDestructive->value]))
            ->postJson("{$this->base}/allocations/{$allocation->id}/complete")
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, '[planning:write]'));
    });

    test('a planning key cannot post journals', function () {
        asApiKey(apiKeyFor($this->user, [ApiAbility::PlanningDestructive->value]))
            ->postJson("{$this->base}/accounts", validAccountPayload())
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, '[ledger:write]'));
    });

    test('a read key sees only its own area', function () {
        Goal::factory()->create(['tenant_id' => $this->tenant->id]);
        Tag::factory()->create(['tenant_id' => $this->tenant->id]);
        Account::factory()->create(['tenant_id' => $this->tenant->id]);

        $planning = apiKeyFor($this->user, [ApiAbility::PlanningRead->value]);
        $library = apiKeyFor($this->user, [ApiAbility::LibraryRead->value]);

        asApiKey($planning)->getJson("{$this->base}/goals")->assertOk();
        asApiKey($planning)->getJson("{$this->base}/tags")->assertForbidden();
        asApiKey($planning)->getJson("{$this->base}/accounts")->assertForbidden();

        asApiKey($library)->getJson("{$this->base}/tags")->assertOk();
        asApiKey($library)->getJson("{$this->base}/journal-templates")->assertOk();
        asApiKey($library)->getJson("{$this->base}/goals")->assertForbidden();
    });

    test('a read key cannot read the audit log of every colleague', function () {
        AuditLog::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
        ]);

        // Audit rows carry user_id plus full before/after images, so leaving this
        // on ledger:read would hand a reporting key every change every colleague
        // ever made — including allocation targets never published.
        asApiKey(apiKeyFor($this->user, [ApiAbility::LedgerRead->value]))
            ->getJson("{$this->base}/audit-logs")
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, '[audit:read]'));

        asApiKey(apiKeyFor($this->user, [ApiAbility::AuditRead->value]))
            ->getJson("{$this->base}/audit-logs")
            ->assertOk();
    });

    test('an expired key is rejected before abilities are considered', function () {
        $expired = apiKeyFor($this->user, [ApiAbility::LedgerDestructive->value], -1);

        asApiKey($expired)
            ->getJson("{$this->base}/accounts")
            ->assertUnauthorized();
    });
});

describe('compatibility', function () {
    test('a login token with no ability list keeps full access', function () {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

        // Exactly what AuthController::login() hands out: abilities defaulted to
        // ['*'] by Sanctum. Adding the middleware must not cost these clients
        // anything they already had.
        $token = $this->user->createToken('api-token')->plainTextToken;

        asApiKey($token)->getJson("{$this->base}/accounts")->assertOk();
        asApiKey($token)->deleteJson("{$this->base}/accounts/{$account->id}")->assertNoContent();
    });

    test('a browser session is not ability gated', function () {
        $this->actingAs($this->user);

        $this->getJson("{$this->base}/accounts")->assertOk();
    });

    test('public endpoints are unaffected', function () {
        $this->postJson('/api/v1/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertOk();
    });
});

describe('revocation', function () {
    test('listing shows keys and leaves them alone', function () {
        apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);

        $this->artisan('api:revoke-key', ['email' => $this->user->email])
            ->expectsOutputToContain('ledger:read')
            ->assertSuccessful();

        expect(PersonalAccessToken::count())->toBe(1);
    });

    test('a key is revoked by id and stops working', function () {
        $token = apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);
        $id = PersonalAccessToken::latest('id')->first()->id;

        $this->artisan('api:revoke-key', ['email' => $this->user->email, '--id' => [$id]])
            ->assertSuccessful();

        expect(PersonalAccessToken::count())->toBe(0);

        asApiKey($token)->getJson("{$this->base}/accounts")->assertUnauthorized();
    });

    test('revoking all keys leaves the login token alone', function () {
        apiKeyFor($this->user, [ApiAbility::LedgerRead->value]);
        $loginToken = $this->user->createToken('api-token')->plainTextToken;

        $this->artisan('api:revoke-key', ['email' => $this->user->email, '--all' => true])
            ->assertSuccessful();

        expect(PersonalAccessToken::count())->toBe(1);

        // The whole reason keys are distinguished by name and not by abilities:
        // "revoke everything" must not lock a person out of their own account.
        asApiKey($loginToken)->getJson('/api/v1/tenants')->assertOk();
    });

    test('an id belonging to another account is not revoked', function () {
        $other = User::factory()->create();
        $foreignId = $other->createToken('apikey:theirs', ['*'], now()->addDay())->accessToken->id;

        // Nothing on this account matched, so nothing is revoked and the command
        // says so rather than reporting a success it did not achieve.
        $this->artisan('api:revoke-key', ['email' => $this->user->email, '--id' => [$foreignId]])
            ->assertExitCode(2);

        expect(PersonalAccessToken::whereKey($foreignId)->exists())->toBeTrue();
    });
});
