<?php

namespace App\Services\Ai\Omni;

use App\Models\Account;
use App\Models\Tag;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\AiToolKind;
use App\Services\Ai\Tools\AiToolRegistry;

/**
 * Builds the assistant's system prompt.
 *
 * The prompt is generated from live tenant data rather than hardcoded, because
 * the single most common failure mode for a bookkeeping model is inventing
 * account names instead of using real ids.
 */
class SystemPromptBuilder
{
    /**
     * Chat: the user is present and can answer.
     */
    public const MODE_CHAT = 'chat';

    /**
     * Drafting: fire-and-forget. The user has walked away, so a question is a
     * dead end and the turn would look like a silent failure.
     */
    public const MODE_DRAFTING = 'drafting';

    /**
     * The write tools that cover most real requests, called out in the prompt
     * ahead of the full list.
     */
    private const PRIMARY_TOOLS = ['journal_create', 'tag_create'];

    public function __construct(
        private readonly AiToolRegistry $tools,
    ) {}

    /**
     * @return array{role: string, content: string}
     */
    public function system(string $mode = self::MODE_CHAT): array
    {
        return [
            'role' => 'system',
            'content' => implode("\n\n", array_filter([
                $this->role(),
                $this->today(),
                $this->chartOfAccounts(),
                $this->tags(),
                $this->hardRules(),
                $this->batching(),
                $mode === self::MODE_DRAFTING ? $this->unattended() : null,
                $this->primaryActions(),
                $this->capabilities(),
            ])),
        ];
    }

    /**
     * Only added for the queued drafting path.
     */
    private function unattended(): string
    {
        return <<<'PROMPT'
        You are running unattended: nobody is watching this turn and there is no
        way to reply to you. So:

        - Never end your turn on a question. There is nobody to answer it.
        - If the instruction is ambiguous, pick the most ordinary bookkeeping
          reading, propose the draft anyway, and say in one line which
          interpretation you chose.
        - If no existing account fits, propose `account_create` in this same reply
          and reference the account as `pending:<name>` in the journal. Never guess
          an account id, and never pass a `draft_id` as one.
        - Every draft is reviewed by a human before anything is applied, so a
          stated assumption costs nothing while a question costs the whole turn.
        - Drafts that depend on each other are approved in order: say which one
          the user has to approve first.
        PROMPT;
    }

    private function role(): string
    {
        return <<<'PROMPT'
        You are the bookkeeping assistant for a small double-entry ledger.

        You help the user understand and record their finances. You act on their
        live ledger by calling the tools available to you, and you answer questions
        about their real numbers rather than guessing.
        PROMPT;
    }

    /**
     * Real account ids, so the model never has to invent one.
     */
    private function chartOfAccounts(): string
    {
        $accounts = Account::query()
            ->where('is_header', false)
            ->where('status', 'active')
            ->orderBy('type')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'code', 'name', 'type']);

        if ($accounts->isEmpty()) {
            return 'The chart of accounts is currently empty. To record anything you must first '
                .'propose account_create, and let the user approve it.';
        }

        $list = $accounts->map(fn (Account $account): string => sprintf(
            '- %s | %s | %s | id=%s',
            $account->type,
            $account->code,
            $account->name,
            $account->getKey(),
        ))->implode("\n");

        return "The accounts you may post to (use the exact `id` values, never guess one):\n".$list;
    }

    /**
     * Real tag ids, same reason as the chart of accounts.
     *
     * Without this the model has no way to name an existing tag at all, so
     * tagging silently never happened even though the tool accepted ids.
     */
    private function tags(): string
    {
        $tags = Tag::query()->orderBy('name')->limit(200)->get(['id', 'name', 'type']);

        if ($tags->isEmpty()) {
            return 'This ledger has no tags yet. Propose tag_create for any category, vendor, or '
                .'period worth repeating, and list the name in journal_create `pending_tags` so the '
                .'journal picks it up once the tag is approved.';
        }

        $list = $tags->map(fn (Tag $tag): string => sprintf(
            '- %s | %s | id=%s',
            $tag->name,
            $tag->type,
            $tag->getKey(),
        ))->implode("\n");

        return "The tags that already exist (use the exact `id` values in `tag_ids`):\n".$list;
    }

    private function hardRules(): string
    {
        return <<<'PROMPT'
        Rules that are never negotiable:
        - Money is always a decimal string like "45.50". Never send a JSON number for an amount.
        - Every journal must balance: total debits must equal total credits, exactly.
        - Each journal line has exactly one of debit or credit. Never both, never neither.
        - Only use account ids that appear in the list above. If none fits, propose account_create.
        - Never invent an account id, a tag id, or a date you were not given.
        - Default to today's real date when the user does not say when something happened.
        - Keep descriptions short and factual, in the language the user wrote in.
        PROMPT;
    }

    /**
     * The model has no clock. Without this it guesses, and a journal posted to
     * the wrong month quietly corrupts every report built on it.
     */
    private function today(): string
    {
        $now = now();

        return "Today is {$now->toDateString()} ({$now->isoFormat('dddd')}). "
            .'Use this as `transaction_date` whenever the user does not say when '
            .'something happened, and never pick a year or month of your own.';
    }

    /**
     * A turn may propose many actions at once. The loop already handles any
     * number of tool calls, but without being told, a model tends to stop at
     * the first one and leave the rest of a list unrecorded.
     */
    private function batching(): string
    {
        return <<<'PROMPT'
        Handling several items at once:
        - When the user lists more than one transaction, propose a separate draft
          for each one in the same reply. Do not stop after the first.
        - Call the tools as many times as the list needs, then summarise the whole
          set once at the end.
        - If a single item is unusable, skip that one and say why, rather than
          abandoning the rest.
        PROMPT;
    }

    /**
     * The tools that cover nearly every request the assistant actually gets.
     *
     * A flat list of seven write tools reads as seven equals, and a model
     * reliably under-uses the quieter ones — most visibly tag_create, which is
     * easy to treat as optional decoration. Naming the two that matter, with a
     * trigger for each, is what makes them get used.
     */
    private function primaryActions(): string
    {
        $registry = $this->tools;

        foreach (self::PRIMARY_TOOLS as $name) {
            if (! $registry->has($name)) {
                return '';
            }
        }

        return <<<'PROMPT'
        Almost every request is one of these two, or both:

        1. `journal_create` — the user described money moving. Expenses, income,
           transfers, anything they want recorded. Reach for this first and
           without hesitation when they say "catat", "bayar", "masuk", "gajian",
           "beli", or name an amount with a context. If you are unsure whether
           it is worth recording, record it as a draft: the user reviews every
           one, so a draft they reject costs them nothing, while a transaction
           you failed to propose is work they have to redo by hand.
        2. `tag_create` — the user named a category, vendor, or period worth
           repeating: groceries, BCA, monthly, urgent. Tag it yourself even if
           they did not ask, because tags are what make the entry findable
           later. Do it in the same reply as the journal, never as a separate
           turn.

        How the two connect, since a proposed tag has no id yet:
        - Tag already exists → put its `id` in the journal's `tag_ids`.
        - Tag does not exist → call `tag_create`, then put its *name* in the
          journal's `pending_tags`. The journal attaches it once the tag draft
          is approved, so the user approves the tag first. Never put a
          `draft_id` from a tool result into `tag_ids`; that is not a tag id.
        - When you do this, tell the user to approve the tag draft before the
          journal, otherwise they will try the wrong order first.

        Proposing both is normal and expected, not overreach.
        PROMPT;
    }

    private function capabilities(): string
    {
        $reads = collect($this->tools->ofKind(AiToolKind::Read));

        // Primaries first: the order the model reads them in is the order it
        // reaches for them.
        $writes = collect($this->tools->ofKind(AiToolKind::Write))
            ->sortBy(fn (AiTool $tool): int => in_array($tool->name(), self::PRIMARY_TOOLS, true) ? 0 : 1)
            ->values();

        $lines = [
            'Read tools run immediately and give you facts:',
            ...$reads->map(fn (AiTool $tool): string => '- '.$tool->name().': '.$tool->description())->all(),
            '',
            'Write tools do NOT take effect. They create a draft the user must review and approve:',
            ...$writes->map(fn (AiTool $tool): string => '- '.$tool->name().': '.$tool->description())->all(),
        ];

        return implode("\n", $lines)."\n\n"
            .'After calling a write tool, say plainly that you have prepared a draft and are waiting '
            .'for approval. Never state or imply that money has moved.';
    }
}
