<?php

use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\JournalTag;
use App\Models\JournalTemplate;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    $this->otherTenant = Tenant::factory()->create();
    Sanctum::actingAs($this->user);
});

afterEach(function () {
    TenantContext::flush();
});

// ---------------------------------------------------------------------
// 1. Unique invariants
// ---------------------------------------------------------------------

test('unique: account code duplicate within same tenant is rejected (422)', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'AS-0001', 'type' => 'asset']);

    $this->postJson("/api/v1/{$this->tenant->slug}/accounts", [
        'code' => 'AS-0001',
        'name' => 'Duplicate',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ])->assertStatus(422)->assertJsonValidationErrors(['code']);
});

test('unique: same account code allowed across different tenants', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'AS-0001', 'type' => 'asset']);

    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);
    Sanctum::actingAs($otherUser);

    $this->postJson("/api/v1/{$otherTenant->slug}/accounts", [
        'code' => 'AS-0001',
        'name' => 'Same code other tenant',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ])->assertCreated();

    expect(Account::withoutGlobalScopes()->where('code', 'AS-0001')->count())->toBe(2);
});

test('unique: DB enforces UNIQUE(tenant_id, code) on accounts', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'AS-0099', 'type' => 'asset']);

    expect(fn () => DB::table('accounts')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'code' => 'AS-0099',
        'name' => 'DB duplicate',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('unique: journal reference duplicate within same tenant is rejected (422)', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-2026-0001']);

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-08-01',
        'description' => 'Duplicate ref',
        'reference' => 'JRN-2026-0001',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $account->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $account->id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['reference']);
});

test('unique: same journal reference allowed across different tenants', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-CROSS-001']);

    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);
    $otherAccount = Account::factory()->create(['tenant_id' => $otherTenant->id]);
    Sanctum::actingAs($otherUser);

    $this->postJson("/api/v1/{$otherTenant->slug}/journals", [
        'transaction_date' => '2026-08-01',
        'description' => 'Same ref other tenant',
        'reference' => 'JRN-CROSS-001',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $otherAccount->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $otherAccount->id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertCreated();

    expect(Journal::withoutGlobalScopes()->where('reference', 'JRN-CROSS-001')->count())->toBe(2);
});

test('unique: DB enforces UNIQUE(tenant_id, reference) on journals', function () {
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-DB-001']);

    expect(fn () => DB::table('journals')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'transaction_date' => now(),
        'description' => 'DB duplicate',
        'reference' => 'JRN-DB-001',
        'status' => 'draft',
        'source' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

// ---------------------------------------------------------------------
// 2. Race invariants (sequential generation per tenant)
// ---------------------------------------------------------------------

test('race: auto-generated account code is scoped per tenant and per type', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'AS-0001', 'type' => 'asset']);
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'LI-0001', 'type' => 'liability']);

    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);

    TenantContext::set($otherTenant);
    expect(Account::generateCode('asset'))->toBe('AS-0001');
    expect(Account::generateCode('liability'))->toBe('LI-0001');
    TenantContext::set($this->tenant);

    expect(Account::generateCode('asset'))->toBe('AS-0002');
    expect(Account::generateCode('liability'))->toBe('LI-0002');
    TenantContext::flush();
});

test('race: auto-generated journal reference is scoped per tenant and per year', function () {
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-2026-0001', 'transaction_date' => '2026-01-01']);
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-2026-0002', 'transaction_date' => '2026-01-02']);
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-2025-0001', 'transaction_date' => '2025-12-31']);

    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);

    TenantContext::set($otherTenant);
    expect(Journal::nextReference(Carbon::parse('2026-06-01')))->toBe('JRN-2026-0001');
    TenantContext::set($this->tenant);

    expect(Journal::nextReference(Carbon::parse('2026-06-01')))->toBe('JRN-2026-0003');
    expect(Journal::nextReference(Carbon::parse('2025-06-01')))->toBe('JRN-2025-0002');
    TenantContext::flush();
});

test('race: concurrent duplicate code triggers DB unique violation (retry path)', function () {
    // Simulate two processes generating the same code simultaneously.
    // First insert succeeds, second must hit UniqueConstraintViolationException.
    $code = 'AS-0099';
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => $code, 'type' => 'asset']);

    expect(fn () => Account::create([
        'tenant_id' => $this->tenant->id,
        'code' => $code,
        'name' => 'Race duplicate',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('race: next-code and next-reference APIs are tenant-scoped', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'AS-0001', 'type' => 'asset']);
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-2026-0001', 'transaction_date' => '2026-01-01']);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/next-code?type=asset")
        ->assertOk()
        ->assertJsonPath('data.code', 'AS-0002');

    $this->getJson("/api/v1/{$this->tenant->slug}/journals/next-reference?transaction_date=2026-06-01")
        ->assertOk()
        ->assertJsonPath('data.reference', 'JRN-2026-0002');

    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);
    Sanctum::actingAs($otherUser);

    $this->getJson("/api/v1/{$otherTenant->slug}/accounts/next-code?type=asset")
        ->assertOk()
        ->assertJsonPath('data.code', 'AS-0001');

    $this->getJson("/api/v1/{$otherTenant->slug}/journals/next-reference?transaction_date=2026-06-01")
        ->assertOk()
        ->assertJsonPath('data.reference', 'JRN-2026-0001');
});

// ---------------------------------------------------------------------
// 3. Parent account rules
// ---------------------------------------------------------------------

test('parent: header account cannot be used as transaction line account', function () {
    $header = Account::factory()->create(['tenant_id' => $this->tenant->id, 'is_header' => true, 'type' => 'asset']);

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-08-01',
        'description' => 'Header misuse',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $header->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => $header->id, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertStatus(422);
});

test('parent: leaf account cannot have children', function () {
    $leaf = Account::factory()->create(['tenant_id' => $this->tenant->id, 'is_header' => false, 'type' => 'asset']);

    $this->postJson("/api/v1/{$this->tenant->slug}/accounts", [
        'name' => 'Child of leaf',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
        'is_header' => false,
        'parent_id' => $leaf->id,
    ])->assertStatus(422);
});

test('parent: parent must be a header account', function () {
    $leafParent = Account::factory()->create(['tenant_id' => $this->tenant->id, 'is_header' => false, 'type' => 'asset']);

    $this->postJson("/api/v1/{$this->tenant->slug}/accounts", [
        'name' => 'Child with leaf parent',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
        'parent_id' => $leafParent->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
});

test('parent: header with existing journal lines cannot become leaf or get children', function () {
    $header = Account::factory()->create(['tenant_id' => $this->tenant->id, 'is_header' => true, 'type' => 'asset']);
    $leaf = Account::factory()->create(['tenant_id' => $this->tenant->id, 'is_header' => false, 'type' => 'asset', 'parent_id' => $header->id]);

    // Create a transaction on the leaf (allowed)
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $journal->lines()->create(['account_id' => $leaf->id, 'debit' => 100, 'credit' => 0]);

    // Make leaf a header after it has lines → must fail
    $this->putJson("/api/v1/{$this->tenant->slug}/accounts/{$leaf->id}", [
        'name' => $leaf->name,
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
        'is_header' => true,
    ])->assertStatus(422);

    // Header with child that has lines cannot get its own lines
    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-08-01',
        'description' => 'Header with child lines',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $header->id, 'debit' => 50, 'credit' => 0],
            ['account_id' => $leaf->id, 'debit' => 0, 'credit' => 50],
        ],
    ])->assertStatus(422);
});

test('parent: cross-tenant parent is rejected', function () {
    $otherTenant = Tenant::factory()->create();
    $foreignHeader = Account::factory()->create(['tenant_id' => $otherTenant->id, 'is_header' => true, 'type' => 'asset']);

    $this->postJson("/api/v1/{$this->tenant->slug}/accounts", [
        'name' => 'Cross tenant child',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
        'parent_id' => $foreignHeader->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
});

test('parent: account cannot be its own parent', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id, 'is_header' => true, 'type' => 'asset']);

    $this->putJson("/api/v1/{$this->tenant->slug}/accounts/{$account->id}", [
        'name' => $account->name,
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
        'is_header' => true,
        'parent_id' => $account->id,
    ])->assertStatus(422);
});

// ---------------------------------------------------------------------
// 4. Posted journal immutability
// ---------------------------------------------------------------------

test('posted lock: draft journal can be updated and deleted', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);

    $this->putJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}", [
        'transaction_date' => '2026-08-01',
        'description' => 'Updated draft',
        'reference' => $journal->reference,
        'status' => 'draft',
        'source' => 'manual',
    ])->assertOk();

    $this->deleteJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}")->assertNoContent();
    expect(Journal::withoutGlobalScopes()->find($journal->id))->toBeNull();
});

test('posted lock: posted journal cannot be updated or deleted', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);

    $this->putJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}", [
        'transaction_date' => '2026-08-01',
        'description' => 'Tampered',
        'reference' => $journal->reference,
        'status' => 'posted',
        'source' => 'manual',
    ])->assertForbidden();

    $this->deleteJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}")->assertForbidden();
});

test('posted lock: archived journal cannot be updated or deleted', function () {
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

test('posted lock: lines on posted journal are immutable', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $line = $journal->lines()->create(['account_id' => $account->id, 'debit' => 100, 'credit' => 0, 'line_number' => 1]);

    // Model-level guard: editing a line on posted journal throws ValidationException
    expect(fn () => tap($line, fn ($l) => $l->update(['debit' => 999])))->toThrow(ValidationException::class);

    // Deleting line on posted journal is blocked
    expect(fn () => $line->delete())->toThrow(ValidationException::class);

    // API: adding a new line to posted journal is forbidden
    $this->postJson("/api/v1/{$this->tenant->slug}/journal-lines", [
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'debit' => 50,
    ])->assertForbidden();
});

test('posted lock: draft journal with lines can be deleted and cascades', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);
    $line = $journal->lines()->create(['account_id' => $account->id, 'debit' => 100, 'credit' => 0, 'line_number' => 1]);

    $this->deleteJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}")->assertNoContent();

    expect(Journal::withoutGlobalScopes()->find($journal->id))->toBeNull();
    expect(JournalLine::withoutGlobalScopes()->find($line->id))->toBeNull();
});

test('posted lock: reversal creates opposite lines and blocks double reversal', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'posted']);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => 1000, 'credit' => 0, 'line_number' => 1]);
    $journal->lines()->create(['account_id' => $account->id, 'debit' => 0, 'credit' => 1000, 'line_number' => 2]);

    $resp = $this->postJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}/reverse")->assertCreated();
    expect($resp->json('data.reverse_from_id'))->toBe($journal->id);

    // Second reversal must be rejected with 409
    $this->postJson("/api/v1/{$this->tenant->slug}/journals/{$journal->id}/reverse")->assertStatus(409);
    expect(Journal::withoutGlobalScopes()->where('reverse_from_id', $journal->id)->count())->toBe(1);

    // Draft cannot be reversed
    $draft = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);
    $this->postJson("/api/v1/{$this->tenant->slug}/journals/{$draft->id}/reverse")->assertForbidden();
});

// ---------------------------------------------------------------------
// 5. Tenant isolation (fail-closed)
// ---------------------------------------------------------------------

test('tenant isolation: API isolates accounts, journals, budgets, tags, templates, allocations', function () {
    $ownAccount = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherAccount = Account::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $ownJournal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'draft']);
    $otherJournal = Journal::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $ownTag = Tag::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherTag = Tag::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $ownBudget = Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);
    $otherBudget = Budget::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $ownTemplate = JournalTemplate::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherTemplate = JournalTemplate::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $ownAllocation = Allocation::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherAllocation = Allocation::factory()->create(['tenant_id' => $this->otherTenant->id]);

    // Lists are isolated
    $this->getJson("/api/v1/{$this->tenant->slug}/accounts")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownAccount->id);
    $this->getJson("/api/v1/{$this->tenant->slug}/journals")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownJournal->id);
    $this->getJson("/api/v1/{$this->tenant->slug}/tags")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownTag->id);
    $this->getJson("/api/v1/{$this->tenant->slug}/budgets")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownBudget->id);
    $this->getJson("/api/v1/{$this->tenant->slug}/journal-templates")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownTemplate->id);
    $this->getJson("/api/v1/{$this->tenant->slug}/allocations")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownAllocation->id);

    // Show cross-tenant returns 404, not leak
    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/{$otherAccount->id}")->assertNotFound();
    $this->getJson("/api/v1/{$this->tenant->slug}/journals/{$otherJournal->id}")->assertNotFound();
    $this->getJson("/api/v1/{$this->tenant->slug}/tags/{$otherTag->id}")->assertNotFound();
    $this->getJson("/api/v1/{$this->tenant->slug}/budgets/{$otherBudget->id}")->assertNotFound();
    $this->getJson("/api/v1/{$this->tenant->slug}/journal-templates/{$otherTemplate->id}")->assertNotFound();
    $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$otherAllocation->id}")->assertNotFound();
});

test('tenant isolation: journal lines and journal tags are isolated via parent journal', function () {
    $ownJournal = Journal::factory()->create(['tenant_id' => $this->tenant->id]);
    $ownAccount = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $ownLine = $ownJournal->lines()->create(['account_id' => $ownAccount->id, 'debit' => 100, 'credit' => 0, 'line_number' => 1]);

    $otherJournal = Journal::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $otherAccount = Account::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $otherLine = $otherJournal->lines()->create(['account_id' => $otherAccount->id, 'debit' => 200, 'credit' => 0, 'line_number' => 1]);

    $ownTag = Tag::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherTag = Tag::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $ownJt = JournalTag::create(['journal_id' => $ownJournal->id, 'tag_id' => $ownTag->id]);
    $otherJt = JournalTag::create(['journal_id' => $otherJournal->id, 'tag_id' => $otherTag->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/journal-lines")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownLine->id);
    $this->getJson("/api/v1/{$this->tenant->slug}/journal-lines/{$otherLine->id}")->assertNotFound();
    $this->getJson("/api/v1/{$this->tenant->slug}/journal-tags")->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/{$this->tenant->slug}/journal-tags/{$otherJt->id}")->assertNotFound();
});

test('tenant isolation: fail-closed — without TenantContext no SELECT * leaks', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'AS-9001', 'type' => 'asset']);
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-FAIL-001']);
    Allocation::factory()->create(['tenant_id' => $this->tenant->id]);
    Budget::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);
    Tag::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'FailTag']);
    JournalTemplate::factory()->create(['tenant_id' => $this->tenant->id]);

    // Ensure no TenantContext is set (fail-closed)
    TenantContext::flush();

    expect(Account::query()->count())->toBe(0);
    expect(Journal::query()->count())->toBe(0);
    expect(JournalLine::query()->count())->toBe(0);
    expect(Allocation::query()->count())->toBe(0);
    expect(Budget::query()->count())->toBe(0);
    expect(Tag::query()->count())->toBe(0);
    expect(JournalTemplate::query()->count())->toBe(0);
    expect(AuditLog::query()->count())->toBe(0);

    // Raw SQL still respects 1=0 (no leak)
    expect(Account::query()->toSql())->toContain('1 = 0');
    expect(JournalLine::query()->toSql())->toContain('1 = 0');

    // Explicit system context bypasses fail-closed (admin/scheduler)
    TenantContext::runInSystemContext(function () {
        expect(Account::query()->count())->toBeGreaterThan(0);
        expect(Journal::query()->count())->toBeGreaterThan(0);
        expect(Allocation::query()->count())->toBeGreaterThan(0);
    });

    // withoutGlobalScopes also bypasses (explicit opt-out)
    expect(Account::withoutGlobalScopes()->count())->toBeGreaterThan(0);
});

test('tenant isolation: cross-tenant access via API returns 404 (not 403 leak)', function () {
    $otherAccount = Account::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $otherJournal = Journal::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $otherTag = Tag::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $otherBudget = Budget::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $otherAllocation = Allocation::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $otherTemplate = JournalTemplate::factory()->create(['tenant_id' => $this->otherTenant->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/accounts/{$otherAccount->id}")->assertNotFound();
    $this->putJson("/api/v1/{$this->tenant->slug}/accounts/{$otherAccount->id}", [
        'name' => 'Hacked', 'type' => 'asset', 'currency' => 'IDR', 'status' => 'active',
    ])->assertNotFound();
    $this->deleteJson("/api/v1/{$this->tenant->slug}/accounts/{$otherAccount->id}")->assertNotFound();

    $this->getJson("/api/v1/{$this->tenant->slug}/journals/{$otherJournal->id}")->assertNotFound();
    $this->getJson("/api/v1/{$this->tenant->slug}/tags/{$otherTag->id}")->assertNotFound();
    $this->getJson("/api/v1/{$this->tenant->slug}/budgets/{$otherBudget->id}")->assertNotFound();
    $this->getJson("/api/v1/{$this->tenant->slug}/allocations/{$otherAllocation->id}")->assertNotFound();
    $this->getJson("/api/v1/{$this->tenant->slug}/journal-templates/{$otherTemplate->id}")->assertNotFound();
});

test('tenant isolation: audit logs are isolated between tenants', function () {
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id]);
    $own = AuditLog::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'journal_id' => $journal->id]);
    $otherJournal = Journal::factory()->create(['tenant_id' => $this->otherTenant->id]);
    $other = AuditLog::factory()->create(['tenant_id' => $this->otherTenant->id, 'user_id' => $this->user->id, 'journal_id' => $otherJournal->id]);

    $this->getJson("/api/v1/{$this->tenant->slug}/audit-logs")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson("/api/v1/{$this->tenant->slug}/audit-logs/{$other->id}")->assertNotFound();
});
