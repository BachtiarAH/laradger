<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    /**
     * @param  array<string, mixed>  $data  validated data
     */
    public function create(array $data): Journal
    {
        return DB::transaction(function () use ($data) {
            $type = $data['type'];
            $amount = (float) $data['amount'];
            $description = $data['description'];
            $date = $data['transaction_date'] ?? now()->toDateString();
            $reference = $data['reference'] ?? null;
            $tags = $data['tags'] ?? [];

            [$lines, $sourceDescription] = match ($type) {
                'expense' => $this->expenseLines($data, $amount),
                'income' => $this->incomeLines($data, $amount),
                'transfer' => $this->transferLines($data, $amount),
                'debt_payment' => $this->debtPaymentLines($data, $amount),
                default => throw ValidationException::withMessages(['type' => 'Unknown transaction type.']),
            };

            $journal = Journal::create([
                'transaction_date' => $date,
                'description' => $description ?: $sourceDescription,
                'reference' => $reference,
                'status' => $data['status'] ?? 'draft',
                'source' => 'manual',
                'allocation_id' => $data['allocation_id'] ?? null,
                'goal_id' => $data['goal_id'] ?? null,
            ]);

            foreach ($lines as $index => $line) {
                $journal->lines()->create($line + ['line_number' => $index + 1]);
            }

            if ($tags !== []) {
                $journal->tags()->sync($tags);
            }

            return $journal;
        });
    }

    /**
     * @return array{0: array<int, array<string,mixed>>, 1: string}
     */
    private function expenseLines(array $data, float $amount): array
    {
        $expense = $this->accountOrFail($data['expense_account_id'], 'expense');
        $this->assertLeaf($expense);
        $method = $data['payment_method'] ?? 'cash';

        if ($method === 'credit') {
            $liability = $this->accountOrFail($data['liability_account_id'], 'liability');
            $this->assertLeaf($liability);

            return [
                [
                    ['account_id' => $expense->id, 'debit' => $amount, 'credit' => 0, 'description' => $data['description']],
                    ['account_id' => $liability->id, 'debit' => 0, 'credit' => $amount, 'description' => $data['description']],
                ],
                "Expense {$amount} {$expense->name} on credit → {$liability->name}",
            ];
        }

        $asset = $this->accountOrFail($data['asset_account_id'], 'asset');
        $this->assertLeaf($asset);

        return [
            [
                ['account_id' => $expense->id, 'debit' => $amount, 'credit' => 0, 'description' => $data['description']],
                ['account_id' => $asset->id, 'debit' => 0, 'credit' => $amount, 'description' => $data['description']],
            ],
            "Expense {$amount} from {$asset->name}",
        ];
    }

    /**
     * @return array{0: array<int, array<string,mixed>>, 1: string}
     */
    private function incomeLines(array $data, float $amount): array
    {
        $asset = $this->accountOrFail($data['asset_account_id'], 'asset');
        $income = $this->accountOrFail($data['income_account_id'], 'income');

        $this->assertLeaf($asset);
        $this->assertLeaf($income);

        return [
            [
                ['account_id' => $asset->id, 'debit' => $amount, 'credit' => 0, 'description' => $data['description']],
                ['account_id' => $income->id, 'debit' => 0, 'credit' => $amount, 'description' => $data['description']],
            ],
            "Income {$amount} to {$asset->name}",
        ];
    }

    /**
     * @return array{0: array<int, array<string,mixed>>, 1: string}
     */
    private function transferLines(array $data, float $amount): array
    {
        $from = $this->accountOrFail($data['from_account_id'], 'asset');
        $to = $this->accountOrFail($data['to_account_id'], 'asset');

        $this->assertLeaf($from);
        $this->assertLeaf($to);

        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_account_id' => 'Transfer destination must be different from source.']);
        }

        $viaIds = $data['via_account_ids'] ?? [];

        if ($viaIds === []) {
            return [
                [
                    ['account_id' => $to->id, 'debit' => $amount, 'credit' => 0, 'description' => $data['description']],
                    ['account_id' => $from->id, 'debit' => 0, 'credit' => $amount, 'description' => $data['description']],
                ],
                "Transfer {$amount} {$from->name} → {$to->name}",
            ];
        }

        $accounts = Account::withoutGlobalScopes()->whereIn('id', $viaIds)->get()->keyBy('id');

        foreach ($viaIds as $viaId) {
            $acc = $accounts->get($viaId);
            if (! $acc) {
                throw ValidationException::withMessages(['via_account_ids' => 'One via account not found.']);
            }
            if ($acc->type !== 'asset') {
                throw ValidationException::withMessages(['via_account_ids' => 'Via accounts must be asset type.']);
            }
            $this->assertLeaf($acc);
            if ($viaId === $from->id || $viaId === $to->id) {
                throw ValidationException::withMessages(['via_account_ids' => 'Via account must be different from source/destination.']);
            }
        }

        // Advanced transfer: single journal that records the hop chain in description
        // while ledger impact is only Cr from / Dr to (via accounts are transit metadata).
        // Alternative multi-leg design (Cr from/Dr via + Cr via/Dr to) would double-count
        // the amount; we keep the simple 2-line journal to preserve correct balances.
        // Via chain is preserved in description for auditability.
        $viaNames = $accounts->pluck('name')->all();
        $viaSuffix = $viaNames ? ' via '.implode(' → ', $viaNames) : '';

        return [
            [
                ['account_id' => $to->id, 'debit' => $amount, 'credit' => 0, 'description' => $data['description'].$viaSuffix],
                ['account_id' => $from->id, 'debit' => 0, 'credit' => $amount, 'description' => $data['description'].$viaSuffix],
            ],
            "Transfer {$amount} {$from->name} → {$to->name}{$viaSuffix}",
        ];
    }

    /**
     * @return array{0: array<int, array<string,mixed>>, 1: string}
     */
    private function debtPaymentLines(array $data, float $amount): array
    {
        $asset = $this->accountOrFail($data['asset_account_id'], 'asset');
        $liability = $this->accountOrFail($data['liability_account_id'], 'liability');

        $this->assertLeaf($asset);
        $this->assertLeaf($liability);

        return [
            [
                ['account_id' => $liability->id, 'debit' => $amount, 'credit' => 0, 'description' => $data['description']],
                ['account_id' => $asset->id, 'debit' => 0, 'credit' => $amount, 'description' => $data['description']],
            ],
            "Debt payment {$amount} {$asset->name} → {$liability->name}",
        ];
    }

    private function accountOrFail(string $id, string $expectedType): Account
    {
        $account = Account::withoutGlobalScopes()->whereKey($id)->first();

        if (! $account) {
            throw ValidationException::withMessages(['account_id' => 'Account not found.']);
        }

        if ($account->type !== $expectedType) {
            throw ValidationException::withMessages(['account_id' => "Account {$account->name} must be type {$expectedType}."]);
        }

        return $account;
    }

    private function assertLeaf(Account $account): void
    {
        if ((bool) $account->is_header) {
            throw ValidationException::withMessages(['account_id' => 'Akun "'.$account->name.'" adalah akun induk — tidak bisa dipakai transaksi.']);
        }
    }
}
