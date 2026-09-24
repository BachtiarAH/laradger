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
        ->and($allocation->effectiveTargetAmount())->toBe(350000.0)
        ->and((float) $allocation->manual_realized_amount)->toBe(0.0);
});

test('allocation supports manual realization without journal transactions', function () {
    $bri = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'status' => 'active']);
    $opening = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $opening->lines()->create(['account_id' => $bri->id, 'debit' => 1000000, 'credit' => 0]);

    $allocation = Allocation::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Monthly Vacation Fund',
        'target_amount' => 500000,
        'status' => 'active',
        'manual_realized_amount' => 0,
    ]);

    $initialJournalCount = Journal::count();

    // 1. Direct deduct with add mode (200k)
    $res1 = $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/deduct", [
        'amount' => 200000,
        'mode' => 'add',
        'reason' => 'Direct cash spending offline',
    ])->assertOk()->json('data');

    expect($res1['manual_realized_amount'])->toBe('200000.00')
        ->and($res1['journal_realized_amount'])->toBe('0.00')
        ->and($res1['realized_amount'])->toBe('200000.00')
        ->and($res1['remaining_amount'])->toBe('300000.00');

    // 2. Add another 100k
    $res2 = $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/deduct", [
        'amount' => 100000,
        'mode' => 'add',
    ])->assertOk()->json('data');

    expect($res2['manual_realized_amount'])->toBe('300000.00')
        ->and($res2['remaining_amount'])->toBe('200000.00');

    // 3. Set mode (set to 150k)
    $res3 = $this->postJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}/deduct", [
        'amount' => 150000,
        'mode' => 'set',
    ])->assertOk()->json('data');

    expect($res3['manual_realized_amount'])->toBe('150000.00')
        ->and($res3['remaining_amount'])->toBe('350000.00');

    // 4. Verify no journals were created
    TenantContext::set($this->tenant);
    expect(Journal::count())->toBe($initialJournalCount);

    // 5. Verify Safe-to-Spend reflects the reduced remaining commitment:
    // Assets: 1,000,000. Remaining allocation: 350,000 -> Safe-to-Spend: 650,000
    $overview = $this->getJson("/api/v1/{$this->tenant->slug}/overview")->assertOk()->json('data');
    expect($overview['eligible_assets'])->toBe('1000000.00')
        ->and($overview['allocated']['total_allocated'])->toBe('350000.00')
        ->and($overview['safe_to_spend'])->toBe('650000.00');
});

test('safe to spend ignores reservations of soft-deleted allocations', function () {
    $bri = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'status' => 'active']);

    $opening = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'transaction_date' => now()->toDateString(),
        'status' => 'posted',
    ]);
    $opening->lines()->create(['account_id' => $bri->id, 'debit' => 5000000, 'credit' => 0]);

    $deletedAllocation = Allocation::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Old Allocation',
        'target_amount' => 2000000,
        'status' => 'active',
    ]);
    $deletedAllocation->accounts()->attach($bri->id, ['amount' => 2000000]);

    Allocation::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Active Allocation',
        'target_amount' => 500000,
        'status' => 'active',
    ]);

    $resBefore = $this->getJson("/api/v1/{$this->tenant->slug}/overview")->assertOk()->json('data');
    expect($resBefore['allocated']['total_allocated'])->toBe('2500000.00')
        ->and($resBefore['safe_to_spend'])->toBe('2500000.00');

    $this->deleteJson("/api/v1/{$this->tenant->slug}/allocations/{$deletedAllocation->id}")->assertNoContent();

    $resAfter = $this->getJson("/api/v1/{$this->tenant->slug}/overview")->assertOk()->json('data');
    expect($resAfter['allocated']['total_allocated'])->toBe('500000.00')
        ->and($resAfter['allocated']['total_target'])->toBe('500000.00')
        ->and($resAfter['safe_to_spend'])->toBe('4500000.00');
});

test('allocation can auto-spend when expenses occur on linked expense accounts', function () {
    $checking = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'name' => 'BCA', 'status' => 'active']);
    $foodGrocery = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'name' => 'Bahan Makanan', 'status' => 'active']);
    $foodDining = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'name' => 'Makan di Luar', 'status' => 'active']);

    // Seed checking balance
    $opening = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $opening->lines()->create(['account_id' => $checking->id, 'debit' => 5000000, 'credit' => 0]);

    // Create allocation linked to two expense accounts
    $res = $this->postJson("/api/v1/{$this->tenant->slug}/allocations", [
        'name' => 'Makan & Minum',
        'target_amount' => 1000000,
        'type' => 'recurring',
        'expense_account_ids' => [$foodGrocery->id, $foodDining->id],
    ])->assertCreated();

    $allocationId = $res->json('data.id');
    expect($res->json('data.expense_account_ids'))->toContain($foodGrocery->id, $foodDining->id)
        ->and($res->json('data.realized_amount'))->toBe('0.00');

    // Expense 1: Grocery 300k WITHOUT specifying allocation_id!
    $this->postJson("/api/v1/{$this->tenant->slug}/transactions", [
        'type' => 'expense',
        'amount' => 300000,
        'expense_account_id' => $foodGrocery->id,
        'asset_account_id' => $checking->id,
        'description' => 'Beli beras dan sayur',
        'status' => 'posted',
    ])->assertCreated();

    // Check allocation auto-spent
    $check1 = $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$allocationId}")
        ->assertOk()
        ->json('data');

    expect($check1['realized_amount'])->toBe('300000.00')
        ->and($check1['remaining_amount'])->toBe('700000.00')
        ->and((float) $check1['progress_percent'])->toEqual(30.0);

    // Expense 2: Dining out 200k on the second linked expense account WITHOUT specifying allocation_id!
    $this->postJson("/api/v1/{$this->tenant->slug}/transactions", [
        'type' => 'expense',
        'amount' => 200000,
        'expense_account_id' => $foodDining->id,
        'asset_account_id' => $checking->id,
        'description' => 'Makan di warung',
        'status' => 'posted',
    ])->assertCreated();

    // Check allocation auto-spent cumulative
    $check2 = $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$allocationId}")
        ->assertOk()
        ->json('data');

    expect($check2['realized_amount'])->toBe('500000.00')
        ->and($check2['remaining_amount'])->toBe('500000.00')
        ->and((float) $check2['progress_percent'])->toEqual(50.0);

    // Check journals list for allocation also returns both auto-spent transactions
    $this->getJson("/api/v1/{$this->tenant->slug}/journals?allocation_id={$allocationId}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('an explicit quick transaction allocation wins over an auto match', function () {
    $asset = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);
    $expense = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense']);
    $autoAllocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id]);
    $explicitAllocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id]);
    $autoAllocation->expenseAccounts()->attach($expense->id);

    $response = $this->postJson("/api/v1/{$this->tenant->slug}/transactions", [
        'type' => 'expense',
        'amount' => 100000,
        'expense_account_id' => $expense->id,
        'asset_account_id' => $asset->id,
        'allocation_id' => $explicitAllocation->id,
        'description' => 'Explicit allocation',
        'status' => 'posted',
    ])->assertCreated();

    expect($response->json('data.allocation_id'))->toBe($explicitAllocation->id);
    $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$autoAllocation->id}")
        ->assertOk()
        ->assertJsonPath('data.journal_realized_amount', '0.00');
    $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$explicitAllocation->id}")
        ->assertOk()
        ->assertJsonPath('data.journal_realized_amount', '100000.00');
});

test('a manual journal without an explicit allocation resolves one active auto allocation', function () {
    $asset = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);
    $expense = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense']);
    $allocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id]);
    $allocation->expenseAccounts()->attach($expense->id);

    $response = $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => now()->toDateString(),
        'description' => 'Auto-linked manual expense',
        'status' => 'posted',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $expense->id, 'debit' => 100000, 'credit' => 0],
            ['account_id' => $asset->id, 'debit' => 0, 'credit' => 100000],
        ],
    ])->assertCreated();

    expect($response->json('data.allocation_id'))->toBe($allocation->id);
    $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$allocation->id}")
        ->assertOk()
        ->assertJsonPath('data.journal_realized_amount', '100000.00');
});

test('a quick transaction stops when an expense account has multiple auto allocations', function () {
    $asset = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);
    $expense = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense']);
    $first = Allocation::factory()->create(['tenant_id' => $this->tenant->id]);
    $second = Allocation::factory()->create(['tenant_id' => $this->tenant->id]);
    $first->expenseAccounts()->attach($expense->id);
    $second->expenseAccounts()->attach($expense->id);
    $journalCount = Journal::count();

    $this->postJson("/api/v1/{$this->tenant->slug}/transactions", [
        'type' => 'expense',
        'amount' => 100000,
        'expense_account_id' => $expense->id,
        'asset_account_id' => $asset->id,
        'description' => 'Ambiguous expense',
        'status' => 'draft',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['allocation_id']);

    expect(Journal::count())->toBe($journalCount);
});
