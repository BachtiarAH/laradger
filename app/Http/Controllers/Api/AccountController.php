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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccountController extends Controller
{
    public function index(string $tenant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Account::class);

        $accounts = Account::with('parent')
            ->withSum('journalLines as total_debit', 'debit')
            ->withSum('journalLines as total_credit', 'credit')
            ->when(request('type'), fn ($query) => $query->where('type', request('type')))
            ->when(request('currency'), fn ($query) => $query->where('currency', request('currency')))
            ->when(request('status'), fn ($query) => $query->where('status', request('status')))
            ->when(request('search'), fn ($query) => $query->where(function ($q): void {
                $search = '%'.request('search').'%';
                $q->where('name', 'like', $search)->orWhere('code', 'like', $search);
            }))
            ->orderBy('code')
            ->paginate();

        return AccountResource::collection($accounts);
    }

    public function store(string $tenant, StoreAccountRequest $request): JsonResponse
    {
        $this->authorize('create', Account::class);

        $account = Account::create($request->validated());

        return (new AccountResource($account->load('parent')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $tenant, Account $account): AccountResource
    {
        $this->authorize('view', $account);

        return new AccountResource($account->load(['parent', 'children']));
    }

    public function update(string $tenant, UpdateAccountRequest $request, Account $account): AccountResource
    {
        $this->authorize('update', $account);

        $account->update($request->validated());

        return new AccountResource($account->fresh('parent'));
    }

    public function destroy(string $tenant, Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        if ($account->journalLines()->exists()) {
            throw new ConflictHttpException('The account cannot be deleted because it has journal lines.');
        }

        if ($account->allocations()->exists()) {
            throw new ConflictHttpException('The account cannot be deleted because it has allocations. Release them first.');
        }

        if ($account->budgets()->exists()) {
            throw new ConflictHttpException('The account cannot be deleted because it is linked to budgets.');
        }

        if ($account->children()->exists()) {
            throw new ConflictHttpException('The account cannot be deleted because it has child accounts.');
        }

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
