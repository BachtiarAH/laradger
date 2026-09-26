---
paths:
  - 'app/Services/Ai/**'
  - 'app/Http/Controllers/Api/AiSettingsController.php'
  - 'app/Http/Controllers/Api/AiJournalDraftController.php'
  - 'app/Http/Controllers/Api/AiAssistantController.php'
  - 'app/Models/User.php'
  - 'app/Models/AiConversation.php'
  - 'app/Models/AiMessage.php'
  - 'app/Models/AiActionDraft.php'
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

## Provider configuration resolves user key > environment

`isConfigured()` is an **instance** method, not static. It reads the resolved
provider config rather than the config repository — that is what makes a
per-user key expressible at all. Do not make it static again.

Resolution order lives in `Gateway\AiProviderConfigResolver`:

1. `config("ai.providers.{$name}")` from the environment.
2. If the authenticated user selected **that same** provider, their stored
   `users.ai_api_key` / `users.ai_model` override it.

A user's key applies only to the provider they picked, so every other provider
keeps its environment config and gateway fallback still works. When a user has
selected a provider, that provider also becomes their default driver
(`AiGateway::getDefaultDriver`).

Never expose the key. `/me/ai` returns `has_key` plus a `key_hint` holding the
last four characters of the user's **own** key; a key that came from the
environment is reported as present but never partially disclosed.
`users.ai_api_key` uses the `encrypted` cast (APP_KEY) and is in `#[Hidden]`.

A blank `api_key` on `PUT /me/ai` is a **no-op, not a clear**, so saving only a
new model can never wipe a key the user cannot see. Clearing is `DELETE /me/ai`.
The same no-op rule applies to `base_uri` and `endpoint`. There is deliberately
**no minimum length** on `api_key`: Ollama and LM Studio accept any non-empty
string, and a real provider rejects a bad key itself.

## openai_compatible is the escape hatch — and the URL trap

`openai_compatible` is how a user reaches Ollama, vLLM, LM Studio, OpenRouter,
Groq, or Gemini's compat path. `base_uri` and `endpoint` are stored on the user
(`users.ai_base_uri`, `users.ai_endpoint`, plain columns — neither is a secret)
and, like the key, apply **only** to the provider the user selected.

`base_uri` and `endpoint` are concatenated **textually with no normalization**:
Laravel does `rtrim(base_uri,'/') . '/' . ltrim(endpoint,'/')`. So these are
correct and these are wrong:

```
http://localhost:11434            + /v1/chat/completions   ✅
http://localhost:11434/v1         + /chat/completions      ✅
http://localhost:11434/v1         + /v1/chat/completions  ❌ doubles /v1
```

The `ltrim` also means a protocol-relative endpoint (`//evil.example.com/x`)
cannot escape to another host — it stays a path. Validation still requires
`base_uri` to start with `http://` or `https://` and `endpoint` to start with
`/` and carry no scheme, because the server fetches these URLs.

**Security note:** a user-supplied `base_uri` means the server issues requests
to an arbitrary http(s) host, i.e. an SSRF surface, and blocking loopback would
defeat the local-model use case. This is accepted deliberately — it is the same
trust level as the user supplying their own API key — but do not widen it to
other providers' endpoints without revisiting that decision.

## Malformed provider output is an invalid response, not a transport failure

`JournalDraftTask` decodes provider content through its own `decode()` helper.
Never let a raw `JsonException` escape: it is not an `AiProviderException`, so it
skips the gateway's provider-failure branch (losing the recorded raw response)
and degrades into a generic "request failed" 502. It also strips ```json fences
first, because small self-hosted models fence their JSON even when told not to.

`AiGateway` **must not** be bound as a singleton: `Manager` caches drivers by
name and drivers now hold per-user credentials. It is resolved per-request,
which is exactly what makes that cache safe.

`/me/ai` is deliberately **not** tenant-scoped — the key belongs to the user,
who may belong to several tenants. It is throttled by the `ai` limiter
(30/min per user), because every AI call spends that user's own money.

## The assistant: propose, never execute

`app/Services/Ai/Omni/` adds an agent loop **above** the gateway. The single
invariant it exists to enforce: **the model never causes a write to happen.**

```
message → OmniAssistant → gateway → model requests tools
                                 ├─ read  tool → runs inline, result fed back
                                 └─ write tool → persisted as `pending` draft, STOPS
user reviews → DraftExecutor → real FormRequest → real controller
```

- `OmniAssistant` has no code path that runs a write tool. It calls
  `payload()` (to normalize what will be sent), never `execute()`.
- `DraftExecutor` is the **only** place a write tool runs, reachable only from
  `POST /ai/drafts/{draft}/execute`. It refuses anything not `pending`, so a
  draft cannot be replayed, and wraps the call in the tool's own transaction.
- A draft's `payload` is stored in its **final** form (tools expose `payload()`
  separately from `execute()`), so what the user reviews is byte-for-byte what
  gets sent. `tool`, `kind`, and `title` are immutable for the same reason.
- The `tool_result` returned to the model says `status: proposed` and
  "NOT been applied" — otherwise the model reports the money as already moved.

## Tool names are underscored, not dotted

Both OpenAI and Anthropic restrict function names to `^[a-zA-Z0-9_-]+$`, so
`journal.create` is **rejected by the API**. Tools are named `journal_create`.
`AiToolRegistry` asserts uniqueness; there is a test asserting every name
matches the pattern.

## The registry is the security boundary

`AiToolRegistry::TOOLS` is an explicit list, never auto-discovered. A tool that
is not listed cannot be invoked regardless of what the model emits — an unknown
name currently throws and fails the turn, which is the intended behaviour.

Tools are split by `AiToolKind`:

- `Read` — runs inline. The model cannot reason about the ledger without facts.
- `Write` — always becomes a draft. Never gate this on a preference.

Write tools must go through `Support\FormRequestInvoker`, which rebuilds the real
FormRequest (`setContainer` / `setRedirector` / `setUserResolver` /
`setRouteResolver`, then `validateResolved()`) and calls the real controller.
That is what makes "the assistant can do nothing the user could not" true rather
than aspirational. Two traps it papers over:

- `Route::setParameter()` throws `LogicException: Route is not bound` until
  `Route::bind($request)` has run. Form requests that read `$this->route('journal')`
  in `authorize()` or `rules()` silently get nothing otherwise.
- The authenticated user is resolved from the guard and **pinned** onto both
  requests, so a payload or route parameter can never act as someone else.

Cross-tenant payloads are stopped by the tenant-scoped `Rule::exists` rules on
ids, not only by policies — several policies allow any authenticated user.

## Chat is synchronous; drafting is queued

Two deliberately different paths. Do not merge them.

| | Assistant chat | Drafting |
|---|---|---|
| Endpoint | `POST /ai/conversations/{c}/messages` | `POST /ai/draft-requests` |
| Response | `200` with `reply` + `drafts` | `202`, work in the background |
| Why | the user is waiting for an answer | a batch of transactions is worth queueing |

Both share `OmniAssistant::respond()`. The chat records the user's words inside
the request (where the tenant context and user exist) and passes no prompt. A
queued request has no request to record in - it creates its own conversation
inside the job - so it passes the prompt, which becomes the first message of the
turn.

`AiDraftRequest` is therefore a *submitted prompt* with its own status
(`queued` / `running` / `completed` / `failed`) and `drafts_count`, which is what
the user watches. `ai_action_drafts.ai_draft_request_id` links each draft back
to the prompt that produced it; the job stamps it after the turn, because the
assistant is shared with the chat path and has no request to know about.

**A job has no tenant context and no authenticated user.** `RunAiDraftRequest`
rebuilds both (`TenantContext::set()`, `Auth::setUser()`) and clears the context
in a `finally`. Without it every query fails closed, since `BelongsToTenant` is
fail-closed with no context. It loads the request with `withoutGlobalScopes()`
for the same reason.

`tries = 1` on purpose: the gateway already falls back across providers, so a
retry is another round of paid calls. The job re-throws after recording a safe
message, so the failure lands in `failed_jobs` for ops while the request shows
the user something actionable.

**Queued drafting requires a worker.** `composer run dev` runs one; in production
`queue:work`. With none running a request stays `queued` forever, so the UI has
to say so rather than spinning. A dispatch failure is marked `failed` in the
controller so it cannot hang as "merely slow".

A chat turn and a queued request can run at the same time; that is fine, since
they own separate conversations.

## Assistant data model

`ai_conversations` has `tenant_id` + `BelongsToTenant`. `ai_messages` and
`ai_action_drafts` deliberately have **no** `tenant_id` and scope via
`whereHas('conversation')`, per the `models.md` child-model rule.

`ai_messages.tool_call_id` is load-bearing: an assistant turn carrying
`tool_calls` must be replayed with exactly one `tool` turn per call or the
provider rejects the request as malformed. `OmniAssistant::history()` therefore
replays stored `tool` turns; do not "clean them up".

Conversations and drafts are **personal**, not tenant-wide: another member of the
same tenant gets `404`, never `403`, so existence is not disclosed. The
ownership check for `PATCH /ai/drafts/{draft}` lives in the FormRequest's
`authorize()` so it runs *before* validation — in the controller a `422` would
confirm the draft exists.

Anthropic specifics: no `tool` role (a tool result is a user turn holding
`tool_result` blocks), no function wrapper (`input_schema`, not `parameters`),
`system` is hoisted out of `messages`, and consecutive same-role turns are
rejected so user turns get merged. A missing `type` on a content block is
tolerated, because proxies fronting an Anthropic-compatible model omit it.

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