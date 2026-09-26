<?php

namespace App\Jobs;

use App\Models\AiActionDraft;
use App\Models\AiConversation;
use App\Models\AiDraftRequest;
use App\Models\Tenant;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Omni\OmniAssistant;
use App\Services\Ai\Omni\SystemPromptBuilder;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works one submitted drafting prompt in the background.
 *
 * This is the *drafting* path, not the chat path. Chatting is synchronous
 * (see AiAssistantController::sendMessage); drafting is fire-and-forget,
 * because a batch of transactions is worth queueing and reviewing later.
 *
 * Like any job it has no tenant context and no authenticated user, so both are
 * rebuilt here — without them every query fails closed, since
 * `BelongsToTenant` is fail-closed with no context.
 */
class RunAiDraftRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt: the gateway already falls back across providers, so a retry
     * is another round of paid calls for no new information.
     */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly string $draftRequestId,
    ) {}

    public function handle(OmniAssistant $assistant): void
    {
        $request = AiDraftRequest::withoutGlobalScopes()->find($this->draftRequestId);

        if ($request === null) {
            return;
        }

        $tenant = Tenant::find($request->tenant_id);
        $user = $request->user;

        if ($tenant === null || $user === null) {
            $this->markFailed($request, 'The drafting request could not be resumed.');

            return;
        }

        TenantContext::set($tenant);
        Auth::setUser($user);

        $request->forceFill([
            'status' => AiDraftRequest::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ])->save();

        try {
            // A conversation holds the turn's messages and drafts, exactly as
            // for a chat turn; the request itself is what gets queued.
            $conversation = AiConversation::create([
                'user_id' => $user->getKey(),
                'title' => str($request->prompt)->limit(80)->toString(),
            ]);

            $request->forceFill(['ai_conversation_id' => $conversation->getKey()])->save();

            $conversation->forceFill([
                'status' => AiConversation::STATUS_RUNNING,
                'started_at' => now(),
            ])->save();

            $outcome = $assistant->respond(
                $conversation,
                $request->prompt,
                SystemPromptBuilder::MODE_DRAFTING,
            );

            // Stamp the drafts with the prompt that produced them, so the drafts
            // list can say which submission each came from. Done here rather
            // than inside the assistant because the assistant is shared with
            // the chat path, which has no request.
            if ($outcome['drafts']->isNotEmpty()) {
                AiActionDraft::withoutGlobalScopes()
                    ->whereIn('id', $outcome['drafts']->map(fn (AiActionDraft $draft): string => $draft->getKey())->all())
                    ->update(['ai_draft_request_id' => $request->getKey()]);
            }

            $conversation->forceFill([
                'status' => AiConversation::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();

            $request->forceFill([
                'status' => AiDraftRequest::STATUS_COMPLETED,
                'drafts_count' => $outcome['drafts']->count(),
                // Only meaningful when nothing was drafted. Left null otherwise,
                // so "produced drafts" needs no outcome value to explain itself.
                'outcome' => $outcome['drafts']->isEmpty() ? $outcome['outcome']['outcome'] ?? null : null,
                'outcome_reason' => $outcome['drafts']->isEmpty() ? $outcome['outcome']['reason'] ?? null : null,
                'outcome_reference' => $outcome['drafts']->isEmpty() ? $outcome['outcome']['reference'] ?? null : null,
                // Kept so an answer the model had to give is not buried in a
                // transcript the user never opens.
                'reply' => $outcome['reply'],
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $this->markFailed($request, $this->messageFor($e), $e);

            throw $e;
        } finally {
            TenantContext::forget();
        }
    }

    public function failed(?Throwable $e): void
    {
        $request = AiDraftRequest::withoutGlobalScopes()->find($this->draftRequestId);

        if ($request !== null && ! $request->isSettled()) {
            $this->markFailed($request, 'The drafting request could not be completed.', $e);
        }
    }

    /**
     * A safe, user-facing sentence. The raw provider payload is logged, never
     * returned.
     */
    private function messageFor(Throwable $e): string
    {
        return $e instanceof AiProviderException
            ? $e->getMessage()
            : 'The assistant could not prepare any drafts for this request.';
    }

    private function markFailed(AiDraftRequest $request, string $message, ?Throwable $e = null): void
    {
        Log::error('An AI drafting request failed.', [
            'draft_request_id' => $request->getKey(),
            'tenant_id' => $request->tenant_id,
            'exception' => $e ? $e::class : null,
            'error' => $e?->getMessage(),
        ]);

        $request->forceFill([
            'status' => AiDraftRequest::STATUS_FAILED,
            'error' => $message,
            'completed_at' => now(),
        ])->save();
    }
}
