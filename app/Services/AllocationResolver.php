<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Allocation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AllocationResolver
{
    /**
     * Resolve a template allocation. A complete active auto match takes
     * precedence; the template allocation is only the fallback.
     *
     * @param  array<int, array<string, mixed>|string>  $lines
     */
    public function resolveForTemplate(?string $explicitAllocationId, array $lines): ?string
    {
        $autoAllocationId = $this->resolveAutoAllocation($lines);

        return $autoAllocationId ?? $this->validateExplicitAllocation($explicitAllocationId);
    }

    /**
     * Resolve a journal or quick-transaction allocation. An explicitly chosen
     * allocation wins; otherwise a complete active auto match is used.
     *
     * @param  array<int, array<string, mixed>|string>  $lines
     */
    public function resolveForJournal(?string $explicitAllocationId, array $lines): ?string
    {
        if (filled($explicitAllocationId)) {
            return $this->validateExplicitAllocation($explicitAllocationId);
        }

        return $this->resolveAutoAllocation($lines);
    }

    /**
     * @param  array<int, array<string, mixed>|string>  $lines
     */
    private function resolveAutoAllocation(array $lines): ?string
    {
        $expenseAccountIds = $this->expenseAccountIds($lines);

        if ($expenseAccountIds->isEmpty()) {
            return null;
        }

        $allocations = Allocation::active()
            ->whereHas('expenseAccounts', fn ($query) => $query->whereIn('accounts.id', $expenseAccountIds))
            ->with('expenseAccounts')
            ->get();
        $matchedAllocationIds = collect();

        foreach ($expenseAccountIds as $accountId) {
            $matches = $allocations->filter(
                fn (Allocation $allocation) => $allocation->expenseAccounts->contains('id', $accountId),
            );

            if ($matches->count() > 1) {
                throw ValidationException::withMessages([
                    'allocation_id' => 'Multiple active allocations match an expense account. Fix the allocation mapping before generating.',
                ]);
            }

            if ($matches->isNotEmpty()) {
                $matchedAllocationIds->push($matches->first()->id);
            }
        }

        $matchedAllocationIds = $matchedAllocationIds->unique()->values();

        if ($matchedAllocationIds->count() > 1) {
            throw ValidationException::withMessages([
                'allocation_id' => 'The expense accounts match multiple active allocations. Fix the allocation mapping before generating.',
            ]);
        }

        if ($matchedAllocationIds->isEmpty()) {
            return null;
        }

        $allocationId = (string) $matchedAllocationIds->first();
        $allocation = $allocations->firstWhere('id', $allocationId);
        $allExpenseAccountsCovered = $allocation !== null
            && $expenseAccountIds->every(
                fn (string $accountId) => $allocation->expenseAccounts->contains('id', $accountId),
            );

        if (! $allExpenseAccountsCovered) {
            throw ValidationException::withMessages([
                'allocation_id' => 'All expense accounts must be mapped to the same active allocation before generating.',
            ]);
        }

        return $allocationId;
    }

    /**
     * @param  array<int, array<string, mixed>|string>  $lines
     * @return Collection<int, string>
     */
    private function expenseAccountIds(array $lines): Collection
    {
        $accountIds = collect($lines)
            ->map(fn (array|string|null $line) => is_array($line) ? ($line['account_id'] ?? null) : $line)
            ->filter(fn (mixed $accountId) => is_string($accountId) && $accountId !== '')
            ->unique()
            ->values();

        return Account::query()
            ->where('type', 'expense')
            ->whereIn('id', $accountIds)
            ->pluck('id');
    }

    private function validateExplicitAllocation(?string $allocationId): ?string
    {
        if (! filled($allocationId)) {
            return null;
        }

        if (! Allocation::query()->whereKey($allocationId)->exists()) {
            throw ValidationException::withMessages([
                'allocation_id' => 'The selected allocation is no longer available.',
            ]);
        }

        return $allocationId;
    }
}
