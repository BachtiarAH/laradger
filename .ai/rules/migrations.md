---
paths:
  - 'database/migrations/**'
---

# Migrations

## Migration files are immutable
Never edit, delete, or rename existing migration files. Once a migration is committed, treat it as immutable - schema changes are made by adding a new migration. Do not change a migration after it has been run in any environment.
