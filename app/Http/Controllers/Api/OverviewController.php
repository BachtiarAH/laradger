<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\JournalLine;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function index(string $tenant, Request $request): JsonResponse
    {
        $period = $request->filled('period') ? $request->string('period')->toString() : 'this_month';
        $now = Carbon::now();

        [$start, $end] = match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $incomeBudgeted = (float) Budget::query()
            ->where('budget_type', 'income')
            ->whereDate('starts_at', '<=', $endDate)
            ->whereDate('ends_at', '>=', $startDate)
            ->sum('amount');

        $expenseBudgeted = (float) Budget::query()
            ->where('budget_type', 'expense')
            ->whereDate('starts_at', '<=', $endDate)
            ->whereDate('ends_at', '>=', $startDate)
            ->sum('amount');

        $baseActualQuery = JournalLine::query()
            ->whereHas('journal', function ($q) use ($startDate, $endDate) {
                $q->whereIn('status', ['posted', 'archived'])
                    ->whereDate('transaction_date', '>=', $startDate)
                    ->whereDate('transaction_date', '<=', $endDate);
            });

        $incomeActual = (float) (clone $baseActualQuery)
            ->whereHas('account', fn ($q) => $q->where('type', 'income'))
            ->sum('credit');

        $expenseActual = (float) (clone $baseActualQuery)
            ->whereHas('account', fn ($q) => $q->where('type', 'expense'))
            ->sum('debit');

        $unbudgetedIncome = $incomeActual - $incomeBudgeted;
        $remainingExpense = $expenseBudgeted - $expenseActual;
        $overspend = max(0.0, $expenseActual - $expenseBudgeted);
        $netBudgeted = $incomeBudgeted - $expenseBudgeted;
        $safeMoney = $incomeActual - ($expenseBudgeted + $overspend);

        $postedOnly = fn ($q) => $q->whereIn('status', ['posted', 'archived']);

        $assetBalance = (float) JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('type', 'asset'))
            ->whereHas('journal', $postedOnly)
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as total')
            ->value('total');

        $liabilityBalance = (float) JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('type', 'liability'))
            ->whereHas('journal', $postedOnly)
            ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as total')
            ->value('total');

        $netWorth = $assetBalance - $liabilityBalance;

        // Month-end wealth series (posted + archived only) for the last 6 months.
        $wealthHistory = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->startOfMonth()->subMonths($i);
            $asOf = $month->copy()->endOfMonth()->toDateString();

            $asset = (float) JournalLine::query()
                ->whereHas('account', fn ($q) => $q->where('type', 'asset'))
                ->whereHas('journal', fn ($q) => $q->whereIn('status', ['posted', 'archived'])->whereDate('transaction_date', '<=', $asOf))
                ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as total')
                ->value('total');

            $liability = (float) JournalLine::query()
                ->whereHas('account', fn ($q) => $q->where('type', 'liability'))
                ->whereHas('journal', fn ($q) => $q->whereIn('status', ['posted', 'archived'])->whereDate('transaction_date', '<=', $asOf))
                ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as total')
                ->value('total');

            $wealthHistory[] = [
                'month' => $month->format('Y-m'),
                'assets' => number_format($asset, 2, '.', ''),
                'liabilities' => number_format($liability, 2, '.', ''),
                'net_worth' => number_format($asset - $liability, 2, '.', ''),
            ];
        }

        return response()->json([
            'data' => [
                'period' => $period,
                'date_range' => [
                    'from' => $startDate,
                    'to' => $endDate,
                ],
                'income' => [
                    'actual' => number_format($incomeActual, 2, '.', ''),
                    'budgeted' => number_format($incomeBudgeted, 2, '.', ''),
                ],
                'expense' => [
                    'actual' => number_format($expenseActual, 2, '.', ''),
                    'budgeted' => number_format($expenseBudgeted, 2, '.', ''),
                    'remaining' => number_format($remainingExpense, 2, '.', ''),
                    'overspend' => number_format($overspend, 2, '.', ''),
                ],
                'unbudgeted_income' => number_format($unbudgetedIncome, 2, '.', ''),
                'net_budgeted' => number_format($netBudgeted, 2, '.', ''),
                'safe_money' => number_format($safeMoney, 2, '.', ''),
                'assets' => [
                    'balance' => number_format($assetBalance, 2, '.', ''),
                ],
                'liabilities' => [
                    'balance' => number_format($liabilityBalance, 2, '.', ''),
                ],
                'net_worth' => number_format($netWorth, 2, '.', ''),
                'wealth_history' => $wealthHistory,
            ],
        ]);
    }
}
