<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendAiMessageRequest;
use App\Http\Requests\UpdateAiDraftRequest;
use App\Http\Resources\AiActionDraftResource;
use App\Http\Resources\AiConversationResource;
use App\Http\Resources\AiMessageResource;
use App\Jobs\RunAiAssistantTurn;
use App\Models\AiActionDraft;
use App\Models\AiConversation;
use App\Services\Ai\Omni\DraftExecutor;
use App\Services\Ai\Omni\OmniAssistant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AiAssistantController extends Controller
{
    public function __construct(
        private readonly OmniAssistant $assistant,
        private readonly DraftExecutor $executor,
    ) {}

    public function conversations(): AnonymousResourceCollection
    {
        return AiConversationResource::collection(
            AiConversation::query()
                ->where('user_id', request()->user()->getKey())
                ->withCount('pendingDrafts')
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get()
        );
    }

    public function createConversation(Request $request): AiConversationResource
    {
        $conversation = AiConversation::create([
            'user_id' => $request->user()->getKey(),
            'title' => null,
        ]);

        return new AiConversationResource($conversation);
    }

    public function show(string $tenant, AiConversation $conversation): JsonResponse
    {
        $this->assertOwner($conversation, request());

        $conversation->load(['messages', 'drafts']);

        return response()->json([
            'data' => [
                'conversation' => (new AiConversationResource($conversation->refresh()))->resolve(request()),
                'messages' => AiMessageResource::collection($conversation->messages)->resolve(request()),
                'drafts' => AiActionDraftResource::collection($conversation->drafts)->resolve(request()),
            ],
        ]);
    }

    /**
     * Lightweight progress probe. The drawer polls this while a turn is in
     * flight so it does not refetch the whole transcript every couple of
     * seconds.
     */
    public function status(string $tenant, Request $request, AiConversation $conversation): JsonResponse
    {
        $this->assertOwner($conversation, $request);

        return response()->json([
            'data' => [
                'status' => $conversation->status,
                'error' => $conversation->error,
                'pending_drafts_count' => $conversation->pendingDrafts()->count(),
                'queued_at' => $conversation->queued_at?->toIso8601String(),
                'started_at' => $conversation->started_at?->toIso8601String(),
                'completed_at' => $conversation->completed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Accept a message and queue the turn.
     *
     * Returns 202 immediately: the reply and any proposed actions are produced
     * in the background, so the user does not wait on several paid model calls
     * and can leave the page open or closed.
     */
    public function sendMessage(
        string $tenant,
        SendAiMessageRequest $request,
        AiConversation $conversation,
    ): JsonResponse {
        $this->assertOwner($conversation, $request);

        // Two concurrent turns would interleave their transcripts and drafts.
        if ($conversation->isBusy()) {
            return response()->json([
                'message' => 'A request is still being worked on in this conversation.',
            ], 409);
        }

        $message = $this->assistant->recordUserMessage(
            $conversation,
            $request->validated('message'),
        );

        $conversation->forceFill([
            'status' => AiConversation::STATUS_QUEUED,
            'queued_at' => now(),
            'error' => null,
        ])->save();

        // Under the `sync` driver the job runs inline, so a failing turn would
        // otherwise surface as a 500 on the message itself. The contract is the
        // same either way: the turn was accepted, and its outcome is reported
        // through the status endpoint. A dispatch failure that leaves the
        // conversation still "queued" is marked here so it cannot hang.
        try {
            RunAiAssistantTurn::dispatch($conversation->getKey(), $message->getKey())
                ->onQueue((string) config('ai.queue.name', 'default'));
        } catch (Throwable $e) {
            Log::error('The AI assistant turn could not be queued.', [
                'conversation_id' => $conversation->getKey(),
                'message_id' => $message->getKey(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $conversation->forceFill([
                'status' => AiConversation::STATUS_FAILED,
                'error' => 'The request could not be queued. Please try again.',
                'completed_at' => now(),
            ])->save();
        }

        return response()->json([
            'data' => [
                'status' => $conversation->refresh()->status,
                'conversation_id' => $conversation->getKey(),
                'message_id' => $message->getKey(),
            ],
        ], 202);
    }

    public function drafts(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        return AiActionDraftResource::collection(
            AiActionDraft::query()
                ->whereHas('conversation', fn ($query) => $query->where('user_id', $request->user()->getKey()))
                ->when(filled($status), fn ($query) => $query->where('status', $status))
                ->latest()
                ->limit(100)
                ->get()
        );
    }

    public function updateDraft(
        string $tenant,
        UpdateAiDraftRequest $request,
        AiActionDraft $draft,
    ): AiActionDraftResource {
        $draft->update(['payload' => $request->validated('payload')]);

        return new AiActionDraftResource($draft->refresh());
    }

    public function executeDraft(string $tenant, Request $request, AiActionDraft $draft): JsonResponse
    {
        $this->assertOwner($draft->conversation, $request);

        try {
            $this->executor->execute($draft);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The action could not be applied.',
                'errors' => $e->errors(),
            ], 422);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'You are not allowed to perform this action.'], 403);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return (new AiActionDraftResource($draft->refresh()))->response();
    }

    public function rejectDraft(string $tenant, Request $request, AiActionDraft $draft): JsonResponse
    {
        $this->assertOwner($draft->conversation, $request);

        try {
            $this->executor->reject($draft);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return (new AiActionDraftResource($draft->refresh()))->response();
    }

    /**
     * Conversations and drafts are personal, not shared with the rest of the
     * tenant: a member must not be able to read or approve another's proposals.
     *
     * 404 rather than 403 so a non-owner learns nothing about what exists.
     */
    private function assertOwner(AiConversation $conversation, Request $request): void
    {
        abort_unless($conversation->user_id === $request->user()->getKey(), 404);
    }
}
