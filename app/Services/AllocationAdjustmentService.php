<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies allocation adjustments (allocate/release) on an account for an
 * allocation. Allocations never touch the ledger; each change is recorded in
 * the audit log. Callers are expected to wrap usage in a database transaction.
 */
class AllocationAdjustmentService
{
    /**
     * Reserve part of an account's available balance for an allocation.
     */
    public function allocate(Allocation $allocation, Account $account, float $amount): void
    {
        $account = Account::query()
            ->whereKey($account->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($account->type !== 'asset') {
            throw ValidationException::withMessages([
                'account_id' => 'Allocations can only be placed on asset accounts where money actually exists.',
            ]);
        }

        $pivot = DB::table('account_allocations')
            ->where('allocation_id', $allocation->id)
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->first();

        $currentTotal = (float) DB::table('account_allocations')
            ->where('account_id', $account->id)
            ->sum('amount');

        $available = max(0.0, $account->postedNetBalance());

        if ($currentTotal + $amount > $available + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => "Allocating this amount would exceed the account's available balance of ".number_format($available, 2, '.', '').'.',
            ]);
        }

        if ($pivot) {
            DB::table('account_allocations')
                ->where('allocation_id', $allocation->id)
                ->where('account_id', $account->id)
                ->increment('amount', $amount);
        } else {
            DB::table('account_allocations')->insert([
                'allocation_id' => $allocation->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->audit($allocation, 'allocation.allocated', [
            'account_id' => $account->id,
            'amount' => number_format($amount, 2, '.', ''),
            'account_total' => number_format($currentTotal + $amount, 2, '.', ''),
        ]);
    }

    /**
     * Release part of an allocation's reservation on an account. The row is
     * removed when the reservation reaches zero.
     */
    public function release(Allocation $allocation, Account $account, float $amount): void
    {
        $account = Account::query()
            ->whereKey($account->id)
            ->lockForUpdate()
            ->firstOrFail();

        $pivot = DB::table('account_allocations')
            ->where('allocation_id', $allocation->id)
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->first();

        if (! $pivot) {
            throw ValidationException::withMessages([
                'amount' => 'There is nothing allocated on this account for this allocation.',
            ]);
        }

        $current = (float) $pivot->amount;

        if ($amount > $current + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => 'Cannot release more than the '.number_format($current, 2, '.', '').' currently allocated on this account.',
            ]);
        }

        if ($amount >= $current - 0.0001) {
            DB::table('account_allocations')
                ->where('allocation_id', $allocation->id)
                ->where('account_id', $account->id)
                ->delete();
        } else {
            DB::table('account_allocations')
                ->where('allocation_id', $allocation->id)
                ->where('account_id', $account->id)
                ->decrement('amount', $amount);
        }

        $this->audit($allocation, 'allocation.released', [
            'account_id' => $account->id,
            'amount' => number_format($amount, 2, '.', ''),
            'account_total' => number_format(max(0.0, $current - $amount), 2, '.', ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(Allocation $allocation, string $action, array $after): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'before' => null,
            'after' => $after,
            'reason' => $action,
        ]);
    }
}
