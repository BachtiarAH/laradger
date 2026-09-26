<?php

namespace App\Services\Ai\Tools\Tools;

use App\Http\Controllers\Api\JournalController;
use App\Http\Requests\StoreJournalRequest;
use App\Services\Ai\Tools\AbstractAiTool;
use App\Services\Ai\Tools\AiToolKind;
use App\Services\Ai\Tools\Support\FormRequestInvoker;
use App\Tenancy\TenantContext;

class JournalCreateTool extends AbstractAiTool
{
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
                            'account_id' => self::uuidSchema('Account id from accounts_search. Must be an active leaf account.'),
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
                    'items' => self::uuidSchema('Existing tag id.'),
                    'description' => 'Optional tag ids to attach.',
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
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $payload = $this->payload($arguments);

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
