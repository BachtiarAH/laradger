# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
