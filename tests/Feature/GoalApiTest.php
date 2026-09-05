<?php

use App\Models\Account;
use App\Models\Goal;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\SafeMoneyService;
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

test('goals can be listed, created, shown, updated, and deleted', function () {
    // 1. Create
    $res = $this->postJson("/api/v1/{$this->tenant->slug}/goals", [
        'name' => 'Emergency Fund',
        'description' => '6 months expenses',
        'target_amount' => '10000000.00',
        'target_date' => now()->addYear()->toDateString(),
        'recurring_contribution_amount' => '500000.00',
        'contribution_frequency' => 'monthly',
    ])->assertCreated();

    $goalId = $res->json('data.id');
    expect($res->json('data.name'))->toBe('Emergency Fund')
        ->and($res->json('data.target_amount'))->toBe('10000000.00')
        ->and($res->json('data.current_amount'))->toBe('0.00')
        ->and($res->json('data.remaining_amount'))->toBe('10000000.00')
        ->and($res->json('data.progress_percent'))->toBe(0);

    // 2. List
    $this->getJson("/api/v1/{$this->tenant->slug}/goals")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $goalId);

    // 3. Show
    $this->getJson("/api/v1/{$this->tenant->slug}/goals/{$goalId}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Emergency Fund');

    // 4. Update
    $this->putJson("/api/v1/{$this->tenant->slug}/goals/{$goalId}", [
        'name' => 'Emergency Fund (Updated)',
        'target_amount' => '12000000.00',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Emergency Fund (Updated)')
        ->assertJsonPath('data.target_amount', '12000000.00');

    // 5. Delete
    $this->deleteJson("/api/v1/{$this->tenant->slug}/goals/{$goalId}")
        ->assertNoContent();

    expect(Goal::withoutGlobalScopes()->find($goalId)->trashed())->toBeTrue();
});

test('goals are scoped to the tenant', function () {
    Goal::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'My Goal']);
    $otherTenant = createTenantForUser(User::factory()->create());
    $otherGoal = Goal::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Other Goal']);

    $this->getJson("/api/v1/{$this->tenant->slug}/goals")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'My Goal');

    $this->getJson("/api/v1/{$this->tenant->slug}/goals/{$otherGoal->id}")
        ->assertNotFound();
});

test('goal accumulates contributions from transfers without inflating expenses', function () {
    $checking = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'name' => 'BRI Checking']);
    $savings = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'name' => 'Jago Savings']);
    $cash = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'name' => 'Cash']);

    // Seed checking with 5M
    $opening = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $opening->lines()->create(['account_id' => $checking->id, 'debit' => 5000000, 'credit' => 0, 'description' => 'Opening']);

    $goal = Goal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Emergency Fund',
        'target_amount' => 5000000,
        'recurring_contribution_amount' => 500000,
    ]);

    // Transfer 1: 500k from Checking to Savings linked to goal
    $this->postJson("/api/v1/{$this->tenant->slug}/transactions", [
        'type' => 'transfer',
        'amount' => 500000,
        'from_account_id' => $checking->id,
        'to_account_id' => $savings->id,
        'description' => 'Emergency fund transfer 1',
        'goal_id' => $goal->id,
        'status' => 'posted',
    ])->assertCreated();

    // Transfer 2: 300k from Cash to Savings linked to goal
    $this->postJson("/api/v1/{$this->tenant->slug}/transactions", [
        'type' => 'transfer',
        'amount' => 300000,
        'from_account_id' => $cash->id,
        'to_account_id' => $savings->id,
        'description' => 'Emergency fund transfer 2',
        'goal_id' => $goal->id,
        'status' => 'posted',
    ])->assertCreated();

    // Check goal accumulated amount
    $goalResponse = $this->getJson("/api/v1/{$this->tenant->slug}/goals/{$goal->id}")
        ->assertOk()
        ->json('data');

    expect($goalResponse['current_amount'])->toBe('800000.00')
        ->and($goalResponse['remaining_amount'])->toBe('4200000.00')
        ->and((float) $goalResponse['progress_percent'])->toEqual(16.0);

    // Verify expenses were NOT inflated (0 expense journals)
    $expenseLines = JournalLine::query()
        ->whereHas('account', fn ($q) => $q->where('type', 'expense'))
        ->count();
    expect($expenseLines)->toBe(0);
});

test('recurring goal contribution is committed for Safe-to-Spend before transfer occurs', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'status' => 'active']);
    $savings = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'status' => 'active']);

    // 5M posted balance
    $opening = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $opening->lines()->create(['account_id' => $account->id, 'debit' => 5000000, 'credit' => 0, 'description' => 'Opening']);

    $goal = Goal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Emergency Fund',
        'target_amount' => 10000000,
        'recurring_contribution_amount' => 500000,
    ]);

    $safeMoney = app(SafeMoneyService::class);

    // Before transfer: 5M assets, 500k pending goal commitment -> Safe-to-Spend = 4.5M
    $resBefore = $this->getJson("/api/v1/{$this->tenant->slug}/overview")
        ->assertOk()
        ->json('data');

    expect($resBefore['eligible_assets'])->toBe('5000000.00')
        ->and($resBefore['goal_commitments']['total'])->toBe('500000.00')
        ->and($resBefore['goal_commitments']['pending_contributions'])->toBe('500000.00')
        ->and($resBefore['goal_commitments']['accumulated_savings'])->toBe('0.00')
        ->and($resBefore['safe_to_spend'])->toBe('4500000.00');

    // Now perform the 500k transfer to savings
    $this->postJson("/api/v1/{$this->tenant->slug}/transactions", [
        'type' => 'transfer',
        'amount' => 500000,
        'from_account_id' => $account->id,
        'to_account_id' => $savings->id,
        'description' => 'Goal monthly contribution',
        'goal_id' => $goal->id,
        'status' => 'posted',
    ])->assertCreated();

    // After transfer: 5M assets, 0 pending, 500k accumulated ring-fenced -> Safe-to-Spend stays 4.5M
    $resAfter = $this->getJson("/api/v1/{$this->tenant->slug}/overview")
        ->assertOk()
        ->json('data');

    expect($resAfter['eligible_assets'])->toBe('5000000.00')
        ->and($resAfter['goal_commitments']['pending_contributions'])->toBe('0.00')
        ->and($resAfter['goal_commitments']['accumulated_savings'])->toBe('500000.00')
        ->and($resAfter['goal_commitments']['total'])->toBe('500000.00')
        ->and($resAfter['safe_to_spend'])->toBe('4500000.00');
});
