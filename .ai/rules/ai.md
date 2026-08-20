---
paths:
  - 'app/Services/Ai/**'
---

# AI Service Architecture

The AI feature is layered so tasks and providers are independently composable:

```text
AI Tasks → AI Gateway → Provider Adapters → AI APIs
```

Adding a new AI task never requires a new provider implementation, and adding a
new provider never requires touching existing tasks.

## Layers

- **Tasks** (`Tasks/`) own task-specific behavior: prompt construction, response
  parsing, and value objects. `JournalDraftTask` implements the `AiTask`
  contract (`statement`, `prompt`, `messages`, `options`, `interpret`) and is
  resolved via the container. It calls `AiGateway::run($this, $context)`.
- **Gateway** (`Gateway/AiGateway.php`, a Laravel Manager) owns generic AI
  orchestration: provider selection, fallback chaining across configured
  providers, call recording (`AiCallRecord` via `AiCallRecorder`), logging, and
  latency tracking. Providers register in `AiGateway::PROVIDERS` with a matching
  `createXxxDriver()` method.
- **Provider Adapters** (`Providers/`) own only provider transport. Each
  implements `Providers\Contracts\AiProvider` (`name`, `isConfigured`, `chat`)
  and extends `AbstractAiProvider` (HTTP call, error mapping, logging). They are
  task-agnostic: no prompt or parsing logic lives here.

## Behavior to preserve

- Tasks never persist data — they return a value object for review; confirmation
  is done via the normal journal endpoints (`AiCallRecorder::confirm`).
- Responses are validated by the task before use; invalid drafts throw
  `AiProviderException::invalidResponse`.
- The gateway runs the default provider first, then falls back through the other
  configured providers; each attempt is recorded and logged independently.
  If none succeed, the last `AiProviderException` is rethrown.
- The controller maps provider failures to a `502` with an `errors.statement`
  message; never leak raw provider responses.
- `interpret(string $content, string $recordId)` parses the provider content and
  attaches the record id to the returned value object.

## Prompt customization

The system prompt is configurable via `config('ai.prompt')` / `AI_PROMPT`. When
set, placeholders `:accounts` and `:statement` are replaced at call time by
`JournalDraftTask`. When empty, the built-in default prompt is used.

## Call recording (prompt + response + usage + confirmation)

Every AI call is recorded through `Contracts\AiCallRecorder`, resolved via
`AiCallRecordingService` (a Laravel Manager, bound in `AppServiceProvider`).
The default `file` driver appends one JSON line per event to
`logs/ai-calls.jsonl` (config: `ai.recording.*`).

- Events: `type: ai_call` (statement, prompt, draft, raw_response, usage,
  latency_ms, success/error) and `type: confirmation` (references the original
  `id` via `recordId`, plus `journal_id`).
- The draft response exposes `data.record_id`; when the user confirms via
  `POST /journals`, send `ai_record_id` back so `JournalController@store`
  records the confirmation.
- `ai.recording.enabled=false` skips all writes. Swap backends by adding a
  driver to `AiCallRecordingService` (e.g. database, Langfuse) — no provider
  changes needed.
- Recording is synchronous; it is not queued.