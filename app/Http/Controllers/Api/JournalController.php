<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJournalRequest;
use App\Http\Requests\UpdateJournalRequest;
use App\Http\Resources\JournalResource;
use App\Models\Journal;
use App\Services\Ai\Contracts\AiCallRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class JournalController extends Controller
{
    public function __construct(
        private readonly AiCallRecorder $aiCallRecorder,
    ) {}

    public function index(string $tenant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Journal::class);

        $journals = Journal::withCount('lines')
            ->withSum('lines', 'debit')
            ->withSum('lines', 'credit')
            ->with('tags')
            ->when(request('status'), fn ($query) => $query->where('status', request('status')))
            ->when(request('source'), fn ($query) => $query->where('source', request('source')))
            ->when(request('from'), fn ($query) => $query->whereDate('transaction_date', '>=', request('from')))
            ->when(request('to'), fn ($query) => $query->whereDate('transaction_date', '<=', request('to')))
            ->latest('transaction_date')
            ->paginate();

        return JournalResource::collection($journals);
    }

    public function store(string $tenant, StoreJournalRequest $request): JsonResponse
    {
        $this->authorize('create', Journal::class);

        $journal = retry(
            times: 3,
            callback: fn () => DB::transaction(function () use ($request) {
                $journal = Journal::create($request->safe()->except(['lines', 'tags', 'ai_record_id']));

                foreach ($request->validated('lines', []) as $index => $line) {
                    $journal->lines()->create($line + ['line_number' => $index + 1]);
                }

                $journal->tags()->sync($request->validated('tags', []));

                if ($recordId = $request->validated('ai_record_id')) {
                    $this->aiCallRecorder->confirm($recordId, $journal);
                }

                return $journal;
            }),
            sleepMilliseconds: 50,
            when: fn (Throwable $e) => $e instanceof UniqueConstraintViolationException,
        );

        return (new JournalResource($journal->load('lines.account', 'tags')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $tenant, Journal $journal): JournalResource
    {
        $this->authorize('view', $journal);

        return new JournalResource($journal->load('lines.account', 'tags'));
    }

    public function update(string $tenant, UpdateJournalRequest $request, Journal $journal): JournalResource
    {
        $this->authorize('update', $journal);

        DB::transaction(function () use ($request, $journal) {
            $journal->update($request->safe()->except(['lines', 'tags']));

            if ($request->has('lines')) {
                $journal->lines()->delete();
                foreach ($request->validated('lines', []) as $index => $line) {
                    $journal->lines()->create($line + ['line_number' => $index + 1]);
                }
            }

            if ($request->has('tags')) {
                $journal->tags()->sync($request->validated('tags', []));
            }
        });

        return new JournalResource($journal->fresh('lines.account', 'tags'));
    }

    public function destroy(string $tenant, Journal $journal): JsonResponse
    {
        $this->authorize('delete', $journal);

        if ($journal->lines()->exists()) {
            throw new ConflictHttpException('The journal cannot be deleted because it has journal lines.');
        }

        if ($journal->auditLogs()->exists()) {
            throw new ConflictHttpException('The journal cannot be deleted because it has audit logs.');
        }

        if ($journal->reversals()->exists()) {
            throw new ConflictHttpException('The journal cannot be deleted because it has reversals.');
        }

        $journal->delete();

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

        return (new JournalResource($reversal->load('lines.account', 'tags')))
            ->response()
            ->setStatusCode(201);
    }
}
