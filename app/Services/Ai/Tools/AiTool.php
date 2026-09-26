<?php

namespace App\Services\Ai\Tools;

/**
 * An action the assistant may propose.
 *
 * The registry of tools *is* the security boundary of the assistant: anything
 * not registered here can never be reached, no matter what the model asks for.
 * Adding a tool is therefore a deliberate act, not a refactor.
 */
interface AiTool
{
    /**
     * Stable dotted identifier, e.g. `journal.create`. Persisted on drafts, so
     * renaming one orphans existing pending drafts.
     */
    public function name(): string;

    /**
     * Sent to the model verbatim. This is the only thing teaching the model
     * when to reach for the tool, so it should say *when*, not just *what*.
     */
    public function description(): string;

    public function kind(): AiToolKind;

    /**
     * JSON Schema (object type) describing the accepted arguments.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * A short human summary shown on the review card, e.g.
     * "Create journal: Groceries 45.50". Must be derived only from arguments
     * that are about to be persisted, so the review UI cannot disagree with what
     * will actually happen.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function title(array $arguments): string;

    /**
     * Perform the action. For read tools this runs immediately; for write tools
     * it runs only after the user confirms the draft.
     *
     * Implementations must go through the real request/controller path so
     * policies, form-request validation, transactions, and audit logging all
     * still apply.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array;
}
