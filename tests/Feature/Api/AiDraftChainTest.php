<?php

use App\Models\Account;
use App\Models\AiActionDraft;
use App\Models\AiConversation;
use App\Models\Journal;
use App\Models\Tag;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    [$this->tenant, $this->base] = assistantTenant($this->user);

    $this->cash = Account::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Cash',
        'type' => 'asset',
        'code' => 'AS-0001',
        'status' => 'active',
    ]);
    $this->coffee = Account::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Coffee',
        'type' => 'expense',
        'code' => 'EX-0001',
        'status' => 'active',
    ]);
});

/**
 * The bug: approving a journal before its tag failed with "approve that tag
 * first", and the failure was terminal — the draft could not be edited, could not
 * be retried, and had to be thrown away and asked for again. Ordering was the
 * user's job with no way to see it and no way to recover from getting it wrong.
 */
describe('a draft that depends on another', function () {
    test('the journal records the tag draft as a prerequisite, not just a name in its payload', function () {
        fakeToolCallingProvider([[
            'id' => 'call_1',
            'name' => 'tag_create',
            'arguments' => ['name' => 'Groceries', 'type' => 'vendor'],
        ], [
            'id' => 'call_2',
            'name' => 'journal_create',
            'arguments' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Groceries',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '25.00'],
                    ['account_id' => $this->cash->id, 'credit' => '25.00'],
                ],
                'pending_tags' => ['Groceries'],
            ],
        ]], 'Prepared.');

        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'bought groceries',
        ])->assertOk();

        reenter($this->tenant);

        $journal = AiActionDraft::withoutGlobalScopes()->where('tool', 'journal_create')->firstOrFail();
        $tag = AiActionDraft::withoutGlobalScopes()->where('tool', 'tag_create')->firstOrFail();

        // The pairing is a real row, which is what makes it orderable and visible.
        expect($journal->dependencies()->pluck('id')->all())->toBe([$tag->id]);

        // And it is reported to the client, so the card can say so before the click.
        $listed = collect($this->getJson("{$this->base}/ai/drafts")->assertOk()->json('data'))
            ->keyBy('id');

        expect($listed[$journal->id]['depends_on'])->toHaveCount(1)
            ->and($listed[$journal->id]['depends_on'][0]['title'])->toBe($tag->title)
            // The tag itself depends on nothing, so its card must not claim it does.
            ->and($listed[$tag->id]['depends_on'])->toBe([]);
    });

    test('the dependency is recorded even when the journal is proposed first', function () {
        // Order within the turn is the model's choice, and it is the case a
        // per-draft implementation would miss: at the moment the journal draft is
        // created the tag draft does not exist yet.
        fakeToolCallingProvider([[
            'id' => 'call_1',
            'name' => 'journal_create',
            'arguments' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Groceries',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '25.00'],
                    ['account_id' => $this->cash->id, 'credit' => '25.00'],
                ],
                'pending_tags' => ['Groceries'],
            ],
        ], [
            'id' => 'call_2',
            'name' => 'tag_create',
            'arguments' => ['name' => 'Groceries', 'type' => 'vendor'],
        ]], 'Prepared.');

        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'bought groceries',
        ])->assertOk();

        reenter($this->tenant);

        $journal = AiActionDraft::withoutGlobalScopes()->where('tool', 'journal_create')->firstOrFail();
        expect($journal->dependencies)->toHaveCount(1);
    });

    test('a tag that already exists is not a dependency', function () {
        Tag::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Groceries',
            'type' => 'vendor',
        ]);

        fakeToolCallingProvider([[
            'id' => 'call_1',
            'name' => 'tag_create',
            'arguments' => ['name' => 'Groceries', 'type' => 'vendor'],
        ], [
            'id' => 'call_2',
            'name' => 'journal_create',
            'arguments' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Groceries',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '25.00'],
                    ['account_id' => $this->cash->id, 'credit' => '25.00'],
                ],
                'pending_tags' => ['Groceries'],
            ],
        ]], 'Prepared.');

        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $this->postJson("{$this->base}/ai/conversations/{$conversation->id}/messages", [
            'message' => 'bought groceries',
        ])->assertOk();

        reenter($this->tenant);

        $journal = AiActionDraft::withoutGlobalScopes()->where('tool', 'journal_create')->firstOrFail();

        // The reference resolves on its own, so demanding a draft be approved for
        // something already in the ledger would be a pointless extra step.
        expect($journal->dependencies)->toHaveCount(0);
    });

    test('one click on the journal creates the tag first, and both are settled', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        [$journal, $tag] = pendingTagAndJournal($conversation, $this->user, $this->coffee->id, $this->cash->id);

        $this->postJson("{$this->base}/ai/drafts/{$journal->id}/execute")->assertOk();

        reenter($this->tenant);

        expect($tag->refresh()->status)->toBe(AiActionDraft::STATUS_EXECUTED)
            ->and($journal->refresh()->status)->toBe(AiActionDraft::STATUS_EXECUTED)
            // The tag really exists, and the journal really points at it.
            ->and(Tag::withoutGlobalScopes()->where('name', 'Groceries')->exists())->toBeTrue();

        $posted = Journal::withoutGlobalScopes()->firstOrFail();
        expect($posted->tags()->withoutGlobalScopes()->pluck('tags.name')->all())
            ->toContain('Groceries');
    });

    test('the chain is all or nothing: a journal that fails validation creates no tag', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $tag = pendingTag($conversation, $this->user);
        $journal = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Unbalanced',
            'status' => AiActionDraft::STATUS_PENDING,
            // Deliberately does not balance, so the journal step fails.
            'payload' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Broken',
                'status' => 'draft',
                'source' => 'manual',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '25.00'],
                    ['account_id' => $this->cash->id, 'credit' => '5.00'],
                ],
                'tags' => [],
                'pending_tags' => ['Groceries'],
            ],
        ]);
        $journal->dependencies()->attach($tag->id);

        $this->postJson("{$this->base}/ai/drafts/{$journal->id}/execute")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines']);

        reenter($this->tenant);

        // The tag step ran first and was rolled back with the journal, so a retry
        // re-runs the whole chain rather than tripping over a half-applied one.
        expect(Tag::withoutGlobalScopes()->where('name', 'Groceries')->exists())->toBeFalse()
            ->and($tag->refresh()->status)->toBe(AiActionDraft::STATUS_PENDING)
            ->and($journal->refresh()->status)->toBe(AiActionDraft::STATUS_FAILED);
    });

    test('a failed draft is editable and retryable, which is what used to be impossible', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        $draft = $conversation->drafts()->create([
            'user_id' => $this->user->id,
            'tool' => 'journal_create',
            'kind' => 'write',
            'title' => 'Unbalanced',
            'status' => AiActionDraft::STATUS_PENDING,
            'payload' => [
                'transaction_date' => '2026-08-17',
                'description' => 'Broken',
                'status' => 'draft',
                'source' => 'manual',
                'lines' => [
                    ['account_id' => $this->coffee->id, 'debit' => '25.00'],
                    ['account_id' => $this->cash->id, 'credit' => '5.00'],
                ],
                'tags' => [],
            ],
        ]);

        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")->assertStatus(422);
        expect($draft->refresh()->status)->toBe(AiActionDraft::STATUS_FAILED);

        // The point of the whole change: correct it and run it again, rather than
        // throwing it away and asking the assistant to produce it a second time.
        $fixed = [
            'transaction_date' => '2026-08-17',
            'description' => 'Corrected',
            'status' => 'draft',
            'source' => 'manual',
            'lines' => [
                ['account_id' => $this->coffee->id, 'debit' => '25.00'],
                ['account_id' => $this->cash->id, 'credit' => '25.00'],
            ],
            'tags' => [],
        ];

        $this->patchJson("{$this->base}/ai/drafts/{$draft->id}", ['payload' => $fixed])->assertOk();
        $this->postJson("{$this->base}/ai/drafts/{$draft->id}/execute")->assertOk();

        expect(Journal::withoutGlobalScopes()->firstOrFail()->description)->toBe('Corrected');
    });

    test('an already applied draft still cannot be run twice', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        [$journal, $tag] = pendingTagAndJournal($conversation, $this->user, $this->coffee->id, $this->cash->id);

        $this->postJson("{$this->base}/ai/drafts/{$journal->id}/execute")->assertOk();

        reenter($this->tenant);
        $this->postJson("{$this->base}/ai/drafts/{$journal->id}/execute")->assertStatus(409);

        // The anti-replay guard is the one thing chaining must not soften: a second
        // run would post the money twice and create a second tag.
        expect(Journal::withoutGlobalScopes()->count())->toBe(1)
            ->and(Tag::withoutGlobalScopes()->where('name', 'Groceries')->count())->toBe(1);
    });

    test('a discarded prerequisite blocks the chain and says how to get out', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        [$journal, $tag] = pendingTagAndJournal($conversation, $this->user, $this->coffee->id, $this->cash->id);

        $this->postJson("{$this->base}/ai/drafts/{$tag->id}/reject")->assertOk();

        $this->postJson("{$this->base}/ai/drafts/{$journal->id}/execute")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This needs "'.$tag->title.'", which you discarded. '
                .'Edit this draft to take the reference out, then approve it.');

        reenter($this->tenant);

        // Nothing ran: refusing is correct, and quietly dropping the reference
        // would mean approving something other than what was reviewed.
        expect(Journal::withoutGlobalScopes()->count())->toBe(0)
            ->and($journal->refresh()->status)->toBe(AiActionDraft::STATUS_PENDING);
    });

    test('a prerequisite the user already approved by hand is not run again', function () {
        $conversation = AiConversation::factory()->forUser($this->user, $this->tenant)->create();

        [$journal, $tag] = pendingTagAndJournal($conversation, $this->user, $this->coffee->id, $this->cash->id);

        // The other flow: approve the tag on its own first, then the journal.
        $this->postJson("{$this->base}/ai/drafts/{$tag->id}/execute")->assertOk();
        $this->postJson("{$this->base}/ai/drafts/{$journal->id}/execute")->assertOk();

        reenter($this->tenant);

        expect($journal->refresh()->status)->toBe(AiActionDraft::STATUS_EXECUTED)
            ->and(Tag::withoutGlobalScopes()->where('name', 'Groceries')->count())->toBe(1);
    });
});

/**
 * A pending tag draft, as the assistant would propose it.
 *
 * A plain function cannot reach the test case, so what it needs is passed in.
 */
function pendingTag(AiConversation $conversation, User $user): AiActionDraft
{
    return $conversation->drafts()->create([
        'user_id' => $user->getKey(),
        'tool' => 'tag_create',
        'kind' => 'write',
        'title' => 'Create vendor tag "Groceries"',
        'status' => AiActionDraft::STATUS_PENDING,
        'payload' => ['name' => 'Groceries', 'type' => 'vendor'],
    ]);
}

/**
 * The pair the assistant produces in one reply: a journal waiting on a tag.
 *
 * @return array{0: AiActionDraft, 1: AiActionDraft}
 */
function pendingTagAndJournal(
    AiConversation $conversation,
    User $user,
    string $debitAccount,
    string $creditAccount,
): array {
    $tag = pendingTag($conversation, $user);

    $journal = $conversation->drafts()->create([
        'user_id' => $user->getKey(),
        'tool' => 'journal_create',
        'kind' => 'write',
        'title' => 'Create journal — Groceries',
        'status' => AiActionDraft::STATUS_PENDING,
        'payload' => [
            'transaction_date' => '2026-08-17',
            'description' => 'Groceries',
            'status' => 'draft',
            'source' => 'manual',
            'lines' => [
                ['account_id' => $debitAccount, 'debit' => '25.00'],
                ['account_id' => $creditAccount, 'credit' => '25.00'],
            ],
            'tags' => [],
            'pending_tags' => ['Groceries'],
        ],
    ]);

    $journal->dependencies()->attach($tag->id);

    return [$journal, $tag];
}
