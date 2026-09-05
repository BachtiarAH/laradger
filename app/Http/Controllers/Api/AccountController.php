<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Http\Resources\JournalLineResource;
use App\Models\Account;
use App\Models\JournalLine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class AccountController extends Controller
{
    public function index(string $tenant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Account::class);

        $query = Account::with('parent')
            ->withCount('children')
            ->withSum('journalLines as total_debit', 'debit')
            ->withSum('journalLines as total_credit', 'credit')
            ->when(request('type'), fn ($query) => $query->where('type', request('type')))
            ->when(request('currency'), fn ($query) => $query->where('currency', request('currency')))
            ->when(request('status'), fn ($query) => $query->where('status', request('status')))
            ->when(request('search'), fn ($query) => $query->where(function ($q): void {
                $search = '%'.request('search').'%';
                $q->where('name', 'like', $search)->orWhere('code', 'like', $search);
            }));

        // Get all accounts first to build hierarchical structure
        $allAccounts = $query->orderBy('code')->get();

        // Build hierarchical structure with depth
        $accounts = $this->buildHierarchicalAccounts($allAccounts);

        // Manually paginate the hierarchical result
        $perPage = (int) (request('per_page', 15));
        $currentPage = (int) (request('page', 1));
        $total = $accounts->count();
        $slicedAccounts = $accounts->slice(($currentPage - 1) * $perPage, $perPage);

        $paginator = new LengthAwarePaginator(
            $slicedAccounts,
            $total,
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return AccountResource::collection($paginator);
    }

    /**
     * Build hierarchical account structure with depth information.
     */
    private function buildHierarchicalAccounts(Collection $accounts): Collection
    {
        // Create a map for quick lookup
        $accountMap = $accounts->keyBy('id');

        // Calculate depth for each account
        $accounts->transform(function ($account) use ($accountMap) {
            $depth = 0;
            $current = $account;
            while ($current->parent_id && isset($accountMap[$current->parent_id])) {
                $depth++;
                $current = $accountMap[$current->parent_id];
            }
            $account->setAttribute('depth', $depth);

            return $account;
        });

        // Sort by depth first, then by code within each depth level
        return $accounts->sortBy(fn ($account) => [$account->depth, $account->code])->values();
    }

    public function store(string $tenant, StoreAccountRequest $request): JsonResponse
    {
        $this->authorize('create', Account::class);

        $account = retry(
            times: 3,
            callback: fn () => DB::transaction(fn () => Account::create($request->validated())),
            sleepMilliseconds: 50,
            when: fn (Throwable $e) => $e instanceof UniqueConstraintViolationException,
        );
        $account->setAttribute('depth', 0);

        return (new AccountResource($account->load('parent')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $tenant, Account $account): AccountResource
    {
        $this->authorize('view', $account);

        $account->setAttribute('depth', 0);

        return new AccountResource($account->load(['parent', 'children']));
    }

    public function update(string $tenant, UpdateAccountRequest $request, Account $account): AccountResource
    {
        $this->authorize('update', $account);

        $account->update($request->validated());
        $account->setAttribute('depth', 0);

        return new AccountResource($account->fresh('parent'));
    }

    public function destroy(string $tenant, Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        // Accounts are archived (soft-deleted), never physically removed, so
        // journal lines, budgets, allocations, templates, and children can keep
        // referencing the row without foreign-key failures.
        $account->delete();

        return response()->json(null, 204);
    }

    public function journalLines(string $tenant, Account $account): AnonymousResourceCollection
    {
        $this->authorize('view', $account);

        $lines = JournalLine::with(['account', 'journal'])
            ->where('account_id', $account->id)
            ->when(request('status'), fn ($q) => $q->whereHas('journal', fn ($jq) => $jq->where('status', request('status'))))
            ->when(request('from'), fn ($q) => $q->whereHas('journal', fn ($jq) => $jq->whereDate('transaction_date', '>=', request('from'))))
            ->when(request('to'), fn ($q) => $q->whereHas('journal', fn ($jq) => $jq->whereDate('transaction_date', '<=', request('to'))))
            ->when(request('search'), function ($q): void {
                $search = '%'.request('search').'%';
                $q->where(function ($qq) use ($search): void {
                    $qq->where('description', 'like', $search)
                        ->orWhereHas('journal', fn ($jq) => $jq->where('reference', 'like', $search)->orWhere('description', 'like', $search));
                });
            })
            ->latest('created_at')
            ->paginate((int) (request('per_page', 15)));

        return JournalLineResource::collection($lines);
    }

    public function allocations(string $tenant, Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $reservations = $account->allocations()->orderBy('name')->get();

        $totalAllocated = (float) $reservations->sum(fn ($allocation) => (float) $allocation->pivot->amount);
        $balance = $account->postedNetBalance();
        $available = max(0.0, $balance);

        return response()->json([
            'data' => [
                'account_id' => $account->id,
                'currency' => $account->currency,
                'balance' => number_format($balance, 2, '.', ''),
                'available' => number_format($available, 2, '.', ''),
                'total_allocated' => number_format($totalAllocated, 2, '.', ''),
                'unallocated' => number_format($available - $totalAllocated, 2, '.', ''),
                'over_allocated' => $totalAllocated > $available + 0.0001,
                'items' => $reservations->map(fn ($allocation) => [
                    'allocation_id' => $allocation->id,
                    'name' => $allocation->name,
                    'amount' => number_format((float) $allocation->pivot->amount, 2, '.', ''),
                ])->values()->all(),
            ],
        ]);
    }

    public function analytics(string $tenant, Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $baseQuery = JournalLine::where('account_id', $account->id);

        $totals = (clone $baseQuery)
            ->selectRaw('COALESCE(SUM(debit),0) as total_debit, COALESCE(SUM(credit),0) as total_credit, COUNT(*) as lines_count')
            ->first();

        $journalsCount = (clone $baseQuery)->distinct('journal_id')->count('journal_id');

        $byStatus = DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $account->id)
            ->groupBy('journals.status')
            ->selectRaw('journals.status, COALESCE(SUM(journal_lines.debit),0) as debit, COALESCE(SUM(journal_lines.credit),0) as credit, COUNT(*) as count')
            ->get()
            ->keyBy('status');

        // Monthly breakdown computed in PHP for DB agnostic behavior
        $allForMonthly = JournalLine::with('journal')
            ->where('account_id', $account->id)
            ->get();

        $monthly = $allForMonthly
            ->groupBy(function (JournalLine $jl) {
                $date = $jl->journal?->transaction_date ?? $jl->created_at;

                return Carbon::parse($date)->format('Y-m');
            })
            ->map(function ($group, string $month) {
                $debit = $group->sum(fn (JournalLine $jl) => (float) $jl->debit);
                $credit = $group->sum(fn (JournalLine $jl) => (float) $jl->credit);

                return [
                    'month' => $month,
                    'debit' => number_format($debit, 2, '.', ''),
                    'credit' => number_format($credit, 2, '.', ''),
                    'count' => $group->count(),
                ];
            })
            ->sortKeysDesc()
            ->take(6)
            ->values();

        $recentLines = JournalLine::with(['journal'])
            ->where('account_id', $account->id)
            ->latest('created_at')
            ->limit(5)
            ->get();

        $totalDebit = (float) ($totals->total_debit ?? 0);
        $totalCredit = (float) ($totals->total_credit ?? 0);

        // Normal balance: debit for asset/expense, credit for liability/equity/income
        $isDebitNormal = in_array($account->type, ['asset', 'expense'], true);
        $net = $isDebitNormal ? $totalDebit - $totalCredit : $totalCredit - $totalDebit;

        return response()->json([
            'data' => [
                'account_id' => $account->id,
                'account_type' => $account->type,
                'totals' => [
                    'debit' => number_format($totalDebit, 2, '.', ''),
                    'credit' => number_format($totalCredit, 2, '.', ''),
                    'net' => number_format($net, 2, '.', ''),
                    'balance' => number_format(abs($net), 2, '.', ''),
                    'balance_side' => $net >= 0 ? ($isDebitNormal ? 'debit' : 'credit') : ($isDebitNormal ? 'credit' : 'debit'),
                ],
                'counts' => [
                    'lines' => (int) ($totals->lines_count ?? 0),
                    'journals' => (int) $journalsCount,
                ],
                'by_status' => $byStatus,
                'monthly' => $monthly,
                'recent' => JournalLineResource::collection($recentLines),
            ],
        ]);
    }
}
