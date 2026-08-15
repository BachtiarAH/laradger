<?php

use App\Models\Journal;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('returns a clean 403 JSON response when a user is not a tenant member', function () {
    $owner = User::factory()->create();
    $tenant = createTenantForUser($owner);
    $other = User::factory()->create();

    $journal = Journal::factory()->create(['tenant_id' => $tenant->id]);

    $response = actingAs($other)
        ->withHeader('X-Tenant', $tenant->slug)
        ->getJson("/api/v1/journals/{$journal->id}");

    $response->assertStatus(403)
        ->assertJson(['message' => 'You are not a member of this tenant.'])
        ->assertJsonMissing(['exception', 'file', 'line', 'trace']);
});

it('returns a clean 403 JSON response for an immutable journal', function () {
    $user = User::factory()->create();
    $tenant = createTenantForUser($user);

    $journal = Journal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'posted']);

    $response = actingAs($user)
        ->withHeader('X-Tenant', $tenant->slug)
        ->putJson("/api/v1/journals/{$journal->id}", [
            'transaction_date' => now()->toDateString(),
            'description' => 'Edited',
            'reference' => 'JRN-TEST',
            'status' => 'posted',
            'source' => 'manual',
            'lines' => [],
        ]);

    $response->assertStatus(403)
        ->assertJson(['message' => 'This action is unauthorized.'])
        ->assertJsonMissing(['exception', 'file', 'line', 'trace']);
});
