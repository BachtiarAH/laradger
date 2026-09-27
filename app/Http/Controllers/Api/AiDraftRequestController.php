<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAiDraftRequest;
use App\Http\Resources\AiDraftRequestResource;
use App\Jobs\RunAiDraftRequest;
use App\Models\AiDraftRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The queued, fire-and-forget drafting path.
 *
 * Separate from the assistant chat on purpose. Chat is synchronous because the
 * user is waiting for an answer; drafting is a prompt to be worked on in the
 * background, because a batch of transactions is worth queueing and reviewing
 * later. Each request keeps its own status so the user can see which of the
 * prompts they sent are still queued, still running, done, or failed.
 */
class AiDraftRequestController extends Controller
{
    /**
     * The caller's own requests, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return AiDraftRequestResource::collection(
            AiDraftRequest::query()
                ->where('user_id', $request->user()->getKey())
                ->latest()
                ->limit(50)
                ->get()
        );
    }

    /**
     * Queue a drafting prompt. Returns 202 immediately; the drafts appear in
     * `ai_action_drafts` when the worker gets to it.
     */
    public function store(StoreAiDraftRequest $request): JsonResponse
    {
        $draftRequest = AiDraftRequest::create([
            'user_id' => $request->user()->getKey(),
            'prompt' => $request->validated('prompt'),
            'status' => AiDraftRequest::STATUS_QUEUED,
            'drafts_count' => 0,
            'queued_at' => now(),
        ]);

        try {
            RunAiDraftRequest::dispatch($draftRequest->getKey())
                ->onQueue((string) config('ai.queue.name', 'default'));
        } catch (Throwable $e) {
            // Without this the request would sit at "queued" forever and read as
            // merely slow rather than broken.
            Log::error('An AI drafting request could not be queued.', [
                'draft_request_id' => $draftRequest->getKey(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $draftRequest->forceFill([
                'status' => AiDraftRequest::STATUS_FAILED,
                'error' => 'The request could not be queued. Please try again.',
                'completed_at' => now(),
            ])->save();
        }

        return response()->json([
            'data' => (new AiDraftRequestResource($draftRequest->refresh()))->resolve($request),
        ], 202);
    }

    /**
     * Cancel a queued or running draft request.
     *
     * If the job has not started yet, it is removed from the queue. If it is
     * already running, the request is marked as cancelled and the job will
     * discard the results when the current AI call finishes.
     */
    public function cancel(string $tenant, Request $request, AiDraftRequest $draftRequest): JsonResponse
    {
        $this->authorize('update', $draftRequest);

        if ($draftRequest->isSettled()) {
            return response()->json([
                'data' => (new AiDraftRequestResource($draftRequest))->resolve($request),
            ]);
        }

        // If still queued, try to delete the job from the queue so the worker
        // never picks it up. If it is already running, the job will check the
        // status and exit early.
        if ($draftRequest->status === AiDraftRequest::STATUS_QUEUED) {
            // The job ID is not stored on the model, so we rely on the status
            // check inside the job. Marking as cancelled is sufficient — the job
            // checks before doing any work.
            $draftRequest->forceFill([
                'status' => AiDraftRequest::STATUS_CANCELLED,
                'completed_at' => now(),
                'error' => null,
            ])->save();
        } else {
            // Running: mark as cancelled, the job will check after respond().
            $draftRequest->forceFill([
                'status' => AiDraftRequest::STATUS_CANCELLED,
                'completed_at' => now(),
                'error' => null,
            ])->save();
        }

        return response()->json([
            'data' => (new AiDraftRequestResource($draftRequest->refresh()))->resolve($request),
        ]);
    }
}
