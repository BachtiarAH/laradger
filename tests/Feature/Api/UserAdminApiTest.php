<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

function actingAsAdmin(): User
{
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($admin);

    return $admin;
}

test('guests cannot access the admin user list', function () {
    $this->getJson('/api/v1/admin/users')->assertUnauthorized();
});

test('a regular user cannot access the admin area', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/admin/users')->assertForbidden();
});

test('a platform admin can list users', function () {
    actingAsAdmin();
    User::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/admin/users');

    $response->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonStructure([
            'data' => [
                ['id', 'name', 'email', 'is_admin', 'status'],
            ],
            'links',
            'meta',
        ]);
});

test('the admin user list can be filtered by status', function () {
    actingAsAdmin();
    User::factory()->suspended()->create(['email' => 'suspended@example.com']);
    User::factory()->create(['email' => 'active@example.com']);

    $response = $this->getJson('/api/v1/admin/users?status=suspended');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'suspended@example.com');
});

test('the admin user list can be searched by name or email', function () {
    actingAsAdmin();
    User::factory()->create(['name' => 'Findable Person', 'email' => 'one@example.com']);
    User::factory()->create(['name' => 'Another One', 'email' => 'findable@example.com']);

    $this->getJson('/api/v1/admin/users?search=findable')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/admin/users?search=findable%40')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'findable@example.com');
});

test('an admin can create a user with an initial password', function () {
    actingAsAdmin();

    $response = $this->postJson('/api/v1/admin/users', [
        'name' => 'New Person',
        'email' => 'newperson@example.com',
        'password' => 'secret-pass',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'New Person')
        ->assertJsonPath('data.email', 'newperson@example.com')
        ->assertJsonPath('data.is_admin', false)
        ->assertJsonPath('data.status', 'active');

    $user = User::where('email', 'newperson@example.com')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('secret-pass', $user->password))->toBeTrue();

    $login = $this->postJson('/api/v1/login', [
        'email' => 'newperson@example.com',
        'password' => 'secret-pass',
    ]);

    $login->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

test('creating a user with a duplicate email is rejected', function () {
    actingAsAdmin();
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Dup',
        'email' => 'taken@example.com',
        'password' => 'secret-pass',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('creating a user with a short password is rejected', function () {
    actingAsAdmin();

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Weak',
        'email' => 'weak@example.com',
        'password' => 'short',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('an admin can update a user details and reset the password', function () {
    actingAsAdmin();
    $user = User::factory()->create(['password' => bcrypt('old-password')]);

    $this->putJson("/api/v1/admin/users/{$user->id}", [
        'name' => 'Renamed Person',
        'email' => 'renamed@example.com',
        'password' => 'new-password',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Renamed Person')
        ->assertJsonPath('data.email', 'renamed@example.com');

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('old-password', $user->fresh()->password))->toBeFalse();
});

test('an admin can create another dedicated admin account', function () {
    actingAsAdmin();

    $response = $this->postJson('/api/v1/admin/users', [
        'name' => 'Staff Admin',
        'email' => 'staff-admin@example.com',
        'password' => 'secret-pass',
        'is_admin' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'staff-admin@example.com')
        ->assertJsonPath('data.is_admin', true);

    expect(User::where('email', 'staff-admin@example.com')->first()->isAdmin())->toBeTrue();
});

test('admin access cannot be granted to or revoked from an existing account', function () {
    actingAsAdmin();
    $user = User::factory()->create();

    $this->putJson("/api/v1/admin/users/{$user->id}", ['is_admin' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['is_admin']);

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('a regular user cannot create or update users', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Nope',
        'email' => 'nope@example.com',
        'password' => 'secret-pass',
    ])->assertForbidden();

    $this->putJson("/api/v1/admin/users/{$user->id}", ['name' => 'Nope'])
        ->assertForbidden();
});

test('an admin cannot change their own status', function () {
    $admin = actingAsAdmin();

    $this->putJson("/api/v1/admin/users/{$admin->id}", ['status' => 'suspended'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

test('suspending a user revokes their tokens and blocks login', function () {
    $admin = actingAsAdmin();
    $user = User::factory()->create();
    $user->createToken('api-token');
    expect($user->tokens()->count())->toBe(1);

    $this->putJson("/api/v1/admin/users/{$user->id}", ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    expect($user->tokens()->count())->toBe(0);

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('reactivating a suspended user allows login again', function () {
    actingAsAdmin();
    $user = User::factory()->suspended()->create(['password' => bcrypt('password')]);

    $this->putJson("/api/v1/admin/users/{$user->id}", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();
});

test('terminating a user is reversible and blocks login while terminated', function () {
    $admin = actingAsAdmin();
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $this->putJson("/api/v1/admin/users/{$user->id}", ['status' => 'terminated'])
        ->assertOk()
        ->assertJsonPath('data.status', 'terminated');

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(422);

    // Restore the account — the admin can always bring it back.
    $this->putJson("/api/v1/admin/users/{$user->id}", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();
});

test('admin update with an empty password keeps the existing password', function () {
    actingAsAdmin();
    $user = User::factory()->create(['password' => bcrypt('original-password')]);

    $this->putJson("/api/v1/admin/users/{$user->id}", ['name' => 'Kept Password', 'password' => ''])
        ->assertOk()
        ->assertJsonPath('data.name', 'Kept Password');

    expect(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
});

test('updating a user to a duplicate email is rejected', function () {
    actingAsAdmin();
    $user = User::factory()->create(['email' => 'first@example.com']);
    User::factory()->create(['email' => 'second@example.com']);

    $this->putJson("/api/v1/admin/users/{$user->id}", ['email' => 'second@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});
