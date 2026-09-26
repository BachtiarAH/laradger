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
    ) {}

    /**
     * @return array{reply: string, drafts: Collection<int, AiActionDraft>, reads: array<int, array<string, mixed>>}
     */
    public function reply(AiConversation $conversation, string $userMessage): array
    {
        $conversation->messages()->create([
            'user_id' => auth()->id(),
            'role' => AiMessage::ROLE_USER,
            'content' => $userMessage,
        ]);

        $messages = $this->history($conversation);
        $options = ['tools' => $this->tools->definitions()];

        $reads = [];
        $drafts = collect();

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
                return [
                    'reply' => $response->content,
                    'drafts' => $drafts,
                    'reads' => $reads,
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

        return ['reply' => $note, 'drafts' => $drafts, 'reads' => $reads];
    }

    /**
     * Route one tool call: read it now, or persist it as a draft.
     *
     * @return array{draft: AiActionDraft|null, read: array<string, mixed>|null, content: string}
     */
    private function handle(ToolCall $call, AiConversation $conversation, AiMessage $message): array
    {
        $tool = $this->tools->get($call->name);

        return $tool->kind() === AiToolKind::Read
            ? $this->runRead($tool, $call)
            : $this->proposeWrite($tool, $call, $conversation, $message);
    }

    /**
     * @return array{draft: null, read: array<string, mixed>, content: string}
     */
    private function runRead(AiTool $tool, ToolCall $call): array
    {
        $result = $tool->execute($call->arguments);

        return [
            'draft' => null,
            'read' => ['tool' => $tool->name(), 'result' => $result],
            // A tool_result is the model's only view of what happened.
            'content' => $this->encode($result),
        ];
    }

    /**
     * @return array{draft: AiActionDraft, read: null, content: string}
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
    private function history(AiConversation $conversation): array
    {
        $messages = [$this->prompts->system()];

        $stored = $conversation->messages()
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse();

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
