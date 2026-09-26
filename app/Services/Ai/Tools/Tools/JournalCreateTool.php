<?php

namespace App\Services\Ai\Tools\Tools;

use App\Http\Controllers\Api\JournalController;
use App\Http\Requests\StoreJournalRequest;
use App\Models\Account;
use App\Models\Tag;
use App\Services\Ai\Tools\AbstractAiTool;
use App\Services\Ai\Tools\AiToolKind;
use App\Services\Ai\Tools\Support\FormRequestInvoker;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class JournalCreateTool extends AbstractAiTool
{
    /**
     * Marks a reference to something this same turn is proposing.
     */
    private const PENDING_PREFIX = 'pending:';

    public function __construct(
        private readonly FormRequestInvoker $requests,
    ) {}

    public function name(): string
    {
        return 'journal_create';
    }

    public function description(): string
    {
        return 'Propose a new journal entry from a plain-language transaction. Use this for anything that '
            .'moves money: expenses, income, transfers, accruals. Every line needs an account_id from '
            .'accounts_search, exactly one of debit or credit, and total debits must equal total credits. '
            .'This only creates a draft for the user to review; nothing is posted until they approve it.';
    }

    public function kind(): AiToolKind
    {
        return AiToolKind::Write;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'transaction_date' => [
                    'type' => 'string',
                    'description' => 'Date the transaction happened, YYYY-MM-DD. Use today when unknown.',
                ],
                'description' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'description' => 'Short memo describing the entry.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'posted'],
                    'description' => 'Defaults to draft. Only post when the user explicitly asks to post it.',
                ],
                'lines' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'description' => 'The debit and credit lines. Must balance exactly.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'account_id' => self::uuidSchema(
                                'Account id from accounts_search. Must be an active leaf account. '
                                .'For an account you are proposing with account_create in this same '
                                .'reply, pass "pending:<account name>" instead — the user approves the '
                                .'account draft first, then this journal.'
                            ),
                            'debit' => self::moneySchema('Debit amount as a decimal string, or null.'),
                            'credit' => self::moneySchema('Credit amount as a decimal string, or null.'),
                            'description' => ['type' => 'string', 'maxLength' => 255],
                        ],
                        'required' => ['account_id'],
                        'additionalProperties' => false,
                    ],
                ],
                'tag_ids' => [
                    'type' => 'array',
                    'items' => self::uuidSchema('Existing tag id, taken from the tag list in your instructions.'),
                    'description' => 'Optional ids of tags that already exist.',
                ],
                'pending_tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'maxLength' => 255],
                    'description' => 'Names of tags you are proposing with tag_create in this same reply. '
                        .'They are attached once that tag exists, so the user approves the tag first. '
                        .'Use this instead of tag_ids for a tag that does not exist yet.',
                ],
            ],
            'required' => ['transaction_date', 'description', 'lines'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function title(array $arguments): string
    {
        $lines = $this->arrayArgument($arguments, 'lines');
        $total = 0.0;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $total += (float) (($line['debit'] ?? null) ?: 0);
        }

        $date = $this->stringArgument($arguments, 'transaction_date');
        $memo = $this->stringArgument($arguments, 'description');

        return sprintf(
            'Create journal %s — %s (%s)',
            $date !== '' ? $date : 'undated',
            $memo !== '' ? $memo : 'no description',
            number_format($total, 2, '.', ''),
        );
    }

    /**
     * Normalize model output into the exact shape the real request expects.
     *
     * Kept separate from `execute()` so the stored draft payload — and therefore
     * what the user reviews — is already in its final form.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function payload(array $arguments): array
    {
        $lines = [];

        foreach ($this->arrayArgument($arguments, 'lines') as $line) {
            if (! is_array($line)) {
                continue;
            }

            $debit = $this->nullableAmount($line['debit'] ?? null);
            $credit = $this->nullableAmount($line['credit'] ?? null);

            $lines[] = array_filter([
                'account_id' => $this->stringArgument($line, 'account_id'),
                'debit' => $debit,
                'credit' => $credit,
                'description' => $this->stringArgument($line, 'description') ?: null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            'transaction_date' => $this->stringArgument($arguments, 'transaction_date'),
            'description' => $this->stringArgument($arguments, 'description'),
            'reference' => null,
            'status' => $this->stringArgument($arguments, 'status', 'draft') ?: 'draft',
            'source' => 'manual',
            'allocation_id' => null,
            'goal_id' => null,
            'lines' => $lines,
            'tags' => array_values(array_filter(array_map(
                fn (mixed $tag): string => is_scalar($tag) ? (string) $tag : '',
                $this->arrayArgument($arguments, 'tag_ids'),
            ))),
            'pending_tags' => $this->pendingTagNames($arguments),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, string>
     */
    private function pendingTagNames(array $arguments): array
    {
        $names = [];

        foreach ($this->arrayArgument($arguments, 'pending_tags') as $name) {
            if (! is_scalar($name)) {
                continue;
            }

            $name = trim((string) $name);

            if ($name !== '') {
                $names[$name] = $name;
            }
        }

        return array_values($names);
    }

    /**
     * A tag the assistant proposed is still a draft, so it has no id the journal
     * could reference. Resolving by name at approval time is what lets the two
     * be proposed together: the user approves the tag, then the journal, and the
     * journal attaches it. One draft never executes another, so the ordering is
     * a thing the user does, not a thing that happens behind their back.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $payload = $this->payload($arguments);

        foreach ($payload['lines'] as $index => $line) {
            $reference = (string) ($line['account_id'] ?? '');

            if (str_starts_with($reference, self::PENDING_PREFIX)) {
                $payload['lines'][$index]['account_id'] = $this->resolvePendingAccount($reference);
            }
        }

        if ($payload['pending_tags'] !== []) {
            $payload['tags'] = array_values(array_unique([
                ...$payload['tags'],
                ...$this->resolvePendingTags($payload['pending_tags']),
            ]));

            unset($payload['pending_tags']);
        }

        $request = $this->requests->make(
            StoreJournalRequest::class,
            $payload,
            [],
            ['REQUEST_URI' => '/journals'],
        );

        $response = app(JournalController::class)->store((string) TenantContext::id(), $request);

        return (array) ($response->getData(true)['data'] ?? []);
    }

    /**
     * A proposed account is a draft, not an account, so there is no id to
     * reference yet. Resolving the name at approval time is what lets the
     * assistant record a transaction and the account it needs in one reply,
     * which is the only way a ledger with a new category is usable at all.
     */
    private function resolvePendingAccount(string $reference): string
    {
        $name = trim(substr($reference, strlen(self::PENDING_PREFIX)));

        $account = Account::query()->where('name', $name)->where('is_header', false)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'lines' => "The account \"{$name}\" is still waiting to be approved. "
                    .'Approve that account first, then approve this journal.',
            ]);
        }

        return (string) $account->getKey();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function resolvePendingTags(array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $tag = Tag::query()->where('name', $name)->first();

            if ($tag === null) {
                // A raw "tags.0 does not exist" tells the user nothing. Name
                // the tag and what to do about it.
                throw ValidationException::withMessages([
                    'tags' => "The tag \"{$name}\" is still waiting to be approved. "
                        .'Approve that tag first, then approve this journal.',
                ]);
            }

            $ids[] = $tag->getKey();
        }

        return $ids;
    }

    /**
     * A blank amount must stay null rather than become "0", because a line with
     * both sides null is rejected and a zero debit is not the same intent.
     */
    private function nullableAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? number_format((float) $value, 2, '.', '') : null;
    }
}
