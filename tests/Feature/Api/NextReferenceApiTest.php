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

test('the next journal reference can be fetched', function () {
    $this->getJson("/api/v1/{$this->tenant->slug}/journals/next-reference")
        ->assertOk()
        ->assertJsonPath('data.reference', 'JRN-'.now()->year.'-0001');
});

test('the next journal reference accounts for existing journals', function () {
    Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'transaction_date' => now()->year.'-01-01',
        'reference' => 'JRN-'.now()->year.'-0001',
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/journals/next-reference")
        ->assertOk()
        ->assertJsonPath('data.reference', 'JRN-'.now()->year.'-0002');
});

test('the next account code can be fetched per type', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'asset', 'code' => 'AS-0001']);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/next-code?type=asset")
        ->assertOk()
        ->assertJsonPath('data.code', 'AS-0002');

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/next-code?type=expense")
        ->assertOk()
        ->assertJsonPath('data.code', 'EX-0001');
});

test('the next account code requires a valid type', function () {
    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/next-code?type=invalid")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});
