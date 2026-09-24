<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJournalRequest;
use App\Http\Requests\UpdateJournalPlanningRequest;
use App\Http\Requests\UpdateJournalRequest;
use App\Http\Resources\JournalResource;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\Journal;
use App\Services\Ai\Contracts\AiCallRecorder;
use App\Services\AllocationAdjustmentService;
use App\Services\AllocationResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class JournalController extends Controller
{
    public function __construct(
        private readonly AiCallRecorder $aiCallRecorder,
        private readonly AllocationAdjustmentService $allocationAdjustments,
        private readonly AllocationResolver $allocationResolver,
    ) {}

    public function index(string $tenant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Journal::class);

        $sortColumns = [
            'reference' => 'reference',
            'description' => 'description',
            'transaction_date' => 'transaction_date',
            'status' => 'status',
            'source' => 'source',
            'total_debit' => 'lines_sum_debit',
            'lines_count' => 'lines_count',
        ];
        $sortBy = is_string(request('sort_by')) ? request('sort_by') : 'transaction_date';
        $sortColumn = $sortColumns[$sortBy] ?? 'transaction_date';
        $sortDirection = request('sort_direction', 'desc') === 'asc' ? 'asc' : 'desc';

        $journals = Journal::withCount('lines')
            ->withSum('lines', 'debit')
            ->withSum('lines', 'credit')
            ->with(['tags', 'allocation', 'goal'])
            ->when(request('status'), fn ($query) => $query->where('status', request('status')))
            ->when(request('source'), fn ($query) => $query->where('source', request('source')))
            ->when(request('allocation_id'), function ($query, $allocationId) {
                $allocation = Allocation::find($allocationId);
                $accountIds = $allocation ? $allocation->expenseAccounts()->pluck('accounts.id')->all() : [];

                $query->where(function ($q) use ($allocationId, $accountIds) {
                    $q->where('allocation_id', $allocationId);
                    if ($accountIds !== []) {
                        $q->orWhere(function ($auto) use ($accountIds) {
                            $auto->whereNull('allocation_id')
                                ->whereHas('lines', fn ($l) => $l->whereIn('account_id', $accountIds));
                        });
                    }
                });
            })
            ->when(request('goal_id'), fn ($query) => $query->where('goal_id', request('goal_id')))
            ->when(request('from'), fn ($query) => $query->whereDate('transaction_date', '>=', request('from')))
            ->when(request('to'), fn ($query) => $query->whereDate('transaction_date', '<=', request('to')))
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('id', $sortDirection)
            ->paginate();

        return JournalResource::collection($journals);
    }

    public function store(string $tenant, StoreJournalRequest $request): JsonResponse
    {
        $this->authorize('create', Journal::class);

        $data = $request->safe()->except(['lines', 'tags', 'ai_record_id']);
        $lines = $request->validated('lines', []);
        $data['allocation_id'] = $this->allocationResolver->resolveForJournal(
            $data['allocation_id'] ?? null,
            $lines,
        );

        $journal = retry(
            times: 3,
            callback: fn () => DB::transaction(function () use ($data, $lines, $request) {
                $journal = Journal::create($data);

                foreach ($lines as $index => $line) {
                    $journal->lines()->create($line + ['line_number' => $index + 1]);
                }

                $journal->tags()->sync($request->validated('tags', []));

                $this->applyAllocationAdjustments($journal, $request->validated('allocation_adjustments', []));

                if ($recordId = $request->validated('ai_record_id')) {
                    $this->aiCallRecorder->confirm($recordId, $journal);
                }

                return $journal;
            }),
            sleepMilliseconds: 50,
            when: fn (Throwable $e) => $e instanceof UniqueConstraintViolationException,
        );

        return (new JournalResource($journal->load('lines.account', 'tags', 'allocation', 'goal')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Allocation changes only make sense on posted journals: drafts are not
     * real money yet. Everything runs inside the caller's transaction so a
     * failing adjustment rolls the whole journal back.
     *
     * @param  array<int, array{action: string, allocation_id: string, account_id: string, amount: string|float}>  $adjustments
     */
    private function applyAllocationAdjustments(Journal $journal, array $adjustments): void
    {
        if ($adjustments === []) {
            return;
        }

        if ($journal->status !== 'posted') {
            throw ValidationException::withMessages([
                'status' => 'Allocation adjustments can only be applied to a posted journal.',
            ]);
        }

        foreach ($adjustments as $index => $adjustment) {
            try {
                $account = Account::query()->whereKey($adjustment['account_id'])->firstOrFail();
                $allocation = Allocation::query()->whereKey($adjustment['allocation_id'])->firstOrFail();

                $amount = (float) $adjustment['amount'];

                if ($adjustment['action'] === 'allocate') {
                    $this->allocationAdjustments->allocate($allocation, $account, $amount);
                } else {
                    $this->allocationAdjustments->release($allocation, $account, $amount);
                }
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

                throw ValidationException::withMessages([
                    "allocation_adjustments.{$index}" => $message,
                ]);
            }
        }
    }

    public function show(string $tenant, Journal $journal): JournalResource
    {
        $this->authorize('view', $journal);

        return new JournalResource($journal->load('lines.account', 'tags', 'allocation', 'goal'));
    }

    public function update(string $tenant, UpdateJournalRequest $request, Journal $journal): JournalResource
    {
        $this->authorize('update', $journal);

        DB::transaction(function () use ($request, $journal) {
            // Handle lines/tags while journal is still draft so model-level
            // immutability guards (JournalLine::deleting) do not block the
            // draft → posted transition that replaces lines atomically.
            if ($request->has('lines')) {
                $journal->lines()->get()->each->delete();
                foreach ($request->validated('lines', []) as $index => $line) {
                    $journal->lines()->create($line + ['line_number' => $index + 1]);
                }
            }

            if ($request->has('tags')) {
                $journal->tags()->sync($request->validated('tags', []));
            }

            $journal->update($request->safe()->except(['lines', 'tags']));
        });

        return new JournalResource($journal->fresh('lines.account', 'tags', 'allocation', 'goal'));
    }

    public function updatePlanning(string $tenant, UpdateJournalPlanningRequest $request, Journal $journal): JournalResource
    {
        $this->authorize('linkPlanning', $journal);

        $journal->update([
            'allocation_id' => $request->input('allocation_id'),
            'goal_id' => $request->input('goal_id'),
        ]);

        return new JournalResource($journal->fresh('lines.account', 'tags', 'allocation', 'goal'));
    }

    public function destroy(string $tenant, Journal $journal): JsonResponse
    {
        $this->authorize('delete', $journal);

        if ($journal->auditLogs()->exists()) {
            throw new ConflictHttpException('The journal cannot be deleted because it has audit logs.');
        }

        if ($journal->reversals()->exists()) {
            throw new ConflictHttpException('The journal cannot be deleted because it has reversals.');
        }

        DB::transaction(function () use ($journal): void {
            // Draft journals are fully mutable: archiving the journal also
            // archives its draft lines (soft delete) so no rows are physically
            // removed anywhere.
            $journal->lines()->get()->each->delete();
            $journal->tags()->detach();
            $journal->delete();
        });

        return response()->json(null, 204);
    }

    public function reverse(string $tenant, Journal $journal): JsonResponse
    {
        $this->authorize('reverse', $journal);

        $reversal = retry(
            times: 3,
            callback: fn () => DB::transaction(function () use ($journal) {
                if ($journal->reversals()->exists()) {
                    throw new ConflictHttpException('The journal has already been reversed.');
                }

                $reversal = Journal::create([
                    'transaction_date' => now(),
                    'description' => "Reversal of {$journal->reference}",
                    'reference' => 'REV-'.$journal->reference.'-'.Str::upper(Str::random(6)),
                    'status' => 'posted',
                    'source' => 'system',
                    'reverse_from_id' => $journal->id,
                ]);

                foreach ($journal->lines as $index => $line) {
                    $reversal->lines()->create([
                        'account_id' => $line->account_id,
                        'line_number' => $index + 1,
                        'debit' => $line->credit,
                        'credit' => $line->debit,
                        'description' => "Reversal of: {$line->description}",
                    ]);
                }

                $reversal->tags()->sync($journal->tags->pluck('id'));

                return $reversal;
            }),
            sleepMilliseconds: 50,
            when: fn (Throwable $e) => $e instanceof UniqueConstraintViolationException,
        );

        return (new JournalResource($reversal->load('lines.account', 'tags', 'allocation', 'goal')))
            ->response()
            ->setStatusCode(201);
    }
}
