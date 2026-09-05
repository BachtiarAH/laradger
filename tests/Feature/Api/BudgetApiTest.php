<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Journal;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    Sanctum::actingAs($this->user);
});

test('budgets can be listed', function () {
    Budget::factory()->count(3)->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/budgets")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('budgets are scoped to the tenant', function () {
    Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);
    Budget::factory()->create();

    $this->getJson("/api/v1/{$this->tenant->slug}/budgets")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a budget can be created with accounts and tags', function () {
    $accounts = Account::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'type' => 'expense']);
    $tags = Tag::factory()->count(2)->create(['tenant_id' => $this->tenant->id]);

    $this->postJson("/api/v1/{$this->tenant->slug}/budgets", [
        'name' => 'Monthly Needs',
        'description' => 'Essential monthly spending.',
        'amount' => '2500000.00',
        'budget_type' => 'expense',
        'starts_at' => '2026-08-01',
        'ends_at' => '2026-08-31',
        'account_ids' => $accounts->pluck('id')->all(),
        'tag_ids' => $tags->pluck('id')->all(),
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Monthly Needs')
        ->assertJsonCount(2, 'data.accounts')
        ->assertJsonCount(2, 'data.tags');
});

test('budget creation validates the date range', function () {
    $this->postJson("/api/v1/{$this->tenant->slug}/budgets", [
        'name' => 'Invalid',
        'amount' => 100000,
        'starts_at' => '2026-08-31',
        'ends_at' => '2026-08-01',
    ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
});

test('budgets can be filtered by tag and account', function () {
    $tag = Tag::factory()->create(['tenant_id' => $this->tenant->id]);
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $budget = Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);
    $budget->tags()->attach($tag);
    $budget->accounts()->attach($account);

    Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/budgets?tag_id={$tag->id}&account_id={$account->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $budget->id);
});

test('a budget can be updated', function () {
    $budget = Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);

    $this->putJson("/api/v1/{$this->tenant->slug}/budgets/{$budget->id}", [
        'name' => 'Updated Budget',
        'amount' => 3000000,
    ])->assertOk()->assertJsonPath('data.name', 'Updated Budget');
});

test('a budget cannot be accessed by another user', function () {
    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);
    $budget = Budget::factory()->create(['tenant_id' => $otherTenant->id, 'user_id' => $otherUser->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/budgets/{$budget->id}")->assertNotFound();
});

test('a budget can be archived (soft-deleted)', function () {
    $budget = Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);

    $this->deleteJson("/api/v1/{$this->tenant->slug}/budgets/{$budget->id}")->assertNoContent();

    expect(Budget::withoutGlobalScopes()->find($budget->id)->trashed())->toBeTrue();
});

test('a budget can be created without a budget type', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);

    $this->postJson("/api/v1/{$this->tenant->slug}/budgets", [
        'name' => 'Cash reserve',
        'amount' => '5000000.00',
        'starts_at' => '2026-08-01',
        'ends_at' => '2026-08-31',
        'account_ids' => [$account->id],
    ])->assertCreated()
        ->assertJsonPath('data.budget_type', null);
});

test('a budget can link accounts of any type', function () {
    foreach (['asset', 'liability', 'equity', 'income', 'expense'] as $type) {
        $account = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => $type]);

        $this->postJson("/api/v1/{$this->tenant->slug}/budgets", [
            'name' => "Budget {$type}",
            'amount' => '1000000.00',
            'budget_type' => null,
            'starts_at' => '2026-08-01',
            'ends_at' => '2026-08-31',
            'account_ids' => [$account->id],
        ])->assertCreated()
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.id', $account->id);
    }
});

test('budget summary includes actual movement for balance-sheet accounts', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);
    $budget = Budget::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'budget_type' => null,
        'starts_at' => '2026-08-01',
        'ends_at' => '2026-08-31',
    ]);

    $journal = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => 'posted',
        'transaction_date' => '2026-08-15',
    ]);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => 1000.00, 'credit' => 0, 'description' => 'In']);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => 0, 'credit' => 500.00, 'description' => 'Out']);

    $response = $this->getJson("/api/v1/{$this->tenant->slug}/budgets?budget_type=")
        ->assertOk();

    expect($response->json('meta.summary.other_actual'))->toBe('1500.00');
});
