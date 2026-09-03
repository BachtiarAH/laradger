<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJournalTagRequest;
use App\Http\Resources\JournalTagResource;
use App\Models\Journal;
use App\Models\JournalTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JournalTagController extends Controller
{
    public function index(string $tenant): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JournalTag::class);

        return JournalTagResource::collection(
            JournalTag::with('journal', 'tag')->paginate()
        );
    }

    public function store(string $tenant, StoreJournalTagRequest $request): JsonResponse
    {
        $journal = Journal::findOrFail($request->validated('journal_id'));

        abort_unless($request->user()->belongsToTenant($journal->tenant_id), 403);

        $journalTag = JournalTag::create($request->validated());

        return (new JournalTagResource($journalTag->load('journal', 'tag')))
            ->response()
            ->setStatusCode(201);
    }
}
