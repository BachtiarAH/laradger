<?php

use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\Tag;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = createTenantForUser($this->user);
    Sanctum::actingAs($this->user);
});

function makeTemplateLines(Account $account): array
{
    return [
        ['account_id' => $account->id, 'debit' => 1000.00, 'credit' => 0, 'description' => 'Dr'],
        ['account_id' => $account->id, 'debit' => 0, 'credit' => 1000.00, 'description' => 'Cr'],
    ];
}

test('journal templates can be listed', function () {
    JournalTemplate::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);
    JournalTemplate::factory()->create();

    $this->getJson("/api/v1/{$this->tenant->slug}/journal-templates")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('journal templates are isolated between tenants', function () {
    $template = JournalTemplate::factory()->create(['tenant_id' => $this->tenant->id]);
    $other = JournalTemplate::factory()->create();

    $this->getJson("/api/v1/{$this->tenant->slug}/journal-templates/{$template->id}")->assertOk();
    $this->getJson("/api/v1/{$this->tenant->slug}/journal-templates/{$other->id}")->assertNotFound();
});

test('a journal template can be created with lines and tags', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $tag = Tag::factory()->create(['tenant_id' => $this->tenant->id]);

    $response = $this->postJson("/api/v1/{$this->tenant->slug}/journal-templates", [
        'name' => 'Monthly rent',
        'description' => 'Office rent payment',
        'period_type' => 'monthly',
        'day_of_month' => 1,
        'lines' => makeTemplateLines($account),
        'tags' => [$tag->id],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Monthly rent')
        ->assertJsonPath('data.period_type', 'monthly')
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonCount(1, 'data.tags');
});

test('an unbalanced journal template cannot be created', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->postJson("/api/v1/{$this->tenant->slug}/journal-templates", [
        'name' => 'Bad template',
        'period_type' => 'daily',
        'lines' => [
            ['account_id' => $account->id, 'debit' => 1000.00],
            ['account_id' => $account->id, 'credit' => 500.00],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);

    expect(JournalTemplate::query()->where('name', 'Bad template')->exists())->toBeFalse();
});

test('a journal template can be updated', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $template = JournalTemplate::factory()->create(['tenant_id' => $this->tenant->id, 'period_type' => 'daily']);

    $this->putJson("/api/v1/{$this->tenant->slug}/journal-templates/{$template->id}", [
        'name' => 'Updated name',
        'period_type' => 'weekly',
        'day_of_week' => 2,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Updated name')
        ->assertJsonPath('data.period_type', 'weekly');
});

test('a journal template can be deleted', function () {
    $template = JournalTemplate::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->deleteJson("/api/v1/{$this->tenant->slug}/journal-templates/{$template->id}")->assertNoContent();

    expect(JournalTemplate::query()->find($template->id))->toBeNull();
});

test('generating from a template creates a draft journal with default amounts', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $tag = Tag::factory()->create(['tenant_id' => $this->tenant->id]);
    $template = JournalTemplate::factory()
        ->monthly(1)
        ->create(['tenant_id' => $this->tenant->id, 'name' => 'Monthly rent', 'description' => 'Rent']);
    $template->lines()->createMany(makeTemplateLines($account));
    $template->tags()->attach($tag->id);

    $response = $this->postJson("/api/v1/{$this->tenant->slug}/journal-templates/{$template->id}/generate", [
        'transaction_date' => '2026-09-05',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.source', 'system')
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonCount(1, 'data.tags')
        ->assertJsonPath('data.description', 'Monthly rent — Rent');

    expect(Journal::query()->where('id', $response->json('data.id'))->count())->toBe(1);
    expect(JournalTemplate::query()->find($template->id)->last_run_at)->not->toBeNull();
});

test('generating from a template allows per-line amount overrides', function () {
    $accountA = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $accountB = Account::factory()->create(['tenant_id' => $this->tenant->id]);
    $template = JournalTemplate::factory()->daily()->create(['tenant_id' => $this->tenant->id]);
    $template->lines()->createMany([
        ['account_id' => $accountA->id, 'debit' => 100.00, 'credit' => 0, 'description' => 'Dr'],
        ['account_id' => $accountA->id, 'debit' => 0, 'credit' => 100.00, 'description' => 'Cr'],
    ]);

    $response = $this->postJson("/api/v1/{$this->tenant->slug}/journal-templates/{$template->id}/generate", [
        'lines' => [
            ['account_id' => $accountB->id, 'debit' => 500.00, 'credit' => 0],
            ['account_id' => $accountB->id, 'debit' => 0, 'credit' => 500.00],
        ],
    ]);

    $response->assertCreated();
    $data = $response->json('data.lines');
    expect($data[0]['account_id'])->toBe($accountB->id);
    expect((float) $data[1]['credit'])->toBe(500.00);
});

test('the scheduler process command generates journals for due templates only', function () {
    $account = Account::factory()->create(['tenant_id' => $this->tenant->id]);

    $due = JournalTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_active' => true,
        'next_run_at' => now()->subDay(),
    ]);
    $due->lines()->createMany(makeTemplateLines($account));

    $notDue = JournalTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_active' => true,
        'next_run_at' => now()->addWeek(),
    ]);

    $inactive = JournalTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_active' => false,
        'next_run_at' => now()->subDay(),
    ]);

    $this->artisan('journal-templates:process')->assertExitCode(0);

    expect(Journal::query()->where('description', 'like', "{$due->name}%")->count())->toBe(1);
    expect(Journal::query()->where('description', 'like', "{$notDue->name}%")->count())->toBe(0);
    expect(Journal::query()->where('description', 'like', "{$inactive->name}%")->count())->toBe(0);

    $due->refresh();
    expect($due->last_run_at)->not->toBeNull();
    expect($due->next_run_at->isFuture())->toBeTrue();
});
