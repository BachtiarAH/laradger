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
  and error mapping). Register new drivers in `JournalDraftService::PROVIDERS`
  and add a matching `createXxxDriver()` method.
- Providers never persist data — they only return a `JournalDraft` value object
  for review. Confirmation is done via the normal journal endpoints.
- Responses are validated before use; missing/duplicated debit/credit lines or
  non-numeric amounts throw `AiProviderException::invalidResponse`.
- Use `draftWithFallback()` when a fallback provider is configured
  (`isConfigured()`), otherwise the configured provider error is rethrown.
- The controller maps provider failures to a `502` with an `errors.statement`
  message; never leak raw provider responses.