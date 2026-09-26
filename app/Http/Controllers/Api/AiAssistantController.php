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
use Illuminate\Support\Collection;
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
        $this->assertOwner($conversation, request());

        $conversation->load(['messages', 'drafts']);

        return response()->json([
            'data' => [
                'conversation' => (new AiConversationResource($conversation->refresh()))->resolve(request()),
                'messages' => AiMessageResource::collection($conversation->messages)->resolve(request()),
                'drafts' => AiActionDraftResource::collection(
                    $conversation->drafts()->with('dependencies')->get()
                )->resolve(request()),
            ],
        ]);
    }

    /**
     * Chat with the assistant, synchronously.
     *
     * Deliberately not queued: this is a conversation, and the user is waiting
     * for the answer. Drafting — the fire-and-forget path — is the queued one,
     * via `AiDraftRequestController`.
     *
     * Read tools run inline so the assistant has facts. Write tools are only
     * ever proposed: they come back as pending drafts for review.
     */
    public function sendMessage(
        string $tenant,
        SendAiMessageRequest $request,
        AiConversation $conversation,
    ): JsonResponse {
        $this->assertOwner($conversation, $request);

        $this->assistant->recordUserMessage(
            $conversation,
            $request->validated('message'),
        );

        $conversation->forceFill([
            'status' => AiConversation::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ])->save();

        try {
            $outcome = $this->assistant->respond($conversation);
        } catch (AiProviderException $e) {
            $conversation->forceFill([
                'status' => AiConversation::STATUS_FAILED,
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ])->save();

            return response()->json([
                'message' => 'The assistant could not respond.',
                'errors' => ['message' => [$e->getMessage()]],
            ], 502);
        }

        $conversation->forceFill([
            'status' => AiConversation::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        return response()->json([
            'data' => [
                'reply' => $outcome['reply'],
                // Nothing has been applied. These are proposals awaiting review.
                'drafts' => AiActionDraftResource::collection(
                    $this->withDependencies($outcome['drafts'])
                )->resolve($request),
                // Non-null only when the turn deliberately proposed nothing, so the
                // drawer can say "already recorded as JRN-x" rather than showing an
                // empty turn with no explanation.
                'outcome' => $outcome['drafts']->isEmpty() ? $outcome['outcome'] : null,
            ],
        ]);
    }

    /**
     * Re-read the given drafts with their prerequisites attached, keeping order.
     *
     * The turn's drafts are collected in a plain collection as they are proposed,
     * and the resolver attaches a dependency relation to the ones that have one —
     * so the relation is present but only ever partially loaded. One query for the
     * whole turn beats a lazy load per card.
     *
     * @param  Collection<int, AiActionDraft>  $drafts
     * @return Collection<int, AiActionDraft>
     */
    private function withDependencies(Collection $drafts): Collection
    {
        if ($drafts->isEmpty()) {
            return $drafts;
        }

        $loaded = AiActionDraft::query()
            ->with('dependencies')
            ->whereIn('id', $drafts->map(fn (AiActionDraft $draft) => $draft->getKey())->all())
            ->get()
            ->keyBy(fn (AiActionDraft $draft): string => $draft->getKey());

        return $drafts->map(fn (AiActionDraft $draft): AiActionDraft => $loaded->get($draft->getKey()) ?? $draft);
    }

    public function drafts(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        return AiActionDraftResource::collection(
            AiActionDraft::query()
                ->with('dependencies')
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

        return new AiActionDraftResource($draft->refresh()->load('dependencies'));
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

        return (new AiActionDraftResource($draft->refresh()->load('dependencies')))->response();
    }

    public function rejectDraft(string $tenant, Request $request, AiActionDraft $draft): JsonResponse
    {
        $this->assertOwner($draft->conversation, $request);

        try {
            $this->executor->reject($draft);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return (new AiActionDraftResource($draft->refresh()->load('dependencies')))->response();
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
