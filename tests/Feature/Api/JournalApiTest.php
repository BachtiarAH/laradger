<?php

use App\Models\Account;
use App\Models\Journal;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    Sanctum::actingAs($this->user);
});

test('journals can be listed and filtered by status', function () {
    Journal::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);

    $this->getJson("/api/v1/{$this->tenant->slug}/journals?status=posted")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a journal can be created with lines and tags', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $tag = Tag::factory()->create(['tenant_id' => $this->tenant->id]);

    $response = $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
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
    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-08-01',
        'description' => 'Missing lines',
        'reference' => 'JRN-TEST-002',
        'status' => 'posted',
        'source' => 'manual',
        'lines' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines']);
});

test('a journal line can be created without a description', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-08-01',
        'description' => 'Line without description',
        'reference' => 'JRN-TEST-003',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $account->id, 'debit' => 1000.00],
            ['account_id' => $account->id, 'credit' => 1000.00],
        ],
    ])->assertCreated()
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.lines.0.description', null);
});

test('a journal reference is auto-generated when omitted', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-08-01',
        'description' => 'Auto reference',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $account->id, 'debit' => 500.00, 'description' => 'Dr'],
            ['account_id' => $account->id, 'credit' => 500.00, 'description' => 'Cr'],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.reference', 'JRN-2026-0001');

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-08-02',
        'description' => 'Second journal',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $account->id, 'debit' => 100.00, 'description' => 'Dr'],
            ['account_id' => $account->id, 'credit' => 100.00, 'description' => 'Cr'],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.reference', 'JRN-2026-0002');
});

test('journals are isolated between tenants', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherTenantJournal = Journal::factory()->create();

    $this->getJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}")->assertOk();
    $this->getJson("/api/v1/{$this->tenant->slug}/journals/{$otherTenantJournal->id}")->assertNotFound();
});

test('a draft journal can be updated', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);

    $this->putJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}", [
        'transaction_date' => '2026-08-01',
        'description' => 'Updated description',
        'reference' => $journal->reference,
        'status' => 'posted',
        'source' => 'manual',
    ])->assertOk()->assertJsonPath('data.status', 'posted');
});

test('a draft journal can be deleted', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);

    $this->deleteJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}")->assertNoContent();
});

test('a posted journal cannot be updated', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);

    $this->putJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}", [
        'transaction_date' => '2026-08-01',
        'description' => 'Tampered',
        'reference' => $journal->reference,
        'status' => 'posted',
        'source' => 'manual',
    ])->assertForbidden();
});

test('an archived journal cannot be updated or deleted', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'archived']);

    $this->putJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}", [
        'transaction_date' => '2026-08-01',
        'description' => 'Tampered',
        'reference' => $journal->reference,
        'status' => 'archived',
        'source' => 'manual',
    ])->assertForbidden();

    $this->deleteJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}")->assertForbidden();
});

test('a posted journal cannot be deleted', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);

    $this->deleteJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}")->assertForbidden();
});

test('a posted journal cannot receive new lines', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->postJson("/api/v1/{$this->tenant->slug}/journal-lines", [
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'debit' => 500.00,
    ])->assertForbidden();
});

test('a journal can be reversed with opposite lines', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => 1000.00, 'credit' => 0, 'description' => 'Dr']);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => 0, 'credit' => 1000.00, 'description' => 'Cr']);

    $response = $this->postJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}/reverse");

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
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);

    $this->postJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}/reverse")->assertForbidden();
});
