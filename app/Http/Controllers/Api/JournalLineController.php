<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJournalLineRequest;
use App\Http\Requests\UpdateJournalLineRequest;
use App\Http\Resources\JournalLineResource;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JournalLineController extends Controller
{
    public function index(string $tenant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JournalLine::class);

        $lines = JournalLine::with('account', 'journal')
            ->when(request('account_id'), fn ($q) => $q->where('account_id', request('account_id')))
            ->when(request('journal_id'), fn ($q) => $q->where('journal_id', request('journal_id')))
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

    public function store(string $tenant, StoreJournalLineRequest $request): JsonResponse
    {
        $journal = Journal::findOrFail($request->validated('journal_id'));

        $this->authorize('update', $journal);

        $line = $journal->lines()->create([
            ...$request->safe()->except('journal_id'),
            'line_number' => $journal->lines()->max('line_number') + 1,
        ]);

        return (new JournalLineResource($line->load('account', 'journal')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $tenant, JournalLine $journalLine): JournalLineResource
    {
        $this->authorize('view', $journalLine);

        return new JournalLineResource($journalLine->load('account', 'journal'));
    }

    public function update(string $tenant, UpdateJournalLineRequest $request, JournalLine $journalLine): JournalLineResource
    {
        $this->authorize('update', $journalLine);

        $journalLine->update($request->validated());

        return new JournalLineResource($journalLine->fresh('account', 'journal'));
    }

    public function destroy(string $tenant, JournalLine $journalLine): JsonResponse
    {
        $this->authorize('delete', $journalLine);

        $journalLine->delete();

        return response()->json(null, 204);
    }
}
