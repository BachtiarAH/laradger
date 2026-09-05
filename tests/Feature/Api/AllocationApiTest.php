<?php

use App\Models\Account;
use App\Models\Allocation;
use App\Models\Journal;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    Sanctum::actingAs($this->user);
});

/**
 * Create an asset account whose posted ledger balance equals $amount.
 */
function allocationAccountWithPostedBalance($tenant, float $amount, string $currency = 'IDR'): Account
{
    $account = Account::factory()->create(['tenant_id' => $tenant->id, 'type' => 'asset', 'currency' => $currency]);

    $journal = Journal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'posted']);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => $amount, 'credit' => 0, 'description' => 'Opening balance']);

    return $account;
}

function reserveAllocationOn($allocation, $account, float $amount): void
{
    $allocation->accounts()->attach($account->id, ['amount' => $amount]);
}

test('allocations can be listed with their totals', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);

    $first = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);
    $second = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Laptop']);

    reserveAllocationOn($first, $account, 3000000);
    reserveAllocationOn($second, $account, 1000000);

    $data = $this->getJson("/api/v1/{$this->tenant->slug}/allocations")
        ->assertOk()
        ->json('data');

    expect(collect($data)->pluck('name')->all())->toBe(['Emergency Fund', 'Laptop']);

    $row = collect($data)->firstWhere('name', 'Emergency Fund');
    expect($row['total_allocated'])->toBe('3000000.00');
});

test('allocations are scoped to the tenant', function () {
    Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Mine']);
    $otherTenant = Allocation::factory()->create(['name' => 'Theirs']);

    $this->getJson("/api/v1/{$this->tenant->slug}/allocations")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mine');

    $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$otherTenant->id}")
        ->assertNotFound();
});

test('an allocation can be created and audited', function () {
    $this->postJson("/api/v1/{$this->tenant->slug}/allocations", [
        'name' => 'Emergency Fund',
        'description' => 'Rainy day money.',
        'target_amount' => '10000000.00',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Emergency Fund')
        ->assertJsonPath('data.target_amount', '10000000.00')
        ->assertJsonPath('data.total_allocated', '0.00');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'allocation.created',
        'user_id' => $this->user->id,
        'tenant_id' => $this->tenant->id,
    ]);
});

test('creating an allocation validates required fields', function () {
    $this->postJson("/api/v1/{$this->tenant->slug}/allocations", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('an allocation can be shown with its account breakdown', function () {
    $jago = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $bri = allocationAccountWithPostedBalance($this->tenant, 3000000);

    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);
    reserveAllocationOn($allocation, $jago, 3000000);
    reserveAllocationOn($allocation, $bri, 2000000);

    $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}")
        ->assertOk()
        ->assertJsonPath('data.total_allocated', '5000000.00')
        ->assertJsonCount(2, 'data.accounts');

    $accounts = collect($this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}")->json('data.accounts'));
    expect($accounts->sum(fn ($row) => (float) $row['amount']))->toBe(5000000.0);
});

test('an allocation can be updated and audited', function () {
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Old name']);

    $this->putJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}", [
        'name' => 'New name',
    ])->assertOk()->assertJsonPath('data.name', 'New name');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'allocation.updated',
        'tenant_id' => $this->tenant->id,
    ]);
});

test('an allocation can be archived (soft-deleted) with its reservations kept', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Vacation']);
    reserveAllocationOn($allocation, $account, 2000000);

    $this->deleteJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}")->assertNoContent();

    expect(Allocation::withoutGlobalScopes()->find($allocation->id)->trashed())->toBeTrue();
    $this->assertDatabaseHas('account_allocations', ['allocation_id' => $allocation->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'allocation.deleted', 'tenant_id' => $this->tenant->id]);
});

test('money can be allocated to an asset account', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);

    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/allocate", [
        'account_id' => $account->id,
        'amount' => '3000000.00',
    ])->assertOk()
        ->assertJsonPath('data.total_allocated', '3000000.00')
        ->assertJsonPath('data.accounts.0.amount', '3000000.00');

    $this->assertDatabaseHas('account_allocations', [
        'allocation_id' => $allocation->id,
        'account_id' => $account->id,
        'amount' => 3000000,
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'allocation.allocated', 'tenant_id' => $this->tenant->id]);
});

test('allocating twice accumulates the amount', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);

    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/allocate", ['account_id' => $account->id, 'amount' => '1000000.00'])->assertOk();
    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/allocate", ['account_id' => $account->id, 'amount' => '500000.00'])->assertOk();

    $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}")
        ->assertJsonPath('data.total_allocated', '1500000.00');
});

test('allocation cannot exceed the available posted balance', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);

    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/allocate", [
        'account_id' => $account->id,
        'amount' => '5000000.01',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);

    $this->assertDatabaseCount('account_allocations', 0);
});

test('draft journal lines do not count towards the available balance', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);
    $posted = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $posted->lines()->create(['account_id' => $account->id, 'debit' => 5000000, 'credit' => 0]);

    $draft = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);
    $draft->lines()->create(['account_id' => $account->id, 'debit' => 9000000, 'credit' => 0]);

    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);

    // Draft would imply 14M available, but only the posted 5M is real money.
    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/allocate", [
        'account_id' => $account->id,
        'amount' => '6000000.00',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);

    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/allocate", [
        'account_id' => $account->id,
        'amount' => '5000000.00',
    ])->assertOk();
});

test('allocations can only be placed on asset accounts', function () {
    $liability = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'liability']);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);

    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/allocate", [
        'account_id' => $liability->id,
        'amount' => '1000000.00',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['account_id']);
});

test('allocating on an account from another tenant is rejected', function () {
    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);
    $foreignAccount = Account::factory()->create(['tenant_id' => $otherTenant->id, 'type' => 'asset']);

    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);

    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/allocate", [
        'account_id' => $foreignAccount->id,
        'amount' => '1000000.00',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['account_id']);
});

test('money can be released partially and fully', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);
    reserveAllocationOn($allocation, $account, 3000000);

    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/release", [
        'account_id' => $account->id,
        'amount' => '1000000.00',
    ])->assertOk()
        ->assertJsonPath('data.total_allocated', '2000000.00');

    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/release", [
        'account_id' => $account->id,
        'amount' => '2000000.00',
    ])->assertOk()
        ->assertJsonPath('data.total_allocated', '0.00');

    $this->assertDatabaseMissing('account_allocations', [
        'allocation_id' => $allocation->id,
        'account_id' => $account->id,
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'allocation.released', 'tenant_id' => $this->tenant->id]);
});

test('release cannot exceed the reserved amount', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);
    reserveAllocationOn($allocation, $account, 3000000);

    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/release", [
        'account_id' => $account->id,
        'amount' => '3000000.01',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

test('an account with an allocation can be archived (soft-deleted)', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);
    reserveAllocationOn($allocation, $account, 2000000);

    $this->deleteJson("/api/v1/{$this->tenant->slug}/accounts/{$account->id}")
        ->assertNoContent();

    expect(Account::withoutGlobalScopes()->find($account->id)->trashed())->toBeTrue();
});

test('account allocation summary reports allocated and unallocated amounts', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);
    reserveAllocationOn($allocation, $account, 3000000);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/{$account->id}/allocations")
        ->assertOk()
        ->assertJsonPath('data.balance', '5000000.00')
        ->assertJsonPath('data.available', '5000000.00')
        ->assertJsonPath('data.total_allocated', '3000000.00')
        ->assertJsonPath('data.unallocated', '2000000.00')
        ->assertJsonPath('data.over_allocated', false)
        ->assertJsonPath('data.items.0.amount', '3000000.00');
});

test('an account is flagged over-allocated when spending drops below reserved amounts', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);
    reserveAllocationOn($allocation, $account, 4000000);

    // Spend 2M of the balance, leaving only 3M posted but 4M reserved.
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => 0, 'credit' => 2000000, 'description' => 'Spend']);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/{$account->id}/allocations")
        ->assertOk()
        ->assertJsonPath('data.available', '3000000.00')
        ->assertJsonPath('data.unallocated', '-1000000.00')
        ->assertJsonPath('data.over_allocated', true);
});

test('allocation audit entries are visible through the audit log API', function () {
    $account = allocationAccountWithPostedBalance($this->tenant, 5000000);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Emergency Fund']);

    $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/allocate", [
        'account_id' => $account->id,
        'amount' => '1000000.00',
    ])->assertOk();

    $this->getJson("/api/v1/{$this->tenant->slug}/audit-logs")
        ->assertOk()
        ->assertJsonFragment(['action' => 'allocation.allocated']);
});

test('an allocation cannot be updated by a user from another tenant', function () {
    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);
    $allocation = Allocation::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Theirs']);

    $this->putJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}", [
        'name' => 'Hijacked',
    ])->assertNotFound();
});
