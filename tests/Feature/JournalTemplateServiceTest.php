<?php

use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\Tenant;
use App\Services\JournalTemplateService;
use App\Tenancy\TenantContext;
use Database\Seeders\JournalTemplateSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->service = app(JournalTemplateService::class);
    $this->tenant = Tenant::factory()->create();
    TenantContext::set($this->tenant);
});

afterEach(function () {
    TenantContext::forget();
});

function templateAccount(Tenant $tenant, string $name, string $code, string $type): Account
{
    return Account::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'code' => $code,
        'type' => $type,
        'status' => 'active',
    ]);
}

/**
 * The seeded templates resolve their accounts by code. A ledger that does not
 * use those codes used to get templates with no lines at all, silently: the line
 * table rendered empty and the template looked ready to use.
 */
test('the seeder skips a template whose account codes are not in the ledger', function () {
    // This ledger has accounts, but none with the codes the seeder looks for.
    templateAccount($this->tenant, 'Kas', '1-1-1-1', 'asset');
    templateAccount($this->tenant, 'Beban', '5-1-1', 'expense');

    $this->seed(JournalTemplateSeeder::class);

    expect(JournalTemplate::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())
        ->toBe(0);
});

test('the seeder creates lines when the account codes do exist', function () {
    templateAccount($this->tenant, 'Jago', 'JAGO', 'asset');
    templateAccount($this->tenant, 'Gaji', 'GAJI', 'income');
    templateAccount($this->tenant, 'GoPay', 'GOPAY', 'asset');
    templateAccount($this->tenant, 'Utilitas', 'UTIL', 'expense');
    templateAccount($this->tenant, 'BRI', 'BRI', 'asset');

    $this->seed(JournalTemplateSeeder::class);

    $templates = JournalTemplate::withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)
        ->get();

    expect($templates)->toHaveCount(4);

    foreach ($templates as $template) {
        expect($template->lines()->count())
            ->toBe(2, "\"{$template->name}\" was seeded without its lines");
    }
});

/**
 * A line-less template would otherwise book a description and nothing else. The
 * ledger would look fine until a report tried to total it.
 */
test('generating from a template with no lines is refused', function () {
    $template = JournalTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_active' => true,
    ]);

    expect($template->lines()->count())->toBe(0);

    expect(fn () => $this->service->generate($template))
        ->toThrow(ValidationException::class);

    expect(Journal::withoutGlobalScopes()->count())->toBe(0);
});

test('the scheduled run skips a line-less template and carries on', function () {
    $broken = JournalTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_active' => true,
        'next_run_at' => now()->subDay(),
    ]);

    $asset = templateAccount($this->tenant, 'Kas', '1-1-1-1', 'asset');
    $expense = templateAccount($this->tenant, 'Beban', '5-1-1', 'expense');

    $working = JournalTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_active' => true,
        'next_run_at' => now()->subDay(),
    ]);
    $working->lines()->create([
        'line_number' => 1, 'account_id' => $expense->id, 'debit' => 1000, 'credit' => 0,
    ]);
    $working->lines()->create([
        'line_number' => 2, 'account_id' => $asset->id, 'debit' => 0, 'credit' => 1000,
    ]);

    $created = $this->service->processDue();

    // The broken one produced nothing, the healthy one still ran.
    expect($created)->toHaveCount(1)
        ->and(Journal::withoutGlobalScopes()->count())->toBe(1)
        ->and(Journal::withoutGlobalScopes()->first()->description)
        ->toStartWith($working->name);

    // A skipped template keeps its schedule, so it is not silently consumed.
    expect($broken->refresh()->next_run_at->toDateString())
        ->toBe(now()->subDay()->toDateString());
});
