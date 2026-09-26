<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendAiMessageRequest;
use App\Http\Requests\UpdateAiDraftRequest;
use App\Http\Resources\AiActionDraftResource;
use App\Http\Resources\AiConversationResource;
use App\Http\Resources\AiMessageResource;
use App\Models\AiActionDraft;
use App\Models\AiConversation;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Omni\DraftExecutor;
use App\Services\Ai\Omni\OmniAssistant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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
        $this->assertOwner($conversation, $request = request());

        $conversation->load(['messages', 'drafts']);

        return response()->json([
            'data' => [
                'conversation' => (new AiConversationResource($conversation))->resolve($request),
                'messages' => AiMessageResource::collection($conversation->messages)->resolve($request),
                'drafts' => AiActionDraftResource::collection($conversation->drafts)->resolve($request),
            ],
        ]);
    }

    public function sendMessage(
        string $tenant,
        SendAiMessageRequest $request,
        AiConversation $conversation,
    ): JsonResponse {
        $this->assertOwner($conversation, $request);

        try {
            $outcome = $this->assistant->reply($conversation, $request->validated('message'));
        } catch (AiProviderException $e) {
            return response()->json([
                'message' => 'The assistant could not respond.',
                'errors' => ['message' => [$e->getMessage()]],
            ], 502);
        }

        return response()->json([
            'data' => [
                'reply' => $outcome['reply'],
                // Nothing has been applied. These are proposals awaiting review.
                'drafts' => AiActionDraftResource::collection($outcome['drafts'])->resolve($request),
            ],
        ]);
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
