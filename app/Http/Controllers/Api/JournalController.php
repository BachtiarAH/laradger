<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJournalRequest;
use App\Http\Requests\UpdateJournalRequest;
use App\Http\Resources\JournalResource;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class JournalController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Journal::class);

        $journals = Journal::with('lines.account', 'tags')
            ->when(request('status'), fn ($query) => $query->where('status', request('status')))
            ->when(request('source'), fn ($query) => $query->where('source', request('source')))
            ->when(request('from'), fn ($query) => $query->whereDate('transaction_date', '>=', request('from')))
            ->when(request('to'), fn ($query) => $query->whereDate('transaction_date', '<=', request('to')))
            ->latest('transaction_date')
            ->paginate();

        return JournalResource::collection($journals);
    }

    public function store(StoreJournalRequest $request): JsonResponse
    {
        $this->authorize('create', Journal::class);

        $journal = $request->user()->journals()->create($request->safe()->except(['lines', 'tags']));

        foreach ($request->validated('lines', []) as $line) {
            $journal->lines()->create($line);
        }

        $journal->tags()->sync($request->validated('tags', []));

        return (new JournalResource($journal->load('lines.account', 'tags')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Journal $journal): JournalResource
    {
        $this->authorize('view', $journal);

        return new JournalResource($journal->load('lines.account', 'tags', 'user'));
    }

    public function update(UpdateJournalRequest $request, Journal $journal): JournalResource
    {
        $this->authorize('update', $journal);

        $journal->update($request->safe()->except(['lines', 'tags']));

        if ($request->has('lines')) {
            $journal->lines()->delete();
            foreach ($request->validated('lines', []) as $line) {
                $journal->lines()->create($line);
            }
        }

        if ($request->has('tags')) {
            $journal->tags()->sync($request->validated('tags', []));
        }

        return new JournalResource($journal->fresh('lines.account', 'tags'));
    }

    public function destroy(Journal $journal): JsonResponse
    {
        $this->authorize('delete', $journal);

        $journal->delete();

        return response()->json(null, 204);
    }

    public function reverse(Journal $journal): JsonResponse
    {
        $this->authorize('reverse', $journal);

        $reversal = $journal->user->journals()->create([
            'transaction_date' => now(),
            'description' => "Reversal of {$journal->reference}",
            'reference' => 'REV-'.$journal->reference.'-'.Str::upper(Str::random(6)),
            'status' => 'posted',
            'source' => 'system',
            'reverse_from_id' => $journal->id,
        ]);

        foreach ($journal->lines as $line) {
            $reversal->lines()->create([
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'description' => "Reversal of: {$line->description}",
            ]);
        }

        $reversal->tags()->sync($journal->tags->pluck('id'));

        return (new JournalResource($reversal->load('lines.account', 'tags')))
            ->response()
            ->setStatusCode(201);
    }
}
