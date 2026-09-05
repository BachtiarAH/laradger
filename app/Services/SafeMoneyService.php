<?php

namespace App\Services;

use App\Models\Allocation;
use App\Models\Goal;
use App\Models\JournalLine;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Killer feature: "Dari semua uangku, berapa yang bebas dipakai?"
 *
 * Formula (Issue #22):
 *   Safe-to-Spend = Available Assets - Outstanding Allocations - Applicable Goal Commitments
 */
class SafeMoneyService
{
    /**
     * @return array{
     *     eligible_assets: float,
     *     allocated: float,
     *     goal_commitments: float,
     *     pending_goal_contributions: float,
     *     accumulated_goal_savings: float,
     *     other_obligations: float,
     *     safe: float,
     *     is_over_allocated: bool
     * }
     */
    public function calculate(): array
    {
        $eligibleAssets = $this->eligibleAssets();
        $allocated = $this->activeAllocated();
        $goalData = $this->goalCommitments();
        $goalCommitments = $goalData['total'];
        $otherObligations = 0.0;

        $safe = $eligibleAssets - $allocated - $goalCommitments - $otherObligations;

        return [
            'eligible_assets' => $eligibleAssets,
            'allocated' => $allocated,
            'goal_commitments' => $goalCommitments,
            'pending_goal_contributions' => $goalData['pending_contributions'],
            'accumulated_goal_savings' => $goalData['accumulated_savings'],
            'other_obligations' => $otherObligations,
            'safe' => $safe,
            'is_over_allocated' => ($allocated + $goalCommitments) > $eligibleAssets + 0.0001,
        ];
    }

    public function eligibleAssets(): float
    {
        return (float) JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('type', 'asset')->where('is_header', false)->where('status', 'active'))
            ->whereHas('journal', fn ($q) => $q->whereIn('status', ['posted', 'archived'])->whereDoesntHave('reversals'))
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as total')
            ->value('total');
    }

    /**
     * Active allocations commitments.
     * Computes the remaining unspent portion across active allocations,
     * or the reserved pivot amounts if explicit account reservations exist.
     */
    public function activeAllocated(): float
    {
        $pivotQuery = DB::table('account_allocations')
            ->join('allocations', 'allocations.id', '=', 'account_allocations.allocation_id')
            ->join('accounts', 'accounts.id', '=', 'account_allocations.account_id')
            ->where('allocations.status', 'active')
            ->where('accounts.type', 'asset');

        if (TenantContext::hasTenant()) {
            $pivotQuery->where('accounts.tenant_id', TenantContext::id());
        }

        $pivotTotal = (float) $pivotQuery->sum('account_allocations.amount');

        // Sum remaining amounts for account-agnostic allocations (active, no pivot rows)
        $allocationsWithoutPivot = Allocation::active()
            ->whereDoesntHave('accounts')
            ->get();

        $agnosticRemaining = (float) $allocationsWithoutPivot->sum(fn (Allocation $a) => $a->remainingAmount());

        return $pivotTotal + $agnosticRemaining;
    }

    /**
     * @return array{total: float, pending_contributions: float, accumulated_savings: float}
     */
    public function goalCommitments(): array
    {
        $goals = Goal::active()->get();

        $pendingContributions = (float) $goals->sum(fn (Goal $g) => $g->pendingContributionThisPeriod());
        $accumulatedSavings = (float) $goals->sum(fn (Goal $g) => $g->accumulatedAmount());

        return [
            'total' => $pendingContributions + $accumulatedSavings,
            'pending_contributions' => $pendingContributions,
            'accumulated_savings' => $accumulatedSavings,
        ];
    }
}
