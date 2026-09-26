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
  - 'tests/Feature/Api/AiAssistantTest.php'
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

## The `ai` limit is for spending, not for reading

`throttle:ai` is 30/min per user and it exists for exactly one reason: every
assistant turn costs that user money. `GET /ai/draft-requests` and
`GET /ai/drafts` call no model and cost nothing, so they sit on a separate
`ai-status` limiter (120/min) instead. Do not move them back.

They were on the spending limit, and the drafting page polls them while a prompt
is in flight. It was spending **three** requests every 2.5 seconds — the status
list, plus the draft list twice over, because the pending count was fetched as a
separate call — which is 72/min against a 30/min ceiling. A queued prompt
therefore throttled the screen watching it, and then the user's next real message
was rejected. The symptom looked like the assistant failing, not like a rate
limit.

Two things follow, and the second is the one that actually mattered:

- The client polls the **status list only**. A turn cannot produce drafts until it
  finishes, so re-reading the draft list on every tick was two thirds of the
  traffic spent on a list that could not have changed; it reloads when a request
  actually settles. The interval also backs off (2.5s → 30s) and stops entirely
  on a hidden tab, because a prompt with no worker behind it waits forever.
- The split matters more than the tuning. A limiter is a statement about what is
  expensive. Polling a free read against the budget reserved for paid calls makes
  the two compete, and the user-visible result is that asking a question fails
  while a progress bar runs.

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

### A proposed action is in the drafter the moment it is proposed

`proposeWrite()` writes the `ai_action_drafts` row inside the turn, so a built
action is **already** in the drafter by the time the reply reaches the user. There
is no second step, no queue, and no "save draft" call to add later — the assistant
and the queued path write to the same table, which is why `/ai/drafts` is the one
screen that lists everything pending.

What the assistant owes the user is only the *telling*: `AssistantDrawer` says how
many actions were added and links to `/ai/drafts`, and calls the shared
`refreshPendingBadge()`. That badge is otherwise only corrected by its own 60s
timer, and a stale count next to a just-built action is indistinguishable from
"nothing happened" — the one thing the turn must not communicate.

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

## A template with no lines is never acceptable

`JournalTemplateSeeder` resolves accounts by **code** (`JAGO`, `GAJI`, `GOPAY`,
`UTIL`, `BRI`). Ledgers built from the standard chart use hierarchical codes
(`1`, `1-1`, `1-1-1-1`), so none of them match. The seeder used to `continue`
past each missing code, which produced four templates with **zero** lines and no
warning: the line table rendered empty, Generate produced nothing, and the
scheduled run booked a journal with a description and no lines at all.

Two guards, and both are needed:

- The seeder resolves every account before writing anything and skips the whole
  template if a code is missing. A partially seeded template looks ready to use
  and is not.
- `JournalTemplateService::generate()` throws a `ValidationException` when a
  template has no lines. `processDue()` already catches that and skips, so a
  bad template cannot stop the batch and keeps its schedule.

`journal_template_lines` has no minimum at the database level — the invariant is
enforced in the request (`StoreJournalTemplateRequest`, `lines` `min:1`) and now
in the service. Do not write a path that creates a template without going
through one of them.

## A write tool cannot reference another write tool's id

A write tool does not execute, so when the model calls `tag_create` or
`account_create` the tool result hands back a `draft_id` (an
`ai_action_drafts.id`) — **not** a `tags.id` or `accounts.id`. Those rows do not
exist yet, and `StoreJournalRequest` requires real ids via
`Rule::exists`. So "call account_create, then use the account you just proposed"
is unimplementable as written, and a ledger with a new category could never
record a transaction at all.

The model gets ids from two places only:

- `SystemPromptBuilder` lists live accounts and live tags with their real ids.
  Anything the model must reference needs to be in one of those lists.
- A reference to something proposed in the *same* turn is carried by **name**,
  not id, and resolved at approval time: `pending:<name>` in
  `lines[].account_id`, and `pending_tags` (names) on `journal_create`.

`JournalCreateTool::payload()` stores the reference **unresolved** so the review
card shows what was proposed and reviewed still equals sent; `execute()` resolves
it. When adding a write tool that produces something another write tool must point
at, follow this pattern rather than inventing a placeholder id.

### The pairing is also a real row, and the executor walks it

The name in the payload is what the user reads; it is not something an executor can
order. `pendingReferences()` on the tool declares the outstanding names, and
`DraftDependencyResolver` pairs them with sibling drafts into
`ai_action_draft_dependencies` (composite key, no surrogate id). Wired at the
**end** of the turn, not per draft — the model may propose the journal before the
tag, and a per-draft implementation misses exactly that. A name that already
exists in the ledger is not a dependency at all; recording one would demand
approving a draft for something already there.

Approving a draft runs its chain, dependencies first, in one transaction. This
replaced "one draft never executes another, so approval order is the user's job",
and it is worth remembering why that rule was wrong rather than just that it
changed. It protected the right invariant — the model never causes a write — but
the ordering it demanded was invisible (a string in a payload, nothing on the
card) and getting it wrong was **terminal**: the failure marked the draft
`failed`, and `failed` was neither editable nor retryable, so the only recovery was
throwing the draft away and asking the assistant for the same thing again. Chaining
weakens nothing that matters — every step still runs through its own tool into the
real FormRequest and controller, and it all still starts from one explicit click.

Three rules the chain obeys, each of which was a bug once:

- **All or nothing.** One transaction. On failure only the step that failed is
  marked; the ones before it were rolled back, so they stay `pending` and a retry
  re-runs the whole chain rather than tripping over a half-applied one.
- **A rejected or failed prerequisite blocks the chain** and the error says to edit
  the reference out. Silently continuing without the tag would mean applying
  something other than what was reviewed. An `executed` prerequisite is skipped
  instead, so approving the tag by hand first still works.
- **`failed` is editable and retryable; `executed` and `rejected` are not.** The
  executor wraps each run in a transaction, so a failure left nothing behind and
  re-running is safe — while re-running an applied draft is the double-posting
  `isExecutable()` exists to prevent. `AiActionDraft::isEditable()` and
  `isExecutable()` encode this; do not collapse them back into `isPending()`.

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

## Replay the tail, in order, or the assistant answers the wrong question

A provider reads `messages` **positionally**. The last one is the live turn;
everything before it is context. So a replay that is merely *reordered* does not
degrade the answer, it **replaces the question** — the model continues from
whichever turn happens to land last. There was no test for this, so it shipped.

Three traps, all in `OmniAssistant::history()`, all live at once:

- **The relation already carries an order.** `AiConversation::messages()` ends
  in `orderBy('created_at')`, and Eloquent *appends* to it. `->orderByDesc('id')`
  on top of that compiles to `ORDER BY created_at ASC, id DESC`, so `->limit(40)`
  took the **oldest** 40 rows and the current prompt was never sent at all. Use
  `reorder()`, never `orderBy*`, when you mean to replace an inherited order.
- **`created_at` cannot order a turn.** It has second precision and a whole turn
  (user, assistant, one tool result per call) is written inside one second. The
  relation therefore also orders by `id`; ids are ordered UUIDs (`HasUuids`), so
  they sort in insertion order and are the only trustworthy sequence here.
- **`reverse()` preserves keys.** `Collection::search()` returns a *key*, so
  after `->reverse()` a key-based `slice()` counts from the wrong end and cuts
  the start of the conversation. `->values()` after `->reverse()`.

The window is a tail, so it can start mid-group and orphan a `tool` turn whose
assistant parent was cut. Those are dropped rather than sent, because a provider
rejects a tool result with no preceding `tool_calls` as malformed and the whole
turn is lost. `AiAssistantTest`'s `replaying the conversation` group pins all of
it — including that the current prompt is the **last** message sent.

A transcript the user reads back and a transcript the model reads back come from
different queries, so they can disagree invisibly. Assert on what was *sent*
(`Http::assertSent`), not on what was stored.

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

## `max:4000` is shared with a client-side budget, do not raise one side

`SendAiMessageRequest` and `StoreAiDraftRequest` both cap a prompt at 4000
characters. The assistant drawer and the AI Drafts page compose their prompts in
`laradger-web/src/lib/receiptPrompt.ts`, which holds the same number and spends it
on receipt OCR text. The two are not independent: raise one and the client starts
sending prompts the server rejects, and the failure only appears as a 422 *after*
the user has already waited through an OCR run.

Receipts are read in the browser and never uploaded, so a prompt is plain text by
the time it arrives here — there is no attachment column, no storage, and no
multipart body anywhere in the AI API. Do not add one to "support" receipts; the
attachment is the OCR text, and it is in the prompt already.

The budget has two rules worth keeping: the user's own words are never truncated
in preference to a receipt, and a receipt that cannot fit is either cut with a
visible marker or announced as dropped — never silently omitted, because the
number that gets silently dropped is a total.

## A turn that proposes nothing must say why

`drafts_count === 0` meant two opposite things at once: the model correctly
declined to double-book a transaction, or the model gave up. Both rendered as an
empty result, and the UI told the user to go ask the assistant about a turn the
assistant had already answered in a collapsed panel. A correct decision was
indistinguishable from a failure, and the reference that would have made it
actionable went nowhere.

`AiToolKind` therefore has a third case, `Outcome`, and one tool,
`record_no_action`. It changes nothing and never becomes a draft — `DraftExecutor`
runs `Write` only, which is the whole reason `Outcome` is not `Write`. The model
declares `already_recorded` (with the existing entry's reference),
`nothing_to_record`, or `needs_attention`; the job stamps it onto
`ai_draft_request`, exactly as it stamps `ai_draft_request_id` onto drafts,
because the assistant is shared with the chat path and has no request to write to.
`outcome` is null whenever drafts were produced — that case needs no explanation.

Two rules the prompt was missing, both added because the model improvised around
their absence and was one bad roll away from improvising wrong:

- **Duplicates.** Nothing told the model to check before drafting, while
  `primaryActions` told it to reach for `journal_create` "without hesitation" and
  `unattended` told it never to stop and ask. So it either double-books or
  unilaterally decides, and which one you get is luck. `duplicates()` bounds the
  check to inputs that identify a transaction and requires the reference. Note
  that an entry created from an approved draft has status `draft`: a model told to
  search only `posted` misses it and duplicates something already approved.
- **Grounding.** The model reported a journal as carrying two tags when it
  carried one, inventing the second from the tag catalogue — which tells the user
  a category is handled when it is not. `journals_search` now returns the tags an
  entry actually has, and `grounding()` forbids stating ledger facts that no tool
  result contained.

`unattended()` says never to end on a question, so a deliberate refusal has to be
expressible as a decision. That is why `record_no_action` exists rather than a
prompt instruction to "mention it in your reply" — prose in a reply is not
something a screen can branch on.

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