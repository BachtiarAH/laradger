---
paths:
  - 'app/Http/Controllers/Api/UserAdminController.php'
  - 'routes/api.php'
  - 'app/Console/Commands/CreateAdminUser.php'
---

# Platform admin area

## Admins are dedicated staff accounts — never promoted customers
`users.is_admin` marks a platform admin. An admin account must be **created as a new dedicated staff account** (`is_admin = true` at store time, either via the panel "Add user" or the `admin:create` CLI command). Admin access can **never be toggled on an existing account**: `PUT /admin/users/{user}` rejects `is_admin` (`prohibited`). This is deliberate — customer accounts are never promoted or demoted.

## Guarding the panel
`/api/v1/admin/*` endpoints have no `{tenant}` prefix. Access is enforced by the `admin` middleware alias (`EnsureUserIsAdmin`, 403 for non-admins). Bootstrap the first admin with `php artisan admin:create {email} --password=...` — it refuses emails that already exist so no customer account is ever repurposed.

## users.status gates login and revokes sessions
`users.status` ∈ `active | suspended | terminated` (enum `App\Enums\UserStatus`). Only `active` accounts may log in (AuthController). Suspending/terminating must delete the user's Sanctum tokens so current sessions die immediately. Termination is reversible (restore by setting `status = active`) — there is deliberately no DELETE endpoint on the admin area.

## Safety guards
- An admin can never change their **own** `status` (self lockout) — 422.
- Accounts are never hard-deleted through this panel.
