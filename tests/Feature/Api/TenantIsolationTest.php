<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Journal;
use App\Models\JournalTag;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    $this->otherTenant = Tenant::factory()->create();

    Sanctum::actingAs($this->user);
});

test('accounts are isolated between tenants on list and show', function () {
    $own = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $other = Account::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/{$own->id}")->assertOk();
    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/{$other->id}")->assertNotFound();
});

test('a user cannot update or delete another tenant account', function () {
    $other = Account::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $this->putJson("/api/v1/{$this->tenant->slug}/accounts/{$other->id}", [
        'name' => 'Tampered',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ])->assertNotFound();

    $this->deleteJson("/api/v1/{$this->tenant->slug}/accounts/{$other->id}")->assertNotFound();

    $this->assertDatabaseHas('accounts', ['id' => $other->id]);
});

test('tags are isolated between tenants on list and show', function () {
    $own = Tag::factory()->create(['tenant_id' => $this->tenant->id]);
    $other = Tag::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/tags")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);

    $this->getJson("/api/v1/{$this->tenant->slug}/tags/{$own->id}")->assertOk();
    $this->getJson("/api/v1/{$this->tenant->slug}/tags/{$other->id}")->assertNotFound();
});

test('a user cannot update or delete another tenant tag', function () {
    $other = Tag::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $this->putJson("/api/v1/{$this->tenant->slug}/tags/{$other->id}", [
        'name' => 'Tampered',
        'type' => 'priority',
    ])->assertNotFound();

    $this->deleteJson("/api/v1/{$this->tenant->slug}/tags/{$other->id}")->assertNotFound();

    $this->assertDatabaseHas('tags', ['id' => $other->id]);
});

test('budgets are isolated between tenants', function () {
    $own = Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);
    $other = Budget::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/budgets")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);

    $this->getJson("/api/v1/{$this->tenant->slug}/budgets/{$other->id}")->assertNotFound();
});

test('audit logs are isolated between tenants', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id]);
    $own = AuditLog::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'journal_id' => $journal->id,
    ]);
    $otherJournal = Journal::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $other = AuditLog::factory()->create([
        'tenant_id' => $this->otherTenant->id,
        'user_id' => $this->user->id,
        'journal_id' => $otherJournal->id,
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/audit-logs")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);

    $this->getJson("/api/v1/{$this->tenant->slug}/audit-logs/{$own->id}")->assertOk();
    $this->getJson("/api/v1/{$this->tenant->slug}/audit-logs/{$other->id}")->assertNotFound();
});

test('journal lines are isolated between tenants', function () {
    $ownJournal = Journal::factory()->create(['tenant_id' => $this->tenant->id]);
    $ownAccount = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $own = $ownJournal->lines()->create([
        'account_id' => $ownAccount->id,
        'debit' => 100.00,
        'credit' => 0,
    ]);

    $otherJournal = Journal::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $otherAccount = Account::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $other = $otherJournal->lines()->create([
        'account_id' => $otherAccount->id,
        'debit' => 200.00,
        'credit' => 0,
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/journal-lines")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);

    $this->getJson("/api/v1/{$this->tenant->slug}/journal-lines/{$own->id}")->assertOk();
    $this->getJson("/api/v1/{$this->tenant->slug}/journal-lines/{$other->id}")->assertNotFound();
});

test('journal tags are isolated between tenants', function () {
    $ownJournal = Journal::factory()->create(['tenant_id' => $this->tenant->id]);
    $ownTag = Tag::factory()->create(['tenant_id' => $this->tenant->id]);
    $own = JournalTag::create(['journal_id' => $ownJournal->id, 'tag_id' => $ownTag->id]);

    $otherJournal = Journal::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $otherTag = Tag::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $other = JournalTag::create(['journal_id' => $otherJournal->id, 'tag_id' => $otherTag->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/journal-tags")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.journal_id', $own->journal_id)
        ->assertJsonPath('data.0.tag_id', $own->tag_id);
});

test('a journal line cannot be attached to another tenant journal', function () {
    $otherJournal = Journal::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->postJson("/api/v1/{$this->tenant->slug}/journal-lines", [
        'journal_id' => $otherJournal->id,
        'account_id' => $account->id,
        'debit' => 100.00,
    ])->assertStatus(422)->assertJsonValidationErrors(['journal_id']);
});

test('a tag cannot be attached to another tenant journal', function () {
    $otherJournal = Journal::factory()->create(['tenant_id' => $this->otherTenant->id, 'status' => 'draft']);
    $tag = Tag::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->postJson("/api/v1/{$this->tenant->slug}/journal-tags", [
        'journal_id' => $otherJournal->id,
        'tag_id' => $tag->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['journal_id']);
});
