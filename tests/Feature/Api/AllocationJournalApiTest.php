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

test('a posted journal can allocate incoming money to an allocation', function () {
    $jago = allocationAccountWithPostedBalance($this->tenant, 5_000_000);
    $salary = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'income']);
    $fund = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Dana Darurat']);

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-09-01',
        'description' => 'Gaji masuk',
        'status' => 'posted',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $jago->id, 'debit' => 2_000_000, 'credit' => 0],
            ['account_id' => $salary->id, 'debit' => 0, 'credit' => 2_000_000],
        ],
        'allocation_adjustments' => [
            ['action' => 'allocate', 'allocation_id' => $fund->id, 'account_id' => $jago->id, 'amount' => '1000000.00'],
        ],
    ])->assertCreated();

    $this->assertDatabaseHas('account_allocations', [
        'allocation_id' => $fund->id,
        'account_id' => $jago->id,
        'amount' => 1_000_000,
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'allocation.allocated', 'tenant_id' => $this->tenant->id]);
});

test('an allocation adjustment that exceeds the balance rolls the journal back', function () {
    $jago = allocationAccountWithPostedBalance($this->tenant, 5_000_000);
    $food = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense']);
    $fund = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Dana Darurat']);

    $journalCountBefore = Journal::count();

    // Jago drops to 4M after this spend; trying to reserve 5M must fail.
    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-09-01',
        'description' => 'Belanja',
        'status' => 'posted',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $food->id, 'debit' => 1_000_000, 'credit' => 0],
            ['account_id' => $jago->id, 'debit' => 0, 'credit' => 1_000_000],
        ],
        'allocation_adjustments' => [
            ['action' => 'allocate', 'allocation_id' => $fund->id, 'account_id' => $jago->id, 'amount' => '5000000.00'],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['allocation_adjustments.0']);

    // Nothing was persisted: journal + lines + adjustment all rolled back.
    expect(Journal::count())->toBe($journalCountBefore);
    $this->assertDatabaseCount('account_allocations', 0);
});

test('spending from an allocated account can release part of a fund in the same journal', function () {
    $jago = allocationAccountWithPostedBalance($this->tenant, 5_000_000);
    $food = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense']);
    $fund = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Dana Darurat']);
    reserveAllocationOn($fund, $jago, 3_000_000);

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-09-01',
        'description' => 'Belanja dari dana darurat',
        'status' => 'posted',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $food->id, 'debit' => 1_000_000, 'credit' => 0],
            ['account_id' => $jago->id, 'debit' => 0, 'credit' => 1_000_000],
        ],
        'allocation_adjustments' => [
            ['action' => 'release', 'allocation_id' => $fund->id, 'account_id' => $jago->id, 'amount' => '1000000.00'],
        ],
    ])->assertCreated();

    $this->assertDatabaseHas('account_allocations', [
        'allocation_id' => $fund->id,
        'account_id' => $jago->id,
        'amount' => 2_000_000,
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'allocation.released', 'tenant_id' => $this->tenant->id]);
});

test('allocation adjustments are rejected on draft journals', function () {
    $jago = allocationAccountWithPostedBalance($this->tenant, 5_000_000);
    $food = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense']);
    $fund = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Dana Darurat']);

    $journalCountBefore = Journal::count();

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-09-01',
        'description' => 'Belanja draft',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $food->id, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => $jago->id, 'debit' => 0, 'credit' => 500_000],
        ],
        'allocation_adjustments' => [
            ['action' => 'allocate', 'allocation_id' => $fund->id, 'account_id' => $jago->id, 'amount' => '500000.00'],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect(Journal::count())->toBe($journalCountBefore);
    $this->assertDatabaseCount('account_allocations', 0);
});

test('a release larger than the reservation rolls the journal back', function () {
    $jago = allocationAccountWithPostedBalance($this->tenant, 5_000_000);
    $food = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense']);
    $fund = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Dana Darurat']);
    reserveAllocationOn($fund, $jago, 1_000_000);

    $journalCountBefore = Journal::count();

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-09-01',
        'description' => 'Belanja besar',
        'status' => 'posted',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $food->id, 'debit' => 2_000_000, 'credit' => 0],
            ['account_id' => $jago->id, 'debit' => 0, 'credit' => 2_000_000],
        ],
        'allocation_adjustments' => [
            ['action' => 'release', 'allocation_id' => $fund->id, 'account_id' => $jago->id, 'amount' => '1500000.00'],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['allocation_adjustments.0']);

    expect(Journal::count())->toBe($journalCountBefore);
    $this->assertDatabaseHas('account_allocations', [
        'allocation_id' => $fund->id,
        'account_id' => $jago->id,
        'amount' => 1_000_000,
    ]);
});

test('allocation adjustments require a valid action', function () {
    $jago = allocationAccountWithPostedBalance($this->tenant, 5_000_000);
    $food = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense']);
    $fund = Allocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Dana Darurat']);

    $journalCountBefore = Journal::count();

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-09-01',
        'description' => 'Belanja',
        'status' => 'posted',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $food->id, 'debit' => 100_000, 'credit' => 0],
            ['account_id' => $jago->id, 'debit' => 0, 'credit' => 100_000],
        ],
        'allocation_adjustments' => [
            ['action' => 'move', 'allocation_id' => $fund->id, 'account_id' => $jago->id, 'amount' => '100000.00'],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['allocation_adjustments.0.action']);

    expect(Journal::count())->toBe($journalCountBefore);
});
