<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JournalLineResource;
use App\Models\JournalLine;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpenseController extends Controller
{
    /**
     * Sortable columns, mapped to their SQL expression.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'transaction_date' => 'journals.transaction_date',
        'debit' => 'journal_lines.debit',
        'account' => 'accounts.name',
    ];

    /**
     * Paginated expense lines — the debits booked on `type = expense` accounts.
     *
     * Only `posted` and `archived` journals are included by default so the list
     * always agrees with the dashboard expense KPI. `from` / `to` filter on the
     * journal's transaction date, matching the `/{tenant}/journals` filters.
     */
    public function index(string $tenant, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JournalLine::class);

        $from = $this->resolveDate($request, 'from');
        $to = $this->resolveDate($request, 'to');
        $statuses = $request->filled('status')
            ? [$request->string('status')->toString()]
            : ['posted', 'archived'];

        $query = JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('type', 'expense'))
            ->whereHas('journal', function ($q) use ($statuses, $from, $to): void {
                $q->whereIn('status', $statuses)
                    ->when($from, fn ($jq) => $jq->whereDate('transaction_date', '>=', $from))
                    ->when($to, fn ($jq) => $jq->whereDate('transaction_date', '<=', $to));
            })
            ->when($request->filled('account_id'), fn ($q) => $q->where('journal_lines.account_id', $request->input('account_id')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('journal_lines.description', 'like', $search)
                        ->orWhereHas('journal', fn ($jq) => $jq->where('reference', 'like', $search)->orWhere('description', 'like', $search));
                });
            });

        // Totals are computed over the same filters as the list, before pagination.
        $totals = (clone $query)
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as total_debit, COUNT(*) as lines_count')
            ->first();

        $sortBy = is_string($request->input('sort_by')) ? $request->input('sort_by') : 'transaction_date';
        $sortDirection = $request->input('sort_direction') === 'asc' ? 'asc' : 'desc';

        $lines = (clone $query)
            ->select('journal_lines.*')
            ->leftJoin('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereNull('journals.deleted_at')
            ->with(['account', 'journal'])
            ->orderBy(self::SORT_COLUMNS[$sortBy] ?? self::SORT_COLUMNS['transaction_date'], $sortDirection)
            ->orderBy('journal_lines.id', $sortDirection)
            ->paginate((int) $request->integer('per_page', 10));

        return JournalLineResource::collection($lines)->additional([
            'meta' => [
                'totals' => [
                    'expense_total' => number_format((float) ($totals->total_debit ?? 0), 2, '.', ''),
                    'lines_count' => (int) ($totals->lines_count ?? 0),
                ],
                'date_range' => [
                    'from' => $from,
                    'to' => $to,
                ],
            ],
        ]);
    }

    /**
     * Parse an optional `YYYY-MM-DD` filter, ignoring unparsable input.
     */
    private function resolveDate(Request $request, string $key): ?string
    {
        if (! $request->filled($key)) {
            return null;
        }

        try {
            return Carbon::parse($request->input($key))->toDateString();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
