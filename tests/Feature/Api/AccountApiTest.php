<?php

use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    Sanctum::actingAs($this->user);
});

test('accounts can be listed', function () {
    Account::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('accounts can be filtered by type', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'liability']);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts?type=asset")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'asset');
});

test('accounts can be filtered by currency', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'IDR']);
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'USD']);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts?currency=USD")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.currency', 'USD');
});

test('accounts can be filtered by status', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'inactive']);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts?status=inactive")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'inactive');
});

test('accounts can be searched by name', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Petty Cash']);
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Accounts Receivable']);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts?search=petty")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Petty Cash');
});

test('account list includes balance aggregates', function () {
    $asset = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);
    $liability = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'liability']);

    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $journal->lines()->create(['account_id' => $asset->id, 'debit' => 150.00, 'credit' => 30.00]);
    $journal->lines()->create(['account_id' => $liability->id, 'debit' => 40.00, 'credit' => 100.00]);

    $data = $this->getJson("/api/v1/{$this->tenant->slug}/accounts")->assertOk()->json('data');

    $assetRow = collect($data)->firstWhere('id', $asset->id);
    $liabilityRow = collect($data)->firstWhere('id', $liability->id);

    expect($assetRow)->not->toBeNull();
    expect($assetRow['total_debit'])->toBe('150.00');
    expect($assetRow['total_credit'])->toBe('30.00');
    expect($assetRow['net'])->toBe('120.00');
    expect($assetRow['balance'])->toBe('120.00');
    expect($assetRow['balance_side'])->toBe('debit');

    expect($liabilityRow)->not->toBeNull();
    expect($liabilityRow['total_debit'])->toBe('40.00');
    expect($liabilityRow['total_credit'])->toBe('100.00');
    expect($liabilityRow['balance'])->toBe('60.00');
    expect($liabilityRow['balance_side'])->toBe('credit');
});

test('an account without transactions has a zero balance', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);

    $data = $this->getJson("/api/v1/{$this->tenant->slug}/accounts")->assertOk()->json('data');

    $row = collect($data)->firstWhere('id', $account->id);

    expect($row)->not->toBeNull();
    expect($row['total_debit'])->toBe('0.00');
    expect($row['total_credit'])->toBe('0.00');
    expect($row['balance'])->toBe('0.00');
    expect($row['balance_side'])->toBe('debit');
});

test('single account responses do not include balance aggregates', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset']);

    $row = $this->getJson("/api/v1/{$this->tenant->slug}/accounts/{$account->id}")
        ->assertOk()
        ->json('data');

    expect($row)->not->toHaveKey('total_debit');
    expect($row)->not->toHaveKey('balance');
});

test('an account can be created', function () {
    $response = $this->postJson("/api/v1/{$this->tenant->slug}/accounts", [
        'name' => 'Petty Cash',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.code', 'AS-0001')
        ->assertJsonPath('data.name', 'Petty Cash');

    $this->assertDatabaseHas('accounts', ['code' => 'AS-0001', 'tenant_id' => $this->tenant->id]);
});

test('account codes are sequential per type', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'AS-0001', 'type' => 'asset']);

    $response = $this->postJson("/api/v1/{$this->tenant->slug}/accounts", [
        'name' => 'Cash on Hand',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ]);

    $response->assertCreated()->assertJsonPath('data.code', 'AS-0002');
});

test('a custom account code can be provided by the client', function () {
    $this->postJson("/api/v1/{$this->tenant->slug}/accounts", [
        'code' => 'CUSTOM-1',
        'name' => 'Petty Cash',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ])->assertCreated()->assertJsonPath('data.code', 'CUSTOM-1');
});

test('creating an account validates required fields', function () {
    $this->postJson("/api/v1/{$this->tenant->slug}/accounts", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type', 'currency', 'status']);
});

test('an account can be shown with parent and children', function () {
    $parent = Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'is_header' => true]);
    $child = Account::factory()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1100',
        'name' => 'Cash',
        'type' => 'asset',
        'parent_id' => $parent->id,
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/{$child->id}")
        ->assertOk()
        ->assertJsonPath('data.parent.code', '1000')
        ->assertJsonPath('data.children', []);
});

test('an account can be updated', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->putJson("/api/v1/{$this->tenant->slug}/accounts/{$account->id}", [
        'name' => 'Updated Name',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'inactive',
    ])->assertOk()->assertJsonPath('data.name', 'Updated Name');
});

test('an account can be deleted', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->deleteJson("/api/v1/{$this->tenant->slug}/accounts/{$account->id}")->assertNoContent();

    $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
});

test('an account with journal lines cannot be deleted and returns a conflict', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => 100.00, 'credit' => 0]);

    $this->deleteJson("/api/v1/{$this->tenant->slug}/accounts/{$account->id}")
        ->assertStatus(409);

    expect(Account::withoutGlobalScopes()->find($account->id))->not->toBeNull();
});

test('accounts are sorted hierarchically with depth information', function () {
    $parent = Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'is_header' => true]);
    $child1 = Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'parent_id' => $parent->id]);
    $child2 = Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => '1200', 'name' => 'Bank', 'type' => 'asset', 'parent_id' => $parent->id]);
    $unrelated = Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => '2000', 'name' => 'Liabilities', 'type' => 'liability']);

    $data = $this->getJson("/api/v1/{$this->tenant->slug}/accounts")->assertOk()->json('data');

    // Verify depth is correct
    $parentRow = collect($data)->firstWhere('id', $parent->id);
    $child1Row = collect($data)->firstWhere('id', $child1->id);
    $child2Row = collect($data)->firstWhere('id', $child2->id);
    $unrelatedRow = collect($data)->firstWhere('id', $unrelated->id);

    expect($parentRow['depth'])->toBe(0);
    expect($child1Row['depth'])->toBe(1);
    expect($child2Row['depth'])->toBe(1);
    expect($unrelatedRow['depth'])->toBe(0);

    // Verify hierarchical ordering (parents before children)
    $parentIndex = array_search($parent->id, array_column($data, 'id'));
    $child1Index = array_search($child1->id, array_column($data, 'id'));
    $child2Index = array_search($child2->id, array_column($data, 'id'));

    expect($parentIndex)->toBeLessThan($child1Index);
    expect($parentIndex)->toBeLessThan($child2Index);
});

test('a sub-account can be added under a fresh detail account and promotes it to induk', function () {
    $parent = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'is_header' => false]);

    $this->postJson("/api/v1/{$this->tenant->slug}/accounts", [
        'name' => 'Reksa Dana Saham - Mandiri',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
        'parent_id' => $parent->id,
    ])->assertCreated()->assertJsonPath('data.parent_id', $parent->id);

    expect(Account::withoutGlobalScopes()->find($parent->id)->is_header)->toBeTrue();
});
