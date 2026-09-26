<?php

namespace App\Services\Ai\Omni;

use App\Models\AiActionDraft;
use App\Services\Ai\Tools\AiToolRegistry;
use Illuminate\Support\Collection;

/**
 * Turns the "I need a tag called X" strings in a draft payload into real rows.
 *
 * The payload has to carry the name rather than an id, because the tag or account
 * does not exist yet — that is the whole reason it is pending. But a name is not
 * something an executor can order or a review card can display, so the pairing is
 * recorded here as a relation while the name stays in the payload for the user to
 * read and for review to keep equalling sent.
 *
 * Matched against every still-pending draft in the same conversation rather than
 * only the ones from this turn: the model is told to propose both in one reply, but
 * nothing stops it from proposing the account in one turn and the journal in the
 * next, and that is still one dependency.
 */
class DraftDependencyResolver
{
    public function __construct(
        private readonly AiToolRegistry $tools,
    ) {}

    /**
     * @param  Collection<int, AiActionDraft>  $proposed  drafts created this turn
     */
    public function wire(Collection $proposed): void
    {
        foreach ($proposed as $draft) {
            $tool = $this->tools->get($draft->tool);

            // Optional, the same way `payload()` is: only a tool that can be
            // waiting on another draft has anything to declare.
            if (! method_exists($tool, 'pendingReferences')) {
                continue;
            }

            foreach ($tool->pendingReferences($draft->payload) as $reference) {
                $provider = $this->providerFor($draft, $reference['tool'], $reference['name']);

                if ($provider !== null) {
                    $draft->dependencies()->syncWithoutDetaching([$provider->getKey()]);
                }
            }
        }
    }

    /**
     * The pending sibling that will create this name, if there is one.
     *
     * Names are compared after trimming and case-sensitively, deliberately the
     * same comparison `JournalCreateTool::execute()` will do when it resolves the
     * reference by name. Matching loosely here would record a dependency that then
     * fails at approval with a message about a name that does not exist — trading
     * a clear error for a confusing one.
     */
    private function providerFor(AiActionDraft $draft, string $tool, string $name): ?AiActionDraft
    {
        return $draft->conversation->drafts()
            ->where('tool', $tool)
            ->where('status', AiActionDraft::STATUS_PENDING)
            ->get()
            ->first(fn (AiActionDraft $candidate): bool => $candidate->isNot($draft)
                && trim((string) ($candidate->payload['name'] ?? '')) === trim($name));
    }
}
