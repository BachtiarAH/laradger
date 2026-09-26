<?php

namespace App\Services\Ai\Omni;

use App\Models\Account;
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
    public function __construct(
        private readonly AiToolRegistry $tools,
    ) {}

    /**
     * @return array{role: string, content: string}
     */
    public function system(): array
    {
        return [
            'role' => 'system',
            'content' => implode("\n\n", array_filter([
                $this->role(),
                $this->chartOfAccounts(),
                $this->hardRules(),
                $this->capabilities(),
            ])),
        ];
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

    private function capabilities(): string
    {
        $reads = collect($this->tools->ofKind(AiToolKind::Read));
        $writes = collect($this->tools->ofKind(AiToolKind::Write));

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
