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

test('a user can log in and receive a token', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user', 'token']);
});

test('login fails with invalid credentials', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(422);
});

test('a user can log out', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/logout')->assertOk();
});
