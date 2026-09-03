---
paths:
  - 'app/Models/JournalTemplate*.php'
  - 'app/Services/JournalTemplateService.php'
  - 'app/Console/Commands/ProcessJournalTemplates.php'
---

# Journal Templates (recurring journals)

## Domain
`journal_templates` stores reusable journal blueprints. `period_type` is `daily|weekly|monthly`
with optional `day_of_week` (0-6) / `day_of_month` (1-31). Active templates are turned into draft
journals (`source='system'`, default line amounts) — either manually via the `generate` endpoint or
automatically by the `journal-templates:process` command (scheduled `dailyAt('06:00')` in
`bootstrap/app.php`).

## Multi-tenant scoping
`JournalTemplate` uses `BelongsToTenant` (has its own `tenant_id`). The child models
`JournalTemplateLine` and `JournalTemplateTag` have no `tenant_id` column and must add a `tenant`
global scope scoping via their `journalTemplate` relationship — do not bypass with unscoped queries
(same rule as `journal_lines` / `journal_tags`).

## Scheduler / tenant context
The scheduled command runs without a `{tenant}` route, so `TenantContext` is empty. The generation
service (`JournalTemplateService::generate`) explicitly sets `TenantContext` to the template's
tenant before creating the `Journal` so `tenant_id` inheritance, per-tenant reference generation,
and child scopes work. Never create journals inside the command without first setting the tenant
context.

## Scheduling logic
`JournalTemplate::nextRunDate()` computes the next occurrence on/after a date. On store, `next_run_at`
is initialized from it (not `+1 day`). After each generation, `advanceSchedule()` moves `next_run_at`
forward by the period.
