---
paths:
  - 'app/Services/Ai/**'
---

# AI Journal Draft Service

The AI draft feature is provider-agnostic. Providers are HTTP-backed drivers
resolved through `JournalDraftService` (a Laravel Manager) using the configured
driver name in `config/ai.php`. Swap providers by changing `AI_DEFAULT_PROVIDER`
— no code changes required.

- Every provider implements `Contracts\JournalDraftProvider` and extends
  `AbstractJournalDraftProvider` (which handles the HTTP call, shared prompt,
  error mapping, and call recording). Register new drivers in
  `JournalDraftService::PROVIDERS` and add a matching `createXxxDriver()` method.
- Providers never persist data — they only return a `JournalDraft` value object
  for review. Confirmation is done via the normal journal endpoints.
- Responses are validated before use; missing/duplicated debit/credit lines or
  non-numeric amounts throw `AiProviderException::invalidResponse`.
- Use `draftWithFallback()` when a fallback provider is configured
  (`isConfigured()`), otherwise the configured provider error is rethrown.
- The controller maps provider failures to a `502` with an `errors.statement`
  message; never leak raw provider responses.

## Prompt customization

The system prompt is configurable via `config('ai.prompt')` / `AI_PROMPT`. When
set, placeholders `:accounts` and `:statement` are replaced at call time. When
empty, the built-in default prompt (in `AbstractJournalDraftProvider`) is used.

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