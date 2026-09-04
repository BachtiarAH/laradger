<?php

use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    Sanctum::actingAs($this->user);
});

test('account code must be unique within same tenant (validation 422)', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'AS-0001', 'type' => 'asset']);

    $this->postJson("/api/v1/{$this->tenant->slug}/accounts", [
        'code' => 'AS-0001',
        'name' => 'Duplicate Cash',
        'type' => 'asset',
        'currency' => 'IDR',
        'status' => 'active',
    ])->assertStatus(422)->assertJsonValidationErrors(['code']);
});

test('same account code allowed across different tenants', function () {
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
    ])->assertCreated()->assertJsonPath('data.code', 'AS-0001');

    expect(Account::where('code', 'AS-0001')->count())->toBe(2);
});

test('accounts code unique is enforced at DB level (tenant_id, code)', function () {
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'AS-0099', 'type' => 'asset']);

    expect(function () {
        DB::table('accounts')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'code' => 'AS-0099',
            'name' => 'DB duplicate',
            'type' => 'asset',
            'currency' => 'IDR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(UniqueConstraintViolationException::class);
});

test('journal reference must be unique within same tenant (validation 422)', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-2026-0001']);

    $this->postJson("/api/v1/{$this->tenant->slug}/journals", [
        'transaction_date' => '2026-08-01',
        'description' => 'Duplicate reference',
        'reference' => $journal->reference,
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $account->id, 'debit' => 100.00],
            ['account_id' => $account->id, 'credit' => 100.00],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['reference']);
});

test('same journal reference allowed across different tenants', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $journal = Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-CROSS-001']);

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
            ['account_id' => $otherAccount->id, 'debit' => 100.00],
            ['account_id' => $otherAccount->id, 'credit' => 100.00],
        ],
    ])->assertCreated()->assertJsonPath('data.reference', 'JRN-CROSS-001');

    expect(Journal::where('reference', 'JRN-CROSS-001')->count())->toBe(2);
});

test('journals reference unique is enforced at DB level (tenant_id, reference)', function () {
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-DB-001']);

    expect(function () {
        DB::table('journals')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'transaction_date' => now(),
            'description' => 'DB duplicate',
            'reference' => 'JRN-DB-001',
            'status' => 'draft',
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(UniqueConstraintViolationException::class);
});

test('auto-generated account code is scoped per tenant', function () {
    // Tenant A has AS-0001
    Account::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'AS-0001', 'type' => 'asset']);

    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);

    // Other tenant has no accounts, should start at AS-0001 not AS-0002
    TenantContext::set($otherTenant);
    $code = Account::generateCode('asset');
    expect($code)->toBe('AS-0001');
    TenantContext::set($this->tenant);

    // Original tenant next should be AS-0002
    $next = Account::generateCode('asset');
    expect($next)->toBe('AS-0002');
});

test('auto-generated journal reference is scoped per tenant and per year', function () {
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-2026-0001', 'transaction_date' => '2026-01-01']);
    Journal::factory()->create(['tenant_id' => $this->tenant->id, 'reference' => 'JRN-2026-0002', 'transaction_date' => '2026-01-02']);

    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);

    TenantContext::set($otherTenant);
    $ref = Journal::nextReference(Carbon::parse('2026-06-01'));
    expect($ref)->toBe('JRN-2026-0001');
    TenantContext::set($this->tenant);

    $next = Journal::nextReference(Carbon::parse('2026-06-01'));
    expect($next)->toBe('JRN-2026-0003');
});
