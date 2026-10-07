---
paths:
  - 'app/Http/Middleware/**'
---

# Middleware

## SetTenantContext runs in the api group, before route model binding
SetTenantContext is registered via `$middleware->api(prepend: ...)`, so it runs after route matching (can read `$request->route('tenant')`) but before SubstituteBindings. It must set TenantContext for the `{tenant}` slug WITHOUT throwing — an unknown slug just passes through so invalid-token requests still get 401 from auth:sanctum. Its job is to make route model binding tenant-scoped (so cross-tenant resources 404, not 403).

## Tenant errors use 404/403, never 422
ResolveTenant (route middleware `tenant`) throws NotFoundHttpException (404) for an unknown tenant slug and AccessDeniedHttpException (403) for a non-member slug. It runs after auth:sanctum. Do not use 422 for tenant selection.

## Controller methods on {tenant}-prefixed routes need a `string $tenant` first param
Laravel passes leftover route params positionally, so a `{tenant}` param would pollute type-hinted model params. Every controller method on a prefixed route must declare `string $tenant` as its first parameter.

## A second `withToken()` in one test does NOT change the acting credential
`withToken()` only rewrites a default header. The auth manager caches the user it resolved on the first guarded request and Sanctum reads that cache instead of re-reading the header, so a second `withToken()` keeps authenticating as the *first* token. Call `app('auth')->forgetGuards()` when switching credentials — `asApiKey()` in `tests/Feature/Api/ApiKeyTest.php` does this. Symptom if you forget: a test that asserts a denial and then a success with a wider key fails on the second assertion with a 403 for the *earlier* key. Production is unaffected (fresh container per request); it is a test-harness artifact only.

## Abilities are `area:tier`, and areas never imply each other
Tiers climb **within** an area only: `ledger:destructive` writes and reads without holding those abilities, but it cannot read budgets. Crossing areas would defeat the point — a reporting integration needs budgets and has no reason to see journals, and with one shared ladder the only safe grant is a blanket read. Adding an area means adding a case to `ApiAbility` **and** a list under `areas` in `config/api-keys.php`; the tier then follows from the HTTP method automatically. Unlisted paths fall back to `ledger` on purpose — a new endpoint must be considered rather than drift into the least restrictive area. `ApiArea::ability()` maps tier→name, which is why `platform` returns `platform:admin` from there rather than constructing `platform:read` (an ability that does not exist, and whose absence silently ungated the whole admin area).

## `/me/api-keys` is outside the `ability` group, and only a person may mint
Key management is not ledger access, so the ledger tiers do not describe it and `platform:admin` is not a ledger ability at all — gating these routes with `ability` would stop a read key listing its own keys while letting it mint one. They sit in their own `auth:sanctum`-only group. `POST` refuses any credential carrying the `apikey:` name prefix and 403s it.

**Test "is this a person?" by the `apikey:` name prefix, not by token type.** This app has no Sanctum cookie sessions in practice: `AuthController` hands the SPA a `createToken('api-token')` (or `device_name`) bearer token which the client keeps in `localStorage`. That is a `PersonalAccessToken` — indistinguishable by type from a key. An earlier version checked for `TransientToken` and so 403'd the real login path; the test suite passed because `$this->actingAs()` does create a genuine session, and only a live HTTP check against `php artisan serve` caught it.

The prefix is already the project's marker for "issued key" — `api:revoke-key` filters on it so revoking keys cannot reach the token someone is logged in with. Reusing it keeps one rule, not two.

The refusal is load-bearing: a key that can issue a key can always issue one wider than itself, so the hierarchy means nothing once a read-only key leaks. Listing and revoking stay open to keys on purpose — a leak must be revocable without a human. Do not "simplify" this into the `ability` group or drop the prefix check.
