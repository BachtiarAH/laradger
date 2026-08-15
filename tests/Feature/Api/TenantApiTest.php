<?php

use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('a user can list their tenants', function () {
    $user = User::factory()->create();
    $first = createTenantForUser($user);
    $second = Tenant::factory()->create();
    $user->tenants()->attach($second, ['role' => 'member']);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/tenants');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                ['id', 'name', 'slug', 'role'],
            ],
            'links',
            'meta',
        ]);

    $rolesById = collect($response->json('data'))->pluck('role', 'id');
    expect($rolesById)->toHaveKey($first->id, 'owner')
        ->toHaveKey($second->id, 'member');
});

test('listing tenants does not require the X-Tenant header', function () {
    $user = User::factory()->create();
    createTenantForUser($user);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/tenants')->assertOk();
});

test('guests cannot list tenants', function () {
    $this->getJson('/api/v1/tenants')->assertUnauthorized();
});
