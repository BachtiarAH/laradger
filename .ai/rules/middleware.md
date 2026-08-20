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
