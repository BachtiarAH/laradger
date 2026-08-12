<?php

use App\Models\Account;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

test('accounts can be listed', function () {
    Account::factory()->count(3)->create();

    $this->getJson('/api/v1/accounts')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('accounts can be filtered by type', function () {
    Account::factory()->create(['type' => 'asset']);
    Account::factory()->create(['type' => 'liability']);

    $this->getJson('/api/v1/accounts?type=asset')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'asset');
});

test('accounts can be filtered by currency', function () {
    Account::factory()->create(['currency' => 'IDR']);
    Account::factory()->create(['currency' => 'USD']);

    $this->getJson('/api/v1/accounts?currency=USD')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.currency', 'USD');
});

test('accounts can be filtered by status', function () {
    Account::factory()->create(['status' => 'active']);
    Account::factory()->create(['status' => 'inactive']);

    $this->getJson('/api/v1/accounts?status=inactive')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'inactive');
});

test('accounts can be searched by name', function () {
    Account::factory()->create(['name' => 'Petty Cash']);
    Account::factory()->create(['name' => 'Accounts Receivable']);

    $this->getJson('/api/v1/accounts?search=petty')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Petty Cash');
});

test('an account can be created', function () {
    $response = $this->postJson('/api/v1/accounts', [
        'name' => 'Petty Cash',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.code', 'AS-0001')
        ->assertJsonPath('data.name', 'Petty Cash');

    $this->assertDatabaseHas('accounts', ['code' => 'AS-0001']);
});

test('account codes are sequential per type', function () {
    Account::factory()->create(['code' => 'AS-0001', 'type' => 'asset']);

    $response = $this->postJson('/api/v1/accounts', [
        'name' => 'Cash on Hand',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ]);

    $response->assertCreated()->assertJsonPath('data.code', 'AS-0002');
});

test('account codes cannot be provided by the client', function () {
    $this->postJson('/api/v1/accounts', [
        'code' => 'CUSTOM-1',
        'name' => 'Petty Cash',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ])->assertStatus(422)->assertJsonValidationErrors(['code']);
});

test('creating an account validates required fields', function () {
    $this->postJson('/api/v1/accounts', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type', 'currency', 'status']);
});

test('an account can be shown with parent and children', function () {
    $parent = Account::factory()->create(['code' => '1000', 'name' => 'Assets', 'type' => 'asset']);
    $child = Account::factory()->create([
        'code' => '1100',
        'name' => 'Cash',
        'type' => 'asset',
        'parent_id' => $parent->id,
    ]);

    $this->getJson("/api/v1/accounts/{$child->id}")
        ->assertOk()
        ->assertJsonPath('data.parent.code', '1000')
        ->assertJsonPath('data.children', []);
});

test('an account can be updated', function () {
    $account = Account::factory()->create();

    $this->putJson("/api/v1/accounts/{$account->id}", [
        'name' => 'Updated Name',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'inactive',
    ])->assertOk()->assertJsonPath('data.name', 'Updated Name');
});

test('an account can be deleted', function () {
    $account = Account::factory()->create();

    $this->deleteJson("/api/v1/accounts/{$account->id}")->assertNoContent();

    $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
});
