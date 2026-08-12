<?php

use App\Models\Account;
use App\Models\Journal;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

test('journals can be listed and filtered by status', function () {
    Journal::factory()->count(2)->create(['user_id' => $this->user->id, 'status' => 'posted']);
    Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'draft']);

    $this->getJson('/api/v1/journals?status=posted')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a journal can be created with lines and tags', function () {
    $account = Account::factory()->create();
    $tag = Tag::factory()->create();

    $response = $this->postJson('/api/v1/journals', [
        'transaction_date' => '2026-08-01',
        'description' => 'Initial capital injection',
        'reference' => 'JRN-TEST-001',
        'status' => 'posted',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $account->id, 'debit' => 1000.00, 'credit' => 0, 'description' => 'Cash'],
            ['account_id' => $account->id, 'debit' => 0, 'credit' => 1000.00, 'description' => 'Equity'],
        ],
        'tags' => [$tag->id],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.reference', 'JRN-TEST-001')
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonCount(1, 'data.tags');
});

test('creating a journal validates nested lines', function () {
    $this->postJson('/api/v1/journals', [
        'transaction_date' => '2026-08-01',
        'description' => 'Missing lines',
        'reference' => 'JRN-TEST-002',
        'status' => 'posted',
        'source' => 'manual',
        'lines' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines']);
});

test('a journal belongs to the authenticated user', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id]);
    $otherUser = User::factory()->create();
    $otherJournal = Journal::factory()->create(['user_id' => $otherUser->id]);

    $this->getJson("/api/v1/journals/{$journal->id}")->assertOk();
    $this->getJson("/api/v1/journals/{$otherJournal->id}")->assertForbidden();
});

test('a draft journal can be updated', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'draft']);

    $this->putJson("/api/v1/journals/{$journal->id}", [
        'transaction_date' => '2026-08-01',
        'description' => 'Updated description',
        'reference' => $journal->reference,
        'status' => 'posted',
        'source' => 'manual',
    ])->assertOk()->assertJsonPath('data.status', 'posted');
});

test('a draft journal can be deleted', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'draft']);

    $this->deleteJson("/api/v1/journals/{$journal->id}")->assertNoContent();
});

test('a posted journal cannot be updated', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'posted']);

    $this->putJson("/api/v1/journals/{$journal->id}", [
        'transaction_date' => '2026-08-01',
        'description' => 'Tampered',
        'reference' => $journal->reference,
        'status' => 'posted',
        'source' => 'manual',
    ])->assertForbidden();
});

test('an archived journal cannot be updated or deleted', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'archived']);

    $this->putJson("/api/v1/journals/{$journal->id}", [
        'transaction_date' => '2026-08-01',
        'description' => 'Tampered',
        'reference' => $journal->reference,
        'status' => 'archived',
        'source' => 'manual',
    ])->assertForbidden();

    $this->deleteJson("/api/v1/journals/{$journal->id}")->assertForbidden();
});

test('a posted journal cannot be deleted', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'posted']);

    $this->deleteJson("/api/v1/journals/{$journal->id}")->assertForbidden();
});

test('a posted journal cannot receive new lines', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'posted']);
    $account = Account::factory()->create();

    $this->postJson('/api/v1/journal-lines', [
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'debit' => 500.00,
    ])->assertForbidden();
});

test('a journal can be reversed with opposite lines', function () {
    $account = Account::factory()->create();
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'posted']);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => 1000.00, 'credit' => 0, 'description' => 'Dr']);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => 0, 'credit' => 1000.00, 'description' => 'Cr']);

    $response = $this->postJson("/api/v1/journals/{$journal->id}/reverse");

    $response->assertCreated()
        ->assertJsonPath('data.reverse_from_id', $journal->id)
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonCount(2, 'data.lines');

    $reversal = Journal::where('reference', $response->json('data.reference'))->first();
    $this->assertDatabaseHas('journal_lines', [
        'journal_id' => $reversal->id,
        'debit' => 1000.00,
        'credit' => 0,
    ]);
    $this->assertDatabaseHas('journal_lines', [
        'journal_id' => $reversal->id,
        'debit' => 0,
        'credit' => 1000.00,
    ]);
});

test('a draft journal cannot be reversed', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'draft']);

    $this->postJson("/api/v1/journals/{$journal->id}/reverse")->assertForbidden();
});
