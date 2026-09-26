<?php

namespace App\Services\Ai\Omni;

use App\Models\AiActionDraft;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\AiToolKind;
use App\Services\Ai\Tools\AiToolRegistry;
use App\Services\Ai\Tools\ToolCall;
use Illuminate\Support\Collection;

/**
 * The agent loop behind the assistant.
 *
 * The single invariant this class exists to enforce: **the model never causes a
 * write to happen.** It may only cause a write to be *proposed*. Read tools run
 * inline because the model cannot reason without facts; write tools are
 * persisted as pending drafts and stop there. Nothing here executes a write
 * tool — that only happens in DraftExecutor, from a separate user action.
 */
class OmniAssistant
{
    /**
     * Hard stop on the loop. Each iteration spends real money, so an unbounded
     * tool-calling loop is a billing hazard, not just a hang.
     */
    private const MAX_TURNS = 6;

    /**
     * Only the tail of the conversation is replayed. Older turns are superseded
     * context that still costs money on every request.
     */
    private const HISTORY_LIMIT = 40;

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiToolRegistry $tools,
        private readonly SystemPromptBuilder $prompts,
        private readonly DraftDependencyResolver $dependencies,
    ) {}

    /**
     * Record what the user said.
     *
     * Deliberately separate from respond(): this runs inside the HTTP request,
     * where the tenant context and the authenticated user are established, so
     * the message is visible immediately. The AI work is then queued and runs
     * later, without the user waiting for it.
     */
    public function recordUserMessage(AiConversation $conversation, string $text): AiMessage
    {
        return $conversation->messages()->create([
            'user_id' => auth()->id(),
            'role' => AiMessage::ROLE_USER,
            'content' => $text,
        ]);
    }

    /**
     * Produce the assistant's reply and any proposed actions.
     *
     * Used by both paths. The synchronous chat records the user's words itself
     * (inside the request, where the tenant context and user exist) and passes
     * no prompt. A queued drafting request has no request to record in — its
     * conversation is created inside the job — so it passes the prompt and the
     * message becomes the first step of the turn.
     *
     * @return array{reply: string, drafts: Collection<int, AiActionDraft>, reads: array<int, array<string, mixed>>, outcome: array{outcome: string, reason: string, reference: string|null}|null}
     */
    public function respond(
        AiConversation $conversation,
        ?string $prompt = null,
        string $mode = SystemPromptBuilder::MODE_CHAT,
    ): array {
        if (filled($prompt)) {
            $this->recordUserMessage($conversation, $prompt);
        }

        $messages = $this->history($conversation, $mode);
        $options = ['tools' => $this->tools->definitions()];

        $reads = [];
        $drafts = collect();
        $declared = null;

        for ($turn = 0; $turn < self::MAX_TURNS; $turn++) {
            $response = $this->gateway->converse($messages, $options);

            $assistant = $conversation->messages()->create([
                'user_id' => auth()->id(),
                'role' => AiMessage::ROLE_ASSISTANT,
                'content' => $response->content !== '' ? $response->content : null,
                'tool_calls' => $response->hasToolCalls()
                    ? array_map(static fn (ToolCall $call): array => $call->toArray(), $response->toolCalls)
                    : null,
            ]);

            if (! $response->hasToolCalls()) {
                // After the loop, not per draft: the model may propose the journal
                // before the tag it needs, so the pairing is only knowable once the
                // turn has produced everything it is going to produce.
                $this->dependencies->wire($drafts);

                return [
                    'reply' => $response->content,
                    'drafts' => $drafts,
                    'reads' => $reads,
                    'outcome' => $declared,
                ];
            }

            // Replayed so the next request stays well-formed: every tool_call in
            // the assistant turn must be answered by exactly one tool turn.
            $messages[] = [
                'role' => AiMessage::ROLE_ASSISTANT,
                'content' => $response->content,
                'tool_calls' => $response->toolCalls,
            ];

            foreach ($response->toolCalls as $call) {
                $outcome = $this->handle($call, $conversation, $assistant);

                $outcome['draft'] instanceof AiActionDraft
                    ? $drafts->push($outcome['draft'])
                    : null;

                if ($outcome['read'] !== null) {
                    $reads[] = $outcome['read'];
                }

                // Last declaration wins, so a model that corrects itself mid-turn
                // is not reported as two contradictory outcomes.
                if ($outcome['declared'] !== null) {
                    $declared = $outcome['declared'];
                }

                $conversation->messages()->create([
                    'user_id' => auth()->id(),
                    'role' => AiMessage::ROLE_TOOL,
                    'tool_call_id' => $call->id,
                    'content' => $outcome['content'],
                ]);

                $messages[] = [
                    'role' => AiMessage::ROLE_TOOL,
                    'tool_call_id' => $call->id,
                    'content' => $outcome['content'],
                ];
            }
        }

        // The loop budget is a safety valve, not an expected path. Say so
        // plainly and keep whatever was already proposed.
        $note = 'I reached my step limit for this request. Review the drafts above, or rephrase to ask for something more specific.';

        $conversation->messages()->create([
            'user_id' => auth()->id(),
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $note,
        ]);

        $this->dependencies->wire($drafts);

        return ['reply' => $note, 'drafts' => $drafts, 'reads' => $reads, 'outcome' => $declared];
    }

    /**
     * Route one tool call: read it now, persist it as a draft, or take its word
     * for what the turn concluded.
     *
     * @return array{draft: AiActionDraft|null, read: array<string, mixed>|null, content: string, declared: array{outcome: string, reason: string, reference: string|null}|null}
     */
    private function handle(ToolCall $call, AiConversation $conversation, AiMessage $message): array
    {
        $tool = $this->tools->get($call->name);

        return match ($tool->kind()) {
            AiToolKind::Read => $this->runRead($tool, $call),
            AiToolKind::Write => $this->proposeWrite($tool, $call, $conversation, $message),
            // Changes nothing, so it runs inline — but it is not a read: it is a
            // declaration, and it is the only path that can set the turn's outcome.
            AiToolKind::Outcome => $this->declareNoAction($tool, $call),
        };
    }

    /**
     * @return array{draft: null, read: null, content: string, declared: array{outcome: string, reason: string, reference: string|null}}
     */
    private function declareNoAction(AiTool $tool, ToolCall $call): array
    {
        $result = $tool->execute($call->arguments);

        return [
            'draft' => null,
            'read' => null,
            'content' => $this->encode($result),
            'declared' => [
                'outcome' => (string) $result['outcome'],
                'reason' => (string) $result['reason'],
                'reference' => $result['reference'] ?? null,
            ],
        ];
    }

    /**
     * @return array{draft: null, read: array<string, mixed>, content: string, declared: null}
     */
    private function runRead(AiTool $tool, ToolCall $call): array
    {
        $result = $tool->execute($call->arguments);

        return [
            'draft' => null,
            'read' => ['tool' => $tool->name(), 'result' => $result],
            // A tool_result is the model's only view of what happened.
            'content' => $this->encode($result),
            'declared' => null,
        ];
    }

    /**
     * @return array{draft: AiActionDraft, read: null, content: string, declared: null}
     */
    private function proposeWrite(
        AiTool $tool,
        ToolCall $call,
        AiConversation $conversation,
        AiMessage $message,
    ): array {
        // Tools exposing payload() hand back the exact final shape, so the
        // payload the user reviews is the payload that later gets sent.
        $payload = method_exists($tool, 'payload')
            ? $tool->payload($call->arguments)
            : $call->arguments;

        $draft = $conversation->drafts()->create([
            'ai_message_id' => $message->getKey(),
            'user_id' => auth()->id(),
            'tool' => $tool->name(),
            'kind' => $tool->kind()->value,
            'title' => $tool->title($call->arguments),
            'payload' => $payload,
            'status' => AiActionDraft::STATUS_PENDING,
        ]);

        return [
            'draft' => $draft,
            'read' => null,
            'declared' => null,
            // State plainly that it is *proposed*, so the model neither reports
            // success nor plans follow-ups as if the money already moved.
            'content' => $this->encode([
                'status' => 'proposed',
                'draft_id' => $draft->getKey(),
                'summary' => $draft->title,
                'note' => 'Saved as a draft for the user to review. It has NOT been applied. Do not report it as done.',
            ]),
        ];
    }

    /**
     * Rebuild the provider-agnostic message list from stored history.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(AiConversation $conversation, string $mode): array
    {
        $messages = [$this->prompts->system($mode)];

        // reorder(), not orderByDesc(): the relation already carries
        // `orderBy('created_at')`, and Eloquent *appends* to it, so the query
        // became `ORDER BY created_at ASC, id DESC` — the oldest 40 rows, and
        // within a turn (written in the same second) newest-first. The
        // ->reverse() then flipped the whole collection. A provider reads
        // messages positionally, so that handed the model the conversation
        // backwards: the turn the user just sent landed first and the very first
        // prompt landed last, right where the model continues from. Past the
        // limit it was worse — the current prompt was cut from the request
        // entirely, leaving only the opening turns.
        //
        // Ids are ordered UUIDs (HasUuids), so they sort in insertion order and
        // the window is the newest 40 rows, replayed oldest-first.
        //
        // ->values() is load-bearing: ->reverse() keeps the original keys, so
        // without it the key-based lookup below counts from the wrong end and
        // slices off the start of the conversation instead of the orphans.
        $stored = $conversation->messages()
            ->reorder('id', 'desc')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        // The window can start in the middle of a tool group, leaving tool turns
        // whose assistant parent was cut. A provider rejects a tool result with
        // no preceding tool_calls as malformed, so the orphans are dropped rather
        // than sent. Replaying the parent turn instead would be better, but the
        // assistant turn that introduced these calls is far older than the window.
        $firstUsable = $stored->search(
            static fn (AiMessage $message): bool => $message->role !== AiMessage::ROLE_TOOL,
        );

        if ($firstUsable !== false) {
            $stored = $stored->slice($firstUsable);
        }

        foreach ($stored as $message) {
            $entry = [
                'role' => $message->role,
                'content' => (string) ($message->content ?? ''),
            ];

            if ($message->role === AiMessage::ROLE_TOOL) {
                $entry['tool_call_id'] = $message->tool_call_id;

                $messages[] = $entry;

                continue;
            }

            if (filled($message->tool_calls)) {
                $entry['tool_calls'] = array_map(
                    static fn (array $call): ToolCall => ToolCall::fromArray($call),
                    $message->tool_calls,
                );
            }

            $messages[] = $entry;
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
