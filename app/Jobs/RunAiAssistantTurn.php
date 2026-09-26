<?php

namespace App\Jobs;

use App\Models\AiConversation;
use App\Models\Tenant;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Omni\OmniAssistant;
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
 * Runs one assistant turn in the background.
 *
 * The user can say what they want and walk away; the drafts appear when it is
 * done. Nothing here executes a write tool — the turn only *proposes* actions,
 * which is what makes it safe to run unattended.
 *
 * A job has no tenant context and no authenticated user, so both are rebuilt
 * here. `BelongsToTenant` fails closed without a context, and read tools would
 * otherwise query across every tenant.
 */
class RunAiAssistantTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt on purpose. The gateway already falls back across providers,
     * and every retry is another round of paid model calls, so a transient
     * failure is reported to the user instead of being silently re-billed.
     */
    public int $tries = 1;

    /**
     * Generous: a turn can span several provider round trips plus read tools.
     */
    public int $timeout = 180;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $userMessageId,
    ) {}

    public function handle(OmniAssistant $assistant): void
    {
        $conversation = AiConversation::withoutGlobalScopes()->find($this->conversationId);

        if ($conversation === null) {
            // Deleted while queued; nothing to report to.
            return;
        }

        $tenant = Tenant::find($conversation->tenant_id);

        if ($tenant === null) {
            return;
        }

        $user = $conversation->user;

        if ($user === null) {
            $this->markFailed($conversation, 'The conversation owner no longer exists.');

            return;
        }

        TenantContext::set($tenant);
        // Read tools and the system prompt resolve the acting user from the
        // guard, so it must be restored for the tenant to mean anything.
        Auth::setUser($user);

        $conversation->forceFill([
            'status' => AiConversation::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ])->save();

        try {
            $assistant->respond($conversation);

            $conversation->forceFill([
                'status' => AiConversation::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $this->markFailed($conversation, $this->messageFor($e), $e);

            // Re-thrown so the failure is visible in failed_jobs rather than
            // swallowed, but the conversation already carries a safe message.
            throw $e;
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * Called by the worker when the job is finally given up on.
     */
    public function failed(?Throwable $e): void
    {
        $conversation = AiConversation::withoutGlobalScopes()->find($this->conversationId);

        if ($conversation !== null && ! $conversation->isBusy() && $conversation->error === null) {
            $this->markFailed($conversation, 'The assistant could not finish this request.', $e);
        }
    }

    /**
     * Provider failures are mapped to a safe, actionable sentence. The raw
     * provider response is logged, never returned, per the AI rules.
     */
    private function messageFor(Throwable $e): string
    {
        if ($e instanceof AiProviderException) {
            return $e->getMessage();
        }

        return 'The assistant could not finish this request. Please try again.';
    }

    private function markFailed(AiConversation $conversation, string $message, ?Throwable $e = null): void
    {
        Log::error('The AI assistant turn failed.', [
            'conversation_id' => $conversation->getKey(),
            'message_id' => $this->userMessageId,
            'exception' => $e ? $e::class : null,
            'error' => $e?->getMessage(),
        ]);

        $conversation->forceFill([
            'status' => AiConversation::STATUS_FAILED,
            'error' => $message,
            'completed_at' => now(),
        ])->save();
    }
}
