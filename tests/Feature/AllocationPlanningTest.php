<?php

use App\Models\Account;
use App\Models\Allocation;
use App\Models\Journal;
use App\Models\User;
use App\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    TenantContext::set($this->tenant);
    Sanctum::actingAs($this->user);
});

afterEach(function () {
    TenantContext::flush();
});

test('creating an allocation does not change account balances or ledger balances', function () {
    $bri = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);
    $opening = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $opening->lines()->create(['account_id' => $bri->id, 'debit' => 5000000, 'credit' => 0, 'description' => 'Opening']);

    expect($bri->postedNetBalance())->toBe(5000000.0);

    // Create allocation
    $this->postJson("/api/v1/{$this->tenant->slug}/allocations", [
        'name' => 'Sibling Allowance',
        'target_amount' => '250000.00',
        'type' => 'recurring',
        'period_type' => 'monthly',
        'roll_forward_mode' => 'reset',
    ])->assertCreated();

    // Re-set tenant context after request
    TenantContext::set($this->tenant);

    // Verify balances unchanged
    expect($bri->fresh()->postedNetBalance())->toBe(5000000.0);
    expect(Journal::count())->toBe(1);
});

test('an allocation can be fulfilled by transactions from multiple asset accounts', function () {
    $bri = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'name' => 'BRI']);
    $cash = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'name' => 'Cash']);
    $expense = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'name' => 'Allowance']);

    // Seed balances
    $opening = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $opening->lines()->create(['account_id' => $bri->id, 'debit' => 3000000, 'credit' => 0]);
    $opening->lines()->create(['account_id' => $cash->id, 'debit' => 1000000, 'credit' => 0]);

    // Create allocation of 250k
    $allocation = Allocation::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Sibling Allowance',
        'target_amount' => 250000,
        'type' => 'recurring',
    ]);

    // Expense 1: 150k paid from BRI
    $this->postJson("/api/v1/{$this->tenant->slug}/transactions", [
        'type' => 'expense',
        'amount' => 150000,
        'expense_account_id' => $expense->id,
        'asset_account_id' => $bri->id,
        'allocation_id' => $allocation->id,
        'description' => 'Allowance part 1 (BRI)',
        'status' => 'posted',
    ])->assertCreated();

    // Expense 2: 100k paid from Cash
    $this->postJson("/api/v1/{$this->tenant->slug}/transactions", [
        'type' => 'expense',
        'amount' => 100000,
        'expense_account_id' => $expense->id,
        'asset_account_id' => $cash->id,
        'allocation_id' => $allocation->id,
        'description' => 'Allowance part 2 (Cash)',
        'status' => 'posted',
    ])->assertCreated();

    // Check allocation realization
    $res = $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}")
        ->assertOk()
        ->json('data');

    expect($res['realized_amount'])->toBe('250000.00')
        ->and($res['remaining_amount'])->toBe('0.00')
        ->and((float) $res['progress_percent'])->toEqual(100.0);
});

test('safe to spend only deducts outstanding remaining allocation rather than double-deducting spent funds', function () {
    $bri = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'status' => 'active']);
    $food = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'status' => 'active']);

    $opening = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $opening->lines()->create(['account_id' => $bri->id, 'debit' => 1000000, 'credit' => 0]);

    // Allocation of 300k
    $allocation = Allocation::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Food allocation',
        'target_amount' => 300000,
        'status' => 'active',
    ]);

    // Initial overview: 1M assets, 300k allocation -> Safe = 700k
    $res1 = $this->getJson("/api/v1/{$this->tenant->slug}/overview")->assertOk()->json('data');
    expect($res1['eligible_assets'])->toBe('1000000.00')
        ->and($res1['allocated']['total_allocated'])->toBe('300000.00')
        ->and($res1['safe_to_spend'])->toBe('700000.00');

    // Now spend 100k towards the allocation
    $this->postJson("/api/v1/{$this->tenant->slug}/transactions", [
        'type' => 'expense',
        'amount' => 100000,
        'expense_account_id' => $food->id,
        'asset_account_id' => $bri->id,
        'allocation_id' => $allocation->id,
        'description' => 'Groceries',
        'status' => 'posted',
    ])->assertCreated();

    // After spending 100k:
    // Assets decreased by 100k -> 900k
    // Remaining allocation commitment decreased to 200k
    // Safe-to-Spend remains 900k - 200k = 700k! (Not 900k - 300k = 600k!)
    $res2 = $this->getJson("/api/v1/{$this->tenant->slug}/overview")->assertOk()->json('data');
    expect($res2['eligible_assets'])->toBe('900000.00')
        ->and($res2['allocated']['total_allocated'])->toBe('200000.00')
        ->and($res2['safe_to_spend'])->toBe('700000.00');
});

test('roll forward command rolls forward recurring allocations with carry over mode', function () {
    $expense = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense']);
    $bri = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);

    $allocation = Allocation::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Sibling Allowance',
        'target_amount' => 250000,
        'type' => 'recurring',
        'period_type' => 'monthly',
        'roll_forward_mode' => 'carry_over',
        'starts_at' => now()->subMonth()->startOfMonth(),
        'ends_at' => now()->subMonth()->endOfMonth(),
    ]);

    // Spend 150k in the previous month
    $journal = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'allocation_id' => $allocation->id,
        'status' => 'posted',
        'transaction_date' => now()->subMonth()->startOfMonth()->addDays(5),
    ]);
    $journal->lines()->create(['account_id' => $expense->id, 'debit' => 150000, 'credit' => 0]);
    $journal->lines()->create(['account_id' => $bri->id, 'debit' => 0, 'credit' => 150000]);

    // Run command
    $this->artisan('allocations:roll-forward')->assertSuccessful();

    $allocation->refresh();
    // 250k - 150k = 100k unspent carried over!
    expect((float) $allocation->carry_over_amount)->toBe(100000.0)
        ->and($allocation->effectiveTargetAmount())->toBe(350000.0);
});
