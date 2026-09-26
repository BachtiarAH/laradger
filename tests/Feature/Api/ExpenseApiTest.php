<?php

use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    Sanctum::actingAs($this->user);

    $this->expenseAccount = Account::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => 'expense',
        'currency' => 'IDR',
    ]);
});

/**
 * Book an expense debit on a journal with the given transaction date.
 */
function bookExpense(
    object $tenant,
    Account $account,
    string $date,
    string $amount,
    string $status = 'posted',
    ?string $description = null,
): Journal {
    $journal = Journal::factory()->create([
        'tenant_id' => $tenant->id,
        'transaction_date' => $date,
        'status' => $status,
        'description' => $description ?? 'Expense journal',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'debit' => $amount,
        'credit' => '0.00',
    ]);

    return $journal;
}

test('expenses can be listed with their running total', function () {
    bookExpense($this->tenant, $this->expenseAccount, '2026-03-05', '750000.00');
    bookExpense($this->tenant, $this->expenseAccount, '2026-03-12', '250000.00');

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.totals.expense_total', '1000000.00')
        ->assertJsonPath('meta.totals.lines_count', 2)
        ->assertJsonPath('data.0.debit', '250000.00')
        ->assertJsonPath('data.0.account.id', $this->expenseAccount->id);
});

test('expenses are listed newest first by default', function () {
    $older = bookExpense($this->tenant, $this->expenseAccount, '2026-01-10', '100000.00');
    $newer = bookExpense($this->tenant, $this->expenseAccount, '2026-01-20', '200000.00');

    $response = $this->getJson("/api/v1/{$this->tenant->slug}/expenses")->assertOk();

    expect(array_column($response->json('data'), 'journal_id'))->toBe([$newer->id, $older->id]);
});

test('expenses can be filtered by date range', function () {
    bookExpense($this->tenant, $this->expenseAccount, '2026-01-10', '100000.00');
    bookExpense($this->tenant, $this->expenseAccount, '2026-02-10', '200000.00');
    bookExpense($this->tenant, $this->expenseAccount, '2026-03-10', '400000.00');

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses?from=2026-02-01&to=2026-02-28")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.debit', '200000.00')
        ->assertJsonPath('meta.totals.expense_total', '200000.00')
        ->assertJsonPath('meta.date_range.from', '2026-02-01')
        ->assertJsonPath('meta.date_range.to', '2026-02-28');
});

test('an open ended date range only bounds the missing side', function () {
    bookExpense($this->tenant, $this->expenseAccount, '2026-01-10', '100000.00');
    bookExpense($this->tenant, $this->expenseAccount, '2026-02-10', '200000.00');

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses?from=2026-02-01")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.debit', '200000.00')
        ->assertJsonPath('meta.totals.expense_total', '200000.00');

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses?to=2026-01-31")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.debit', '100000.00')
        ->assertJsonPath('meta.totals.expense_total', '100000.00');
});

test('expenses exclude other account types', function () {
    $assetAccount = Account::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => 'asset',
        'currency' => 'IDR',
    ]);

    bookExpense($this->tenant, $this->expenseAccount, '2026-01-10', '100000.00');

    $journal = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'transaction_date' => '2026-01-10',
        'status' => 'posted',
    ]);
    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $assetAccount->id,
        'debit' => '5000000.00',
        'credit' => '0.00',
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.totals.expense_total', '100000.00');
});

test('expenses exclude draft journals unless a status is requested', function () {
    bookExpense($this->tenant, $this->expenseAccount, '2026-01-10', '100000.00');
    bookExpense($this->tenant, $this->expenseAccount, '2026-01-11', '900000.00', 'draft');

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.totals.expense_total', '100000.00');

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses?status=draft")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.totals.expense_total', '900000.00');
});

test('expenses can be filtered by account and search term', function () {
    $otherExpense = Account::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => 'expense',
        'currency' => 'IDR',
    ]);

    bookExpense($this->tenant, $this->expenseAccount, '2026-01-10', '100000.00', 'posted', 'Makan siang Client');
    bookExpense($this->tenant, $otherExpense, '2026-01-11', '200000.00', 'posted', 'BensinPertamina');

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses?account_id={$this->expenseAccount->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.debit', '100000.00');

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses?search=makan")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.debit', '100000.00');
});

test('expenses can be sorted by amount', function () {
    bookExpense($this->tenant, $this->expenseAccount, '2026-01-10', '100000.00');
    bookExpense($this->tenant, $this->expenseAccount, '2026-01-11', '900000.00');

    $ascending = $this->getJson("/api/v1/{$this->tenant->slug}/expenses?sort_by=debit&sort_direction=asc")->assertOk();
    expect(array_column($ascending->json('data'), 'debit'))->toBe(['100000.00', '900000.00']);

    $descending = $this->getJson("/api/v1/{$this->tenant->slug}/expenses?sort_by=debit&sort_direction=desc")->assertOk();
    expect(array_column($descending->json('data'), 'debit'))->toBe(['900000.00', '100000.00']);
});

test('expenses are paginated', function () {
    foreach (range(1, 3) as $index) {
        bookExpense($this->tenant, $this->expenseAccount, '2026-01-1'.$index, '100000.00');
    }

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses?per_page=2&page=2")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.totals.lines_count', 3);
});

test('an unparsable date range is ignored', function () {
    bookExpense($this->tenant, $this->expenseAccount, '2026-01-10', '100000.00');

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses?from=not-a-date&to=also-bad")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.date_range.from', null)
        ->assertJsonPath('meta.date_range.to', null);
});

test('expenses are scoped to the tenant', function () {
    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);
    $otherAccount = Account::factory()->create([
        'tenant_id' => $otherTenant->id,
        'type' => 'expense',
        'currency' => 'IDR',
    ]);

    bookExpense($this->tenant, $this->expenseAccount, '2026-01-10', '100000.00');
    bookExpense($otherTenant, $otherAccount, '2026-01-10', '9000000.00');

    $this->getJson("/api/v1/{$this->tenant->slug}/expenses")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.totals.expense_total', '100000.00');
});

test('expense list agrees with the overview expense actual', function () {
    $this->travelTo(Carbon::parse('2026-03-20'));

    bookExpense($this->tenant, $this->expenseAccount, '2026-03-05', '750000.00');
    bookExpense($this->tenant, $this->expenseAccount, '2026-04-02', '300000.00');
    bookExpense($this->tenant, $this->expenseAccount, '2026-02-27', '150000.00');

    $expenses = $this->getJson("/api/v1/{$this->tenant->slug}/expenses?from=2026-03-01&to=2026-03-31")->assertOk();
    $overview = $this->getJson("/api/v1/{$this->tenant->slug}/overview?period=this_month")->assertOk();

    expect($expenses->json('meta.totals.expense_total'))->toBe($overview->json('data.expense.actual'));
})->after(fn () => $this->travelBack());
