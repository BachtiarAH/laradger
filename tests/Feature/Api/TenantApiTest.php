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

test('listing tenants does not require a tenant in the URL', function () {
    $user = User::factory()->create();
    createTenantForUser($user);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/tenants')->assertOk();
});

test('guests cannot list tenants', function () {
    $this->getJson('/api/v1/tenants')->assertUnauthorized();
});

test('an authenticated user can create a tenant', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/tenants', [
        'name' => 'Acme Corp',
        'slug' => 'acme-corp',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Acme Corp')
        ->assertJsonPath('data.slug', 'acme-corp')
        ->assertJsonPath('data.role', 'owner');

    $tenant = Tenant::where('slug', 'acme-corp')->first();

    expect($tenant)->not->toBeNull()
        ->and($user->tenants()->whereKey($tenant->id)->exists())->toBeTrue()
        ->and($user->tenants()->find($tenant->id)->pivot->role)->toBe('owner');
});

test('an authenticated user can create a tenant with an auto-generated slug', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/tenants', [
        'name' => 'Acme Corp',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Acme Corp')
        ->assertJsonPath('data.role', 'owner');

    $slug = $response->json('data.slug');

    expect($slug)->toMatch('/^[a-z0-9-]+$/')
        ->and(Tenant::where('slug', $slug)->exists())->toBeTrue();
});

test('creating a tenant with a duplicate slug is rejected', function () {
    $user = User::factory()->create();
    Tenant::factory()->create(['slug' => 'acme-corp']);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/tenants', [
        'name' => 'Acme Corp',
        'slug' => 'acme-corp',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['slug']);
});

test('creating a tenant with an invalid slug is rejected', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/tenants', [
        'name' => 'Acme Corp',
        'slug' => 'Invalid Slug!',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['slug']);
});

test('guests cannot create a tenant', function () {
    $this->postJson('/api/v1/tenants', [
        'name' => 'Acme Corp',
    ])->assertUnauthorized();
});

test('creating a tenant does not require a tenant in the URL', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/tenants', [
        'name' => 'Acme Corp',
    ])->assertCreated();
});
