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
use Throwable;

/**
 * Runs a draft the user has explicitly approved.
 *
 * This is the *only* place a write tool executes, and it is reachable only from
 * an explicit user action on a pending draft. It never runs a read tool, and it
 * refuses anything already applied, so a draft cannot be replayed.
 *
 * Approving a draft runs the chain it depends on, in order, in one transaction.
 * The ordering used to be the user's problem and it went badly: a journal that
 * referenced a pending tag failed with "approve that tag first", and the failure
 * was terminal — the draft could not be edited, could not be retried, and had to
 * be thrown away and asked for again. Every link in that chain is an action the
 * user was already shown and can see on the review screen, so running them
 * together is the same decision, made once.
 *
 * What chaining does *not* weaken: every step still goes through its own tool's
 * `execute()` into the real FormRequest and controller, so validation, policies,
 * transactions and audit logging all still apply to each. What it gives up is the
 * user clicking each one separately, which bought nothing here except the chance
 * to get the order wrong.
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
        if (! $draft->isExecutable()) {
            throw new RuntimeException('This draft has already been settled.');
        }

        $chain = $this->chainFor($draft);
        $failed = null;

        try {
            DB::transaction(function () use ($chain, &$failed): void {
                foreach ($chain as $step) {
                    $failed = $step;
                    $this->runOne($step);
                }
            });
        } catch (ValidationException|AuthorizationException $e) {
            // Outside the transaction, because the write that records the failure
            // rolled back along with everything the chain had done. Only the step
            // that actually failed is marked: the ones before it were undone too,
            // so they stay pending and a retry re-runs the whole chain.
            $this->markFailed($failed, $e);

            throw $e;
        }

        return (array) $draft->refresh()->result;
    }

    /**
     * The drafts to run, dependencies first, ending with the one asked for.
     *
     * A dependency that is already applied is dropped rather than run again, which
     * is what makes approving a journal still work after the user has already
     * approved its tag by hand — the two flows do not have to be chosen up front.
     *
     * @return array<int, AiActionDraft>
     */
    private function chainFor(AiActionDraft $root): array
    {
        $ordered = [];
        $seen = [];

        $visit = function (AiActionDraft $draft) use (&$visit, &$ordered, &$seen): void {
            $key = $draft->getKey();

            if (($seen[$key] ?? null) === 'done') {
                return;
            }

            if (($seen[$key] ?? null) === 'visiting') {
                throw new RuntimeException('These drafts depend on each other in a loop.');
            }

            $seen[$key] = 'visiting';

            foreach ($draft->dependencies()->get() as $dependency) {
                if ($dependency->status === AiActionDraft::STATUS_EXECUTED) {
                    continue;
                }

                if (! $dependency->isExecutable()) {
                    throw new RuntimeException($this->blockedMessage($dependency));
                }

                $visit($dependency);
            }

            $seen[$key] = 'done';
            $ordered[] = $draft;
        };

        $visit($root);

        return $ordered;
    }

    /**
     * Why a chain cannot run, and what to do about it.
     *
     * Discarding a prerequisite is a decision, not a failure, so this refuses
     * rather than executing anything. The edit hint matters: the reference lives in
     * the payload, so removing it is a normal edit on the draft that is still
     * sitting there pending.
     */
    private function blockedMessage(AiActionDraft $dependency): string
    {
        $subject = $dependency->title;

        return match ($dependency->status) {
            AiActionDraft::STATUS_REJECTED => 'This needs "'.$subject.'", which you discarded. '
                .'Edit this draft to take the reference out, then approve it.',
            default => 'This needs "'.$subject.'", which could not be applied. '
                .'Fix or discard that one, or edit this draft to take the reference out.',
        };
    }

    /**
     * One step, inside the chain's transaction.
     *
     * @return array<string, mixed>
     */
    private function runOne(AiActionDraft $draft): array
    {
        $tool = $this->tools->get($draft->tool);

        if ($tool->kind() !== AiToolKind::Write) {
            throw new RuntimeException('Only write actions can be approved.');
        }

        // A tool removed from the registry between proposing and approving must
        // fail loudly rather than silently doing nothing.
        $result = $tool->execute($draft->payload);

        $draft->update([
            'status' => AiActionDraft::STATUS_EXECUTED,
            'result' => $result,
            'error' => null,
            'executed_at' => now(),
        ]);

        $this->audit($draft);

        return $result;
    }

    private function markFailed(?AiActionDraft $draft, Throwable $e): void
    {
        if ($draft === null) {
            return;
        }

        $draft->forceFill([
            'status' => AiActionDraft::STATUS_FAILED,
            'error' => match (true) {
                $e instanceof ValidationException => 'The action could not be applied: '
                    .collect($e->errors())->flatten()->first(),
                $e instanceof AuthorizationException => 'You are not allowed to perform this action.',
                default => 'The action could not be applied.',
            },
            'executed_at' => now(),
        ])->save();
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
