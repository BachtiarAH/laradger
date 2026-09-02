<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    Sanctum::actingAs($this->user);
});

test('overview returns default this_month data', function () {
    $incomeAccount = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'income', 'currency' => 'IDR']);
    $expenseAccount = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'currency' => 'IDR']);

    $now = Carbon::now();
    $journal = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'transaction_date' => $now->toDateString(),
        'status' => 'posted',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $incomeAccount->id,
        'credit' => '1500000.00',
        'debit' => '0.00',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $expenseAccount->id,
        'debit' => '800000.00',
        'credit' => '0.00',
    ]);

    Budget::factory()->create([
        'tenant_id' => $this->tenant->id,
        'budget_type' => 'income',
        'amount' => '2000000.00',
        'starts_at' => $now->copy()->startOfMonth()->toDateString(),
        'ends_at' => $now->copy()->endOfMonth()->toDateString(),
    ]);

    Budget::factory()->create([
        'tenant_id' => $this->tenant->id,
        'budget_type' => 'expense',
        'amount' => '1000000.00',
        'starts_at' => $now->copy()->startOfMonth()->toDateString(),
        'ends_at' => $now->copy()->endOfMonth()->toDateString(),
    ]);

    $response = $this->getJson("/api/v1/{$this->tenant->slug}/overview")
        ->assertOk()
        ->assertJsonPath('data.period', 'this_month')
        ->assertJsonPath('data.income.actual', '1500000.00')
        ->assertJsonPath('data.income.budgeted', '2000000.00')
        ->assertJsonPath('data.expense.actual', '800000.00')
        ->assertJsonPath('data.expense.budgeted', '1000000.00')
        ->assertJsonPath('data.expense.remaining', '200000.00')
        ->assertJsonPath('data.expense.overspend', '0.00')
        ->assertJsonPath('data.unbudgeted_income', '-500000.00')
        ->assertJsonPath('data.net_budgeted', '1000000.00')
        ->assertJsonPath('data.safe_money', '500000.00')
        ->assertJsonPath('data.liabilities.balance', '0.00');
});

test('overview respects period query parameter', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'income', 'currency' => 'IDR']);

    $today = Carbon::today();
    $journal = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'transaction_date' => $today->toDateString(),
        'status' => 'posted',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'credit' => '500000.00',
        'debit' => '0.00',
    ]);

    $response = $this->getJson("/api/v1/{$this->tenant->slug}/overview?period=today")
        ->assertOk()
        ->assertJsonPath('data.period', 'today')
        ->assertJsonPath('data.income.actual', '500000.00');
});

test('overview ignores draft journals', function () {
    $incomeAccount = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'income', 'currency' => 'IDR']);

    $now = Carbon::now();
    $draftJournal = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'transaction_date' => $now->toDateString(),
        'status' => 'draft',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $draftJournal->id,
        'account_id' => $incomeAccount->id,
        'credit' => '999999.00',
        'debit' => '0.00',
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/overview")
        ->assertOk()
        ->assertJsonPath('data.income.actual', '0.00');
});

test('overview is scoped to the tenant', function () {
    $otherUser = User::factory()->create();
    $otherTenant = createTenantForUser($otherUser);

    $account = Account::factory()->create(['tenant_id' => $otherTenant->id, 'type' => 'income', 'currency' => 'IDR']);
    $journal = Journal::factory()->create([
        'tenant_id' => $otherTenant->id,
        'status' => 'posted',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'credit' => '500000.00',
        'debit' => '0.00',
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/overview")
        ->assertOk()
        ->assertJsonPath('data.income.actual', '0.00');
});

test('overview computes overspend when expense exceeds budget', function () {
    $expenseAccount = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'currency' => 'IDR']);

    $now = Carbon::now();
    $journal = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'transaction_date' => $now->toDateString(),
        'status' => 'posted',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $expenseAccount->id,
        'debit' => '1500000.00',
        'credit' => '0.00',
    ]);

    Budget::factory()->create([
        'tenant_id' => $this->tenant->id,
        'budget_type' => 'expense',
        'amount' => '1000000.00',
        'starts_at' => $now->copy()->startOfMonth()->toDateString(),
        'ends_at' => $now->copy()->endOfMonth()->toDateString(),
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/overview")
        ->assertOk()
        ->assertJsonPath('data.expense.actual', '1500000.00')
        ->assertJsonPath('data.expense.budgeted', '1000000.00')
        ->assertJsonPath('data.expense.remaining', '-500000.00')
        ->assertJsonPath('data.expense.overspend', '500000.00')
        ->assertJsonPath('data.safe_money', '-1500000.00')
        ->assertJsonPath('data.liabilities.balance', '0.00');
});

test('overview computes safe money with overspend', function () {
    $incomeAccount = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'income', 'currency' => 'IDR']);
    $expenseAccount = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'currency' => 'IDR']);

    $now = Carbon::now();
    $journal = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'transaction_date' => $now->toDateString(),
        'status' => 'posted',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $incomeAccount->id,
        'credit' => '2000000.00',
        'debit' => '0.00',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $expenseAccount->id,
        'debit' => '1500000.00',
        'credit' => '0.00',
    ]);

    Budget::factory()->create([
        'tenant_id' => $this->tenant->id,
        'budget_type' => 'income',
        'amount' => '2000000.00',
        'starts_at' => $now->copy()->startOfMonth()->toDateString(),
        'ends_at' => $now->copy()->endOfMonth()->toDateString(),
    ]);

    Budget::factory()->create([
        'tenant_id' => $this->tenant->id,
        'budget_type' => 'expense',
        'amount' => '1000000.00',
        'starts_at' => $now->copy()->startOfMonth()->toDateString(),
        'ends_at' => $now->copy()->endOfMonth()->toDateString(),
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/overview")
        ->assertOk()
        ->assertJsonPath('data.income.actual', '2000000.00')
        ->assertJsonPath('data.expense.budgeted', '1000000.00')
        ->assertJsonPath('data.expense.overspend', '500000.00')
        ->assertJsonPath('data.safe_money', '500000.00')
        ->assertJsonPath('data.liabilities.balance', '0.00');
});

test('overview safe money can be negative', function () {
    $incomeAccount = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'income', 'currency' => 'IDR']);
    $expenseAccount = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'currency' => 'IDR']);

    $now = Carbon::now();
    $journal = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'transaction_date' => $now->toDateString(),
        'status' => 'posted',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $incomeAccount->id,
        'credit' => '500000.00',
        'debit' => '0.00',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $expenseAccount->id,
        'debit' => '800000.00',
        'credit' => '0.00',
    ]);

    Budget::factory()->create([
        'tenant_id' => $this->tenant->id,
        'budget_type' => 'income',
        'amount' => '500000.00',
        'starts_at' => $now->copy()->startOfMonth()->toDateString(),
        'ends_at' => $now->copy()->endOfMonth()->toDateString(),
    ]);

    Budget::factory()->create([
        'tenant_id' => $this->tenant->id,
        'budget_type' => 'expense',
        'amount' => '1000000.00',
        'starts_at' => $now->copy()->startOfMonth()->toDateString(),
        'ends_at' => $now->copy()->endOfMonth()->toDateString(),
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/overview")
        ->assertOk()
        ->assertJsonPath('data.income.actual', '500000.00')
        ->assertJsonPath('data.expense.budgeted', '1000000.00')
        ->assertJsonPath('data.expense.actual', '800000.00')
        ->assertJsonPath('data.expense.overspend', '0.00')
        ->assertJsonPath('data.safe_money', '-500000.00')
        ->assertJsonPath('data.liabilities.balance', '0.00');
});

test('overview computes liability balance', function () {
    $liabilityAccount = Account::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'liability', 'currency' => 'IDR']);

    $now = Carbon::now();
    $journal = Journal::factory()->create([
        'tenant_id' => $this->tenant->id,
        'transaction_date' => $now->toDateString(),
        'status' => 'posted',
    ]);

    JournalLine::factory()->create([
        'journal_id' => $journal->id,
        'account_id' => $liabilityAccount->id,
        'credit' => '3000000.00',
        'debit' => '500000.00',
    ]);

    $this->getJson("/api/v1/{$this->tenant->slug}/overview")
        ->assertOk()
        ->assertJsonPath('data.liabilities.balance', '2500000.00');
});
