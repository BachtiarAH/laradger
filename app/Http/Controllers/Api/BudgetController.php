<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Models\JournalLine;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BudgetController extends Controller
{
    public function index(string $tenant, Request $request): AnonymousResourceCollection
    {
        $query = Budget::query()
            ->with(['accounts', 'tags']);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        if ($request->filled('starts_at')) {
            $query->whereDate('starts_at', '>=', $request->date('starts_at'));
        }

        if ($request->filled('ends_at')) {
            $query->whereDate('ends_at', '<=', $request->date('ends_at'));
        }

        if ($request->filled('tag_id')) {
            $query->whereHas('tags', fn ($tags) => $tags->whereKey($request->input('tag_id')));
        }

        if ($request->filled('account_id')) {
            $query->whereHas('accounts', fn ($accounts) => $accounts->whereKey($request->input('account_id')));
        }

        if ($request->filled('period')) {
            $period = $request->string('period')->toString();
            $now = Carbon::now();

            [$start, $end] = match ($period) {
                'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
                'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
                'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
                default => [null, null],
            };

            if ($start && $end) {
                $query->whereDate('starts_at', '<=', $end->toDateString())
                    ->whereDate('ends_at', '>=', $start->toDateString());
            }
        }

        if ($request->filled('period_type')) {
            $query->where('period_type', $request->string('period_type'));
        }

        if ($request->filled('is_recurring')) {
            $query->where('is_recurring', $request->boolean('is_recurring'));
        }

        if ($request->filled('budget_type')) {
            $query->where('budget_type', $request->string('budget_type')->toString());
        }

        $totalAmount = (clone $query)->sum('amount');
        $incomeBudgeted = (clone $query)->where('budget_type', 'income')->sum('amount');
        $expenseBudgeted = (clone $query)->where('budget_type', 'expense')->sum('amount');

        // Determine actual date range from same filters (period / starts_at / ends_at)
        $actualStart = null;
        $actualEnd = null;

        if ($request->filled('period')) {
            $period = $request->string('period')->toString();
            $now = Carbon::now();
            [$pStart, $pEnd] = match ($period) {
                'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
                'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
                'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
                default => [null, null],
            };
            if ($pStart && $pEnd) {
                $actualStart = $pStart->toDateString();
                $actualEnd = $pEnd->toDateString();
            }
        } elseif ($request->filled('starts_at') || $request->filled('ends_at')) {
            $actualStart = $request->input('starts_at');
            $actualEnd = $request->input('ends_at');
        }

        $incomeActual = 0;
        $expenseActual = 0;

        $filterBudgetType = $request->filled('budget_type') ? $request->string('budget_type')->toString() : null;
        $shouldComputeIncome = $filterBudgetType === null || $filterBudgetType === 'income';
        $shouldComputeExpense = $filterBudgetType === null || $filterBudgetType === 'expense';

        if ($shouldComputeIncome || $shouldComputeExpense) {
            $baseActualQuery = JournalLine::query()
                ->whereHas('journal', function ($q) use ($actualStart, $actualEnd, $request) {
                    $q->whereIn('status', ['posted', 'archived']);
                    if ($actualStart) {
                        $q->whereDate('transaction_date', '>=', $actualStart);
                    }
                    if ($actualEnd) {
                        $q->whereDate('transaction_date', '<=', $actualEnd);
                    }
                    if ($request->filled('tag_id')) {
                        $q->whereHas('tags', fn ($qq) => $qq->whereKey($request->input('tag_id')));
                    }
                });

            if ($request->filled('account_id')) {
                $baseActualQuery->where('account_id', $request->input('account_id'));
            }

            if ($shouldComputeIncome) {
                $incomeActual = (clone $baseActualQuery)
                    ->whereHas('account', fn ($q) => $q->where('type', 'income'))
                    ->sum('credit');
            }

            if ($shouldComputeExpense) {
                $expenseActual = (clone $baseActualQuery)
                    ->whereHas('account', fn ($q) => $q->where('type', 'expense'))
                    ->sum('debit');
            }
        }

        $unbudgetedIncome = (float) $incomeActual - (float) $incomeBudgeted;
        $remainingExpense = (float) $expenseBudgeted - (float) $expenseActual;
        $netBudgeted = (float) $incomeBudgeted - (float) $expenseBudgeted;

        $paginator = $query->latest('starts_at')->paginate();

        return BudgetResource::collection($paginator)->additional([
            'meta' => [
                'total_amount' => number_format((float) $totalAmount, 2, '.', ''),
                'summary' => [
                    'income_budgeted' => number_format((float) $incomeBudgeted, 2, '.', ''),
                    'expense_budgeted' => number_format((float) $expenseBudgeted, 2, '.', ''),
                    'total_budgeted' => number_format((float) $totalAmount, 2, '.', ''),
                    'income_actual' => number_format((float) $incomeActual, 2, '.', ''),
                    'expense_actual' => number_format((float) $expenseActual, 2, '.', ''),
                    'unbudgeted_income' => number_format($unbudgetedIncome, 2, '.', ''),
                    'remaining_expense' => number_format($remainingExpense, 2, '.', ''),
                    'net_budgeted' => number_format($netBudgeted, 2, '.', ''),
                ],
            ],
        ]);
    }

    public function store(string $tenant, StoreBudgetRequest $request): JsonResponse
    {
        $data = $request->validated();
        $accountIds = $data['account_ids'] ?? [];
        $tagIds = $data['tag_ids'] ?? [];
        unset($data['account_ids'], $data['tag_ids']);

        $data = $this->resolveBudgetDates($data);

        $budget = DB::transaction(function () use ($request, $data, $accountIds, $tagIds) {
            $budget = Budget::create([
                ...$data,
                'user_id' => $request->user()->id,
            ]);
            $budget->accounts()->sync($accountIds);
            $budget->tags()->sync($tagIds);

            return $budget;
        });

        return (new BudgetResource($budget->load(['accounts', 'tags'])))->response()->setStatusCode(201);
    }

    public function show(string $tenant, Request $request, Budget $budget): BudgetResource
    {
        $this->ensureOwner($request, $budget);

        return new BudgetResource($budget->load(['accounts', 'tags']));
    }

    public function update(string $tenant, UpdateBudgetRequest $request, Budget $budget): BudgetResource
    {
        $this->ensureOwner($request, $budget);

        $data = $request->validated();
        $accountIds = array_key_exists('account_ids', $data) ? $data['account_ids'] : null;
        $tagIds = array_key_exists('tag_ids', $data) ? $data['tag_ids'] : null;
        unset($data['account_ids'], $data['tag_ids']);

        $data = $this->resolveBudgetDates($data, $budget);

        DB::transaction(function () use ($budget, $data, $accountIds, $tagIds) {
            $budget->update($data);

            if ($accountIds !== null) {
                $budget->accounts()->sync($accountIds);
            }

            if ($tagIds !== null) {
                $budget->tags()->sync($tagIds);
            }
        });

        return new BudgetResource($budget->fresh()->load(['accounts', 'tags']));
    }

    public function destroy(string $tenant, Request $request, Budget $budget): JsonResponse
    {
        $this->ensureOwner($request, $budget);
        $budget->delete();

        return response()->json(null, 204);
    }

    private function ensureOwner(Request $request, Budget $budget): void
    {
        abort_unless($request->user()->belongsToTenant($budget->tenant_id), 404);
    }

    /**
     * Resolve starts_at/ends_at from budget_month when period_type is monthly.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveBudgetDates(array $data, ?Budget $existing = null): array
    {
        $periodType = $data['period_type'] ?? $existing?->period_type ?? 'custom';
        $budgetMonth = $data['budget_month'] ?? null;
        unset($data['budget_month']);

        if ($periodType === 'monthly' && $budgetMonth) {
            $carbon = Carbon::createFromFormat('Y-m', $budgetMonth);
            if ($carbon) {
                $data['starts_at'] = $carbon->copy()->startOfMonth()->toDateString();
                $data['ends_at'] = $carbon->copy()->endOfMonth()->toDateString();
            }
        }

        // If monthly without explicit month but also without dates, default to current month
        if ($periodType === 'monthly' && empty($data['starts_at']) && empty($data['ends_at'])) {
            if ($existing) {
                $data['starts_at'] ??= $existing->starts_at?->toDateString();
                $data['ends_at'] ??= $existing->ends_at?->toDateString();
            }

            if (empty($data['starts_at']) && empty($data['budget_month'])) {
                $now = Carbon::now();
                $data['starts_at'] ??= $now->copy()->startOfMonth()->toDateString();
                $data['ends_at'] ??= $now->copy()->endOfMonth()->toDateString();
            }
        }

        // Normalize defaults
        $data['period_type'] = $periodType;
        $data['is_recurring'] = $data['is_recurring'] ?? $existing?->is_recurring ?? false;
        $data['budget_type'] = $data['budget_type'] ?? $existing?->budget_type ?? 'expense';

        return $data;
    }
}
