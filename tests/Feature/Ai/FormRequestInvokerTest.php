<?php

use App\Http\Requests\StoreJournalRequest;
use App\Http\Requests\UpdateJournalRequest;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Tools\Support\FormRequestInvoker;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

it('validates a payload and exposes the route bound model', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($user, ['role' => 'owner']);
    Sanctum::actingAs($user);
    TenantContext::set($tenant);

    Account::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Cash', 'type' => 'asset']);
    $expense = Account::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Coffee', 'type' => 'expense']);
    $cash = Account::query()->where('name', 'Cash')->firstOrFail();

    $request = app(FormRequestInvoker::class)->make(StoreJournalRequest::class, [
        'transaction_date' => '2026-08-17',
        'description' => 'Coffee',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $expense->id, 'debit' => '10.00', 'credit' => null],
            ['account_id' => $cash->id, 'debit' => null, 'credit' => '10.00'],
        ],
    ]);

    expect($request)->toBeInstanceOf(StoreJournalRequest::class)
        ->and($request->validated('description'))->toBe('Coffee');
});

it('rejects an invalid payload with field errors instead of writing anything', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($user, ['role' => 'owner']);
    Sanctum::actingAs($user);

    $cash = Account::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Cash', 'type' => 'asset']);
    $coffee = Account::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Coffee', 'type' => 'expense']);

    $lines = DB::table('journal_lines')->count();

    expect(fn () => app(FormRequestInvoker::class)->make(StoreJournalRequest::class, [
        'transaction_date' => '2026-08-17',
        'description' => 'Unbalanced',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $coffee->id, 'debit' => '10.00', 'credit' => null],
            ['account_id' => $cash->id, 'debit' => null, 'credit' => '5.00'],
        ],
    ]))->toThrow(ValidationException::class);

    expect(DB::table('journal_lines')->count())->toBe($lines);
});

it('rejects account ids belonging to another tenant', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $cash = Account::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Cash', 'type' => 'asset']);
    $coffee = Account::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Coffee', 'type' => 'expense']);

    $otherTenant = Tenant::factory()->create();
    $foreignAccount = Account::factory()->create(['tenant_id' => $otherTenant->id, 'type' => 'expense']);

    Sanctum::actingAs($owner);
    TenantContext::set($tenant);

    $lines = DB::table('journal_lines')->count();

    // The policy allows any authenticated user to create a journal, so this is
    // stopped by the tenant-scoped `exists` rules on the account ids instead.
    expect(fn () => app(FormRequestInvoker::class)->make(StoreJournalRequest::class, [
        'transaction_date' => '2026-08-17',
        'description' => 'Cross tenant',
        'status' => 'draft',
        'source' => 'manual',
        'lines' => [
            ['account_id' => $foreignAccount->id, 'debit' => '10.00', 'credit' => null],
            ['account_id' => $cash->id, 'debit' => null, 'credit' => '10.00'],
        ],
    ]))->toThrow(ValidationException::class);

    expect(DB::table('journal_lines')->count())->toBe($lines);
});

it('binds a route parameter so authorize and rules can see the model', function () {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($user, ['role' => 'owner']);
    Sanctum::actingAs($user);
    TenantContext::set($tenant);

    $cash = Account::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Cash', 'type' => 'asset']);
    $coffee = Account::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Coffee', 'type' => 'expense']);

    $journal = Journal::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'posted',
    ]);

    // JournalPolicy::update requires a draft, so this only passes if the route
    // parameter is actually visible to authorize().
    expect(fn () => app(FormRequestInvoker::class)->make(
        UpdateJournalRequest::class,
        [
            'transaction_date' => '2026-08-17',
            'description' => 'Posted journals are immutable',
            'status' => 'posted',
            'source' => 'manual',
            'lines' => [
                ['account_id' => $coffee->id, 'debit' => '10.00', 'credit' => null],
                ['account_id' => $cash->id, 'debit' => null, 'credit' => '10.00'],
            ],
        ],
        ['journal' => $journal],
    ))->toThrow(AuthorizationException::class);
});
