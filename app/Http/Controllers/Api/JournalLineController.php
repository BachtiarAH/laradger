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

        return JournalLineResource::collection(
            JournalLine::with('account', 'journal')->paginate()
        );
    }

    public function store(string $tenant, StoreJournalLineRequest $request): JsonResponse
    {
        $journal = Journal::findOrFail($request->validated('journal_id'));

        $this->authorize('update', $journal);

        $line = $journal->lines()->create($request->safe()->except('journal_id'));

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
