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

test('journal lines can be listed', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id]);
    $account = Account::factory()->create();
    $journal->lines()->create([
        'account_id' => $account->id,
        'debit' => 500.00,
        'credit' => 0,
        'description' => 'Test line',
    ]);

    $this->getJson('/api/v1/journal-lines')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a journal line can be created', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'draft']);
    $account = Account::factory()->create();

    $this->postJson('/api/v1/journal-lines', [
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'debit' => 250.00,
        'description' => 'New line',
    ])->assertCreated()->assertJsonPath('data.debit', '250.00');
});

test('a journal line requires debit or credit', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'draft']);
    $account = Account::factory()->create();

    $this->postJson('/api/v1/journal-lines', [
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'description' => 'No amount',
    ])->assertStatus(422)->assertJsonValidationErrors(['credit']);
});

test('a journal line on a posted journal cannot be updated or deleted', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'posted']);
    $account = Account::factory()->create();
    $line = $journal->lines()->create([
        'account_id' => $account->id,
        'debit' => 500.00,
        'credit' => 0,
        'description' => 'Locked line',
    ]);

    $this->putJson("/api/v1/journal-lines/{$line->id}", [
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'debit' => 999.00,
    ])->assertForbidden();

    $this->deleteJson("/api/v1/journal-lines/{$line->id}")->assertForbidden();
});

test('a journal line on a draft journal can be updated and deleted', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'draft']);
    $account = Account::factory()->create();
    $line = $journal->lines()->create([
        'account_id' => $account->id,
        'debit' => 500.00,
        'credit' => 0,
        'description' => 'Editable line',
    ]);

    $this->putJson("/api/v1/journal-lines/{$line->id}", [
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'debit' => 600.00,
    ])->assertOk()->assertJsonPath('data.debit', '600.00');

    $this->deleteJson("/api/v1/journal-lines/{$line->id}")->assertNoContent();
});

test('tags can be listed and created', function () {
    Tag::factory()->count(2)->create();

    $this->getJson('/api/v1/tags')->assertOk()->assertJsonCount(2, 'data');

    $this->postJson('/api/v1/tags', ['name' => 'Urgent', 'type' => 'priority'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Urgent');
});

test('journal tags can be attached', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'draft']);
    $tag = Tag::factory()->create();

    $this->postJson('/api/v1/journal-tags', [
        'journal_id' => $journal->id,
        'tag_id' => $tag->id,
    ])->assertCreated();

    $this->assertDatabaseHas('journal_tags', [
        'journal_id' => $journal->id,
        'tag_id' => $tag->id,
    ]);
});

test('tags cannot be attached to a posted journal', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id, 'status' => 'posted']);
    $tag = Tag::factory()->create();

    $this->postJson('/api/v1/journal-tags', [
        'journal_id' => $journal->id,
        'tag_id' => $tag->id,
    ])->assertForbidden();
});

test('audit logs can be listed', function () {
    $journal = Journal::factory()->create(['user_id' => $this->user->id]);

    $this->user->auditLogs()->create([
        'action' => 'journal.created',
        'before' => null,
        'after' => ['status' => 'posted'],
        'reason' => 'Created via API',
        'journal_id' => $journal->id,
    ]);

    $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonCount(1, 'data');
});
