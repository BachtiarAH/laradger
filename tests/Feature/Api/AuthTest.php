<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('guests are redirected to login for protected endpoints', function () {
    $this->getJson('/api/v1/accounts')->assertUnauthorized();
});

test('guests get a json 401 without the json accept header', function () {
    $this->get('/api/v1/accounts')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('a user can register and receive a token', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);
});

test('registration response includes the created tenant in user.tenants', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'tenant_name' => 'Acme Corp',
        'tenant_slug' => 'acme-corp',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.tenants.0.name', 'Acme Corp')
        ->assertJsonPath('user.tenants.0.slug', 'acme-corp')
        ->assertJsonPath('user.tenants.0.role', 'owner');
});

test('registration auto-creates a tenant and attaches the user as owner', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'tenant_name' => 'Acme Corp',
        'tenant_slug' => 'acme-corp',
    ]);

    $response->assertCreated()
        ->assertJsonPath('tenant.name', 'Acme Corp')
        ->assertJsonPath('tenant.slug', 'acme-corp');

    $user = User::where('email', 'jane@example.com')->first();

    expect($user->tenants)->toHaveCount(1);
    expect($user->tenants->first()->pivot->role)->toBe('owner');
});

test('a user can log in and receive a token', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $tenant = createTenantForUser($user);

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user', 'token'])
        ->assertJsonPath('user.tenants.0.id', $tenant->id)
        ->assertJsonPath('user.tenants.0.slug', $tenant->slug)
        ->assertJsonPath('user.tenants.0.role', 'owner');
});

test('login fails with invalid credentials', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(422);
});

test('a user can log out', function () {
    $user = User::factory()->create();
    $tenant = createTenantForUser($user);
    Sanctum::actingAs($user);

    $this->withHeader('X-Tenant', $tenant->slug)
        ->postJson('/api/v1/logout')->assertOk();
});
