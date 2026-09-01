---
paths:
  - 'database/**'
---

# Database safety

## Never destroy the local database without permission
`database/database.sqlite` holds real testing data, not disposable scratch state. Never run data-destroying commands against it without asking first: `migrate:fresh`, `db:wipe`, or any `migrate:rollback`/`migrate:reset` that would revert migrations beyond ones created in the current session.

## Verify new migrations non-destructively
Validate new migrations by running the test suite (`php artisan test --compact`) and, when needed, `migrate:rollback --step=1 && php artisan migrate` only for the migration just created - never `migrate:fresh`.
