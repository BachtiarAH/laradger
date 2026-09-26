<?php

namespace App\Services\Ai\Tools\Tools;

use App\Services\Ai\Omni\TurnOutcome;
use App\Services\Ai\Tools\AbstractAiTool;
use App\Services\Ai\Tools\AiToolKind;
use Illuminate\Validation\ValidationException;

/**
 * The model declaring that it deliberately proposed nothing.
 *
 * This tool writes nothing. It exists so that "I decided not to create this
 * because it is already recorded" is a report the UI can render, instead of a
 * turn that returned zero drafts and looked identical to a turn that fell over.
 *
 * It is an `Outcome`, not a `Write`, so it can never become a draft: there is
 * nothing to approve about a decision not to act, and `DraftExecutor` refuses
 * anything that is not a `Write`.
 */
class RecordNoActionTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'record_no_action';
    }

    public function description(): string
    {
        return 'Declare that you are deliberately proposing no drafts this turn, and why. '
            .'Call this at the end of a turn whenever you decide not to act, so the user '
            .'is told the difference between "nothing needed doing" and "the request failed". '
            .'Use outcome="already_recorded" when you searched the ledger, found that same '
            .'transaction already exists, and chose not to create a second entry — always pass '
            .'that entry\'s reference so the user can find it. Use '
            .'outcome="nothing_to_record" when the request simply contains nothing bookable. '
            .'Use outcome="needs_attention" when you could not proceed and the user must '
            .'decide. Never call this instead of proposing drafts you should have created.';
    }

    public function kind(): AiToolKind
    {
        return AiToolKind::Outcome;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'outcome' => [
                    'type' => 'string',
                    'enum' => array_column(TurnOutcome::cases(), 'value'),
                    'description' => 'Why no drafts were proposed.',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'One plain sentence, shown to the user as the outcome. '
                        .'Name the amount and date when they are the reason.',
                ],
                'reference' => [
                    'type' => 'string',
                    'description' => 'Required for already_recorded: the reference of the entry '
                        .'that already exists, e.g. JRN-2026-0002.',
                ],
            ],
            'required' => ['outcome', 'reason'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function title(array $arguments): string
    {
        return 'No drafts proposed';
    }

    /**
     * Validates and echoes the declaration. Deliberately side-effect free.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $outcome = TurnOutcome::tryFrom($this->stringArgument($arguments, 'outcome'));
        $reason = $this->stringArgument($arguments, 'reason');
        $reference = $this->stringArgument($arguments, 'reference');

        if ($outcome === null) {
            throw ValidationException::withMessages([
                'outcome' => 'Choose one of: '.implode(', ', array_column(TurnOutcome::cases(), 'value')).'.',
            ]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Say in one sentence why no draft was proposed.',
            ]);
        }

        // Without the reference the user is told a duplicate was avoided and has
        // no way to find the entry that already exists, which is the whole point.
        if ($outcome === TurnOutcome::AlreadyRecorded && $reference === '') {
            throw ValidationException::withMessages([
                'reference' => 'Give the reference of the entry that already exists, '
                    .'e.g. JRN-2026-0002.',
            ]);
        }

        return [
            'status' => 'no_action',
            'outcome' => $outcome->value,
            'reason' => $reason,
            'reference' => $reference === '' ? null : $reference,
        ];
    }
}
