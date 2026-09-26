<?php

namespace App\Services\Ai\Omni;

use App\Models\AiActionDraft;
use App\Models\AuditLog;
use App\Services\Ai\Tools\AiToolKind;
use App\Services\Ai\Tools\AiToolRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Runs a draft the user has explicitly approved.
 *
 * This is the *only* place a write tool executes, and it is reachable only from
 * an explicit user action on a pending draft. It never runs a read tool, and it
 * refuses anything that is not pending, so a draft cannot be replayed.
 */
class DraftExecutor
{
    public function __construct(
        private readonly AiToolRegistry $tools,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(AiActionDraft $draft): array
    {
        if (! $draft->isPending()) {
            throw new RuntimeException('This draft has already been settled.');
        }

        $tool = $this->tools->get($draft->tool);

        if ($tool->kind() !== AiToolKind::Write) {
            throw new RuntimeException('Only write actions can be approved.');
        }

        // A tool removed from the registry between proposing and approving must
        // fail loudly rather than silently doing nothing.
        try {
            $result = DB::transaction(fn (): array => $tool->execute($draft->payload));
        } catch (ValidationException $e) {
            $draft->update([
                'status' => AiActionDraft::STATUS_FAILED,
                'error' => 'The action could not be applied: '.collect($e->errors())->flatten()->first(),
                'executed_at' => now(),
            ]);

            throw $e;
        } catch (AuthorizationException $e) {
            $draft->update([
                'status' => AiActionDraft::STATUS_FAILED,
                'error' => 'You are not allowed to perform this action.',
                'executed_at' => now(),
            ]);

            throw $e;
        }

        $draft->update([
            'status' => AiActionDraft::STATUS_EXECUTED,
            'result' => $result,
            'error' => null,
            'executed_at' => now(),
        ]);

        $this->audit($draft);

        return $result;
    }

    public function reject(AiActionDraft $draft): AiActionDraft
    {
        if (! $draft->isPending()) {
            throw new RuntimeException('This draft has already been settled.');
        }

        $draft->update([
            'status' => AiActionDraft::STATUS_REJECTED,
            'rejected_at' => now(),
        ]);

        return $draft->refresh();
    }

    /**
     * AI-driven changes land in the normal audit trail so they are
     * distinguishable from hand-entered ones during a review.
     */
    private function audit(AiActionDraft $draft): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'ai.'.$draft->tool,
            'before' => null,
            'after' => $draft->result,
            'reason' => 'Approved AI draft '.$draft->getKey(),
        ]);
    }
}
