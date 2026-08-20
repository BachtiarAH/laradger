---
paths:
  - 'app/Models/**'
---

# Models

## Multi-tenant scoping: BelongsToTenant + journal-child scopes
Tenant isolation is enforced at the model layer. Tenant-owned models use the BelongsToTenant trait (sets tenant_id on create, adds tenant global scope). Child models without a tenant_id column (JournalLine, JournalTag) must add a 'tenant' global scope scoping via their parent journal relationship — do not bypass with unscoped queries. The tenant slug comes from the URL path (`/api/v1/{tenant}/...`); SetTenantContext (api group middleware) and ResolveTenant set TenantContext.
