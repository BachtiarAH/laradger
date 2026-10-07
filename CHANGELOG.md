# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Scoped, expiring API keys for unattended clients. `php artisan api:issue-key` mints a Sanctum token carrying a fixed set of abilities and always an expiry (`API_KEY_DEFAULT_DAYS`, capped by `API_KEY_MAX_DAYS`). A key can never be granted more abilities after issue, and requesting an unrecognised ability fails loudly rather than being silently dropped.
- `ApiAbility` / `ApiArea` enums. An ability is `area:tier`; tiers climb within an area and nothing is implied across areas. The areas are `ledger` (journals, accounts, lines, expenses, AI drafting), `planning` (allocations, goals, budgets), `library` (tags, journal templates), `audit` (the change log), and `platform` (staff accounts).
- `ApiKeyIssuer` service holding the issuance rules — at least one ability, an expiry, and collapsing redundant abilities per area — so the guarantees hold for any future caller and not just the console command.
- `php artisan api:revoke-key` lists an account's keys and revokes them by token id or `--all`. Keys are identified by their `apikey:` name prefix rather than by abilities, so "revoke everything" cannot reach the login token someone is using to drive the web app.
- `EnsureApiAbility` middleware, registered as the `ability` alias and applied after `auth:sanctum` on every authenticated route group. It resolves one required ability per route — area from the path, tier from the method — and returns `403` naming both the missing ability and the ones that would satisfy it.
- A per-user API key management page and API. `GET/POST /me/api-keys` and `DELETE /me/api-keys/{token}` issue, list, and revoke the caller's own keys, so the page and `api:issue-key` produce identical keys through the same issuer.
- `ApiKeyManager` service holding the per-user scoping rules, including the prefix filter that keeps the login token out of reach of "revoke everything".
- Frontend route `/settings/api-keys`, with abilities grouped by area and checkboxes that mark the tiers a wider choice already subsumes. It is scoped to the signed-in user's own keys; there is no endpoint for managing anyone else's.
- `docs/openapi.yaml` documents the areas, the ability matrix, how each endpoint's requirement is resolved, and the issuance/revocation commands and endpoints.

### Changed

- **Breaking for keys issued before this change.** A key holding `ledger:destructive` previously reached allocations, goals, budgets, tags, and templates implicitly, because those were simply more ledger endpoints. It now reaches only the ledger area and must be reissued with the abilities it still needs. `ledger:*` keys otherwise behave exactly as before, and completing an allocation is a `planning:write` rather than a `ledger:write`.
- `config/api-keys.php` replaces `admin_paths` with an `areas` map, checked in order, first match wins, falling back to `ledger` for unlisted paths.

### Security

- **A key cannot issue a key.** `POST /me/api-keys` returns `403` to any request authenticated with an issued key, whatever that key holds. Without this, a leaked `ledger:read` key could mint itself a `platform:admin` key and take the account over — every ability tier would be a speed bump. The check is on the `apikey:` name prefix rather than the credential's type, because this app's SPA authenticates with a `createToken('api-token')` bearer rather than a Sanctum cookie session; the two are the same token class. Listing and revoking stay reachable with a key, so a leak can be cut without a human present.
- The audit log moved off `ledger:read` onto its own `audit:read`. An audit row carries `user_id` plus full `before` and `after` images, so a read-only integration would otherwise have seen every change every colleague made — including allocation targets and goals that were never published. Reading a number is not reading the change history behind it.
- Areas are separated so a `ledger:destructive` key cannot read budgets, and a `planning:read` key cannot read journals. Previously all six feature areas shared one ladder, leaving a blanket read as the only safe thing to hand a narrow integration.
- `/me/api-keys` sits outside the `ability` middleware group: these routes manage credentials rather than ledger data, so the ledger tiers do not describe them, and `platform:admin` is not a ledger ability at all.
- Revoking another account's key id returns `404`, not `403` — a 403 would confirm the id exists there.
- Minting is throttled to 10/hour per user; a hijacked session cannot spray usable keys.

### Notes

- **Not a breaking change for API clients.** Tokens issued by `POST /login` carry no ability list and keep full access; adding the middleware costs existing clients nothing. Browser sessions are likewise not ability-gated. Only keys issued through `api:issue-key` are constrained.
- `platform:admin` is absent from the self-service picker. It is for staff accounts, and offering it would let an ordinary user mint a key worth nothing until they are promoted. The ability itself is unchanged and `api:issue-key` can still issue one.

## [0.1.2] - 2026-09-05

### Added

- Allocation & Safe Money killer feature (Sprint 3): `Allocation` model with lifecycle status (`Active`, `Completed`, `Cancelled`, `Expired`), `account_allocations` pivot for strict reservations, and `SafeMoneyService` implementing the formula `EA - AA - O` (where `O = 0.0` in V1).
- `AllocationAdjustmentService` with atomic `allocate`/`release` logic that enforces strict reservation bounds (cannot exceed `postedNetBalance`) and deletes pivot rows at zero.
- `OverviewController` dashboard rollup exposing `safe_to_spend`, `eligible_assets`, `allocated` (with aspirational `total_target` and `unfunded` gap), `other_obligations`, `is_over_allocated`, and `safe_money_formula`.
- 6-month wealth history (`wealth_history`) on the overview endpoint.
- Migration `2026_09_05_044142_add_lifecycle_to_allocations_table` adding `status`, `expires_at`, and `completed_at` to allocations.

### Changed

- Journal posting with allocation adjustments now runs inside a single DB transaction; if allocation validation fails, the entire journal rolls back.
- `OverviewController` returns money values formatted as strings (`number_format($x, 2, '.', '')`) for consistent client consumption.

## [0.1.1] - 2026-08-24

### Added

- `BalancedJournalLines` validation rule that rejects journal lines whose total debits do not equal total credits, applied to both create and update journal requests (#8).
- Re-verification of double-entry balance for AI-generated drafts; unbalanced provider responses now fail with a 502 (#8).
- Database migration `2026_08_24_062255_make_journals_reference_unique` that changes `journals.reference` from `text` to `string(255)` and adds a unique composite index on `(tenant_id, reference)` (#11).
- Concurrency-safe auto reference generation: journal creation and reversal retry on unique constraint violations (#11).
- `Account::budgets()` relation for budget link checks (#10).
- Feature tests covering balance enforcement, transaction rollback, FK-safe deletion, duplicate references, forged reversal fields, and double reversal.

### Changed

- `JournalController::store()`, `update()`, and `reverse()` as well as `BudgetController::store()` and `update()` now run their multi-write operations inside a database transaction so partial writes roll back on failure (#9).
- Journal and account deletion now intentionally returns `409 Conflict` when referenced data exists, instead of surfacing a raw foreign-key exception (#10):
  - A journal cannot be deleted when it has journal lines, audit logs, or reversals.
  - An account cannot be deleted when it has journal lines, budget links, or child accounts.

### Fixed

- A journal can no longer be reversed more than once; subsequent reversal attempts return `409 Conflict` (#11).

### Security

- Clients can no longer set `reverse_from_id` through journal create/update requests; reversal links are only created by the system (#11).
- Clients can no longer forge system-generated entries by submitting `source: system`; only `manual` and `imported` are accepted (#11).
