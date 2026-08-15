<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant', $this->tenant->slug);
});

test('budgets can be listed', function () {
    Budget::factory()->count(3)->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);

    $this->getJson('/api/v1/budgets')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('budgets are scoped to the tenant', function () {
    Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);
    Budget::factory()->create();

    $this->getJson('/api/v1/budgets')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a budget can be created with accounts and tags', function () {
    $accounts = Account::factory()->count(2)->create(['tenant_id' => $this->tenant->id]);
    $tags = Tag::factory()->count(2)->create(['tenant_id' => $this->tenant->id]);

    $this->postJson('/api/v1/budgets', [
        'name' => 'Monthly Needs',
        'description' => 'Essential monthly spending.',
        'amount' => '2500000.00',
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
    $this->postJson('/api/v1/budgets', [
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

    $this->getJson("/api/v1/budgets?tag_id={$tag->id}&account_id={$account->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $budget->id);
});

test('a budget can be updated', function () {
    $budget = Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);

    $this->putJson("/api/v1/budgets/{$budget->id}", [
        'name' => 'Updated Budget',
        'amount' => 3000000,
    ])->assertOk()->assertJsonPath('data.name', 'Updated Budget');
});

test('a budget cannot be accessed by another user', function () {
    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);
    $budget = Budget::factory()->create(['tenant_id' => $otherTenant->id, 'user_id' => $otherUser->id]);

    $this->getJson("/api/v1/budgets/{$budget->id}")->assertNotFound();
});

test('a budget can be deleted', function () {
    $budget = Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);

    $this->deleteJson("/api/v1/budgets/{$budget->id}")->assertNoContent();

    $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
});
