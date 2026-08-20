# Petunjuk Perubahan untuk Frontend

> Status: **Breaking Change** — pindah dari header `X-Tenant` ke slug tenant di URL.

## Ringkasan

Seluruh endpoint yang di-scope per-tenant sekarang di-prefix dengan slug tenant di URL.
Header `X-Tenant` **dihapus total** dan tidak lagi dipakai.

Sebelumnya:

```
GET /api/v1/accounts
X-Tenant: acme-corp
Authorization: Bearer <token>
```

Sekarang:

```
GET /api/v1/acme-corp/accounts
Authorization: Bearer <token>
```

## Endpoint yang berubah

Tambahkan `/{tenant}` di depan path (ganti `{tenant}` dengan slug tenant):

| Sebelum | Sesudah |
|---|---|
| `POST /api/v1/logout` | `POST /api/v1/{tenant}/logout` |
| `GET/POST /api/v1/accounts` | `GET/POST /api/v1/{tenant}/accounts` |
| `GET/PUT/DELETE /api/v1/accounts/{account}` | `GET/PUT/DELETE /api/v1/{tenant}/accounts/{account}` |
| `GET/POST /api/v1/budgets` | `GET/POST /api/v1/{tenant}/budgets` |
| `GET/PUT/DELETE /api/v1/budgets/{budget}` | `GET/PUT/DELETE /api/v1/{tenant}/budgets/{budget}` |
| `GET/POST /api/v1/journals` | `GET/POST /api/v1/{tenant}/journals` |
| `POST /api/v1/journals/ai-draft` | `POST /api/v1/{tenant}/journals/ai-draft` |
| `GET/PUT/DELETE /api/v1/journals/{journal}` | `GET/PUT/DELETE /api/v1/{tenant}/journals/{journal}` |
| `POST /api/v1/journals/{journal}/reverse` | `POST /api/v1/{tenant}/journals/{journal}/reverse` |
| `GET/POST /api/v1/journal-lines` | `GET/POST /api/v1/{tenant}/journal-lines` |
| `GET/PUT/DELETE /api/v1/journal-lines/{journal_line}` | `GET/PUT/DELETE /api/v1/{tenant}/journal-lines/{journal_line}` |
| `GET/POST /api/v1/journal-tags` | `GET/POST /api/v1/{tenant}/journal-tags` |
| `GET/POST/PUT/DELETE /api/v1/tags` | `GET/POST/PUT/DELETE /api/v1/{tenant}/tags` |
| `GET/PUT/DELETE /api/v1/tags/{tag}` | `GET/PUT/DELETE /api/v1/{tenant}/tags/{tag}` |
| `GET /api/v1/audit-logs` | `GET /api/v1/{tenant}/audit-logs` |
| `GET /api/v1/audit-logs/{audit_log}` | `GET /api/v1/{tenant}/audit-logs/{audit_log}` |

**Tidak berubah:**

- `POST /api/v1/register`
- `POST /api/v1/login`
- `GET /api/v1/tenants`
- `POST /api/v1/tenants`

## Perubahan status code

| Situasi | Sebelum | Sekarang |
|---|---|---|
| Token invalid/hilang | 401 | **401** (tetap) |
| Slug tenant tidak dikenal di URL | 422 (via header) | **404** `Tenant not found.` |
| Token valid tapi bukan member tenant itu | 403 | **403** (tetap) |
| Resource milik tenant lain | 404 | **404** (tetap, via scope) |

## Aksi yang perlu dilakukan frontend

1. **Hapus** semua kode yang mengirim header `X-Tenant` (interceptor axios/fetch, dsb).
2. **Dapatkan slug tenant** dari `GET /api/v1/tenants` (field `slug`) atau dari respon
   `register`/`login` (`tenant.slug`).
3. **Prefix semua request protected** dengan slug:
   `API_BASE + '/' + tenantSlug + '/accounts'`.
4. **Update handler error**:
   - `404` pada path resource → tampilkan "tenant/tidak ditemukan".
   - `403` → "bukan member dari tenant ini".
   - `401` → redirect ke login.
5. **Default tenant**: setelah login/register, gunakan `tenant.slug` yang dikembalikan
   sebagai tenant aktif.