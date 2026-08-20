<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAiJournalDraftRequest;
use App\Models\Account;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Tasks\JournalDraftTask;
use Illuminate\Http\JsonResponse;

class AiJournalDraftController extends Controller
{
    public function __construct(
        private readonly JournalDraftTask $task,
    ) {}

    public function store(string $tenant, StoreAiJournalDraftRequest $request): JsonResponse
    {
        $accounts = Account::query()
            ->select('name', 'type')
            ->orderBy('name', 'asc')
            ->get()
            ->toArray();

        try {
            $draft = $this->task->draft(
                $request->validated('statement'),
                $accounts,
            );
        } catch (AiProviderException $e) {
            return response()->json([
                'message' => 'The AI draft could not be generated.',
                'errors' => [
                    'statement' => [$e->getMessage()],
                ],
            ], 502);
        }

        return response()->json([
            'data' => $draft->toArray(),
        ]);
    }
}
