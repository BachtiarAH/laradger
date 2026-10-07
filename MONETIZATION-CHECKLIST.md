# Monetization Checklist — Laradger

Rencana monetisasi: **SaaS subscription per tenant + lisensi self-hosted**, satu
codebase, dua mode. Gate lewat `BILLING_ENFORCED`.

> **Status:** rencana, belum ada kode yang ditulis. Centang saat dikerjakan.
> Untuk keputusan durable yang sudah disepakati, catat juga di `.ai/rules`.

## Kunci keputusan (bukan opsional — ini yang mengikat semua di bawah)

- **Plan = baris DB, bukan enum/hardcode.** `plans.features` (json) +
  `plans.limits` (json). Supaya pivot segmen (UMKM → konsultan → global) cukup
  tambah baris, tanpa rewrite.
- **Midtrans tidak bisa auto-charge QRIS/VA.** Auto-recurring (`/v1/subscriptions`)
  hanya support `credit_card` + `gopay` tokenization. Jadi defaultnya
  **invoice-based renewal** (Payment Link/VA + email pengingat), jalur
  recurring hanya opsi premium.
- **Webhook satu-satunya sumber kebenaran.** Jangan pernah percaya redirect
  browser. Verifikasi signature via `\Midtrans\Notification::status()`.
- **V1 tanpa prorasi.** Upgrade/downgrade berlaku di akhir periode. Menuangkan
  di UI, jangan diam-diam.
- **Grace period 7 hari** saat telat bayar — user tidak boleh kehilangan riwayat
  pembukuan.
- **Sudah pakai `HasUuids`** (`Tenant`, `Journal`, dll) → `Plan`, `Subscription`,
  `Invoice` ikut.

## Keputusan terbuka (harus diputuskan sebelum fase 5)

- [ ] **Nama produk.** Repo = "Laradger", tapi UI landing menulis "Ledgify".
      Konsistenkan sebelum halaman pricing ada — nama itu akan dipakai di
      invoice, email, dan metadata Midtrans.
- [ ] **Harga + batas kuota** untuk tabel `plans`. Usulan awal (IDR):
      | Plan | Bulanan | Tahunan | Batas |
      |---|---|---|---|
      | Free | Rp0 | — | 1 org, 1 seat, 100 jurnal/bln, riwayat 6 bln |
      | Pro | Rp99.000 | Rp990.000 | unlimited jurnal, alokasi, safe-to-spend, goals, audit penuh |
      | Business | Rp349.000 | Rp3.490.000 | 5 seat, multi-org, export PDF |
      | Konsultan | Rp1.500.000 | — | unlimited org klien, white-label |
- [ ] **Model lisensi self-hosted:** sekali bayar (perpetual / per tahun) atau
      bulanan juga? menentukan isi `licenses` + alur aktivasi.

---

## Fase 1 — Config, migration, enum, model

- [ ] `config/billing.php`: `enforce` (env `BILLING_ENFORCED`, default `true`),
      `trial_days` (14), `grace_days` (7), `currency` (`IDR`), `default_plan`
- [ ] `config/services.php` → `midtrans`: `server_key`, `client_key`,
      `is_production`
- [ ] `composer require midtrans/midtrans-php`
- [ ] `SubscriptionStatus` enum (`app/Enums/`) — `Trialing`, `Active`, `PastDue`,
      `Grace`, `Canceled`, `Expired` + `hasAccess()`. Ikuti style
      `AllocationStatus` (TitleCase keys, helper method)
- [ ] `InvoiceStatus` enum — `Pending`, `Paid`, `Failed`, `Expired`
- [ ] Migrasi `plans` — `key`, `name`, `price_monthly`, `price_yearly`,
      `currency`, `trial_days`, `features` (json), `limits` (json), `is_active`,
      `sort_order`
- [ ] Migrasi `subscriptions` — `tenant_id`, `plan_id`, `status`, `billing_period`,
      `current_period_start/end`, `trial_ends_at`, `grace_ends_at`, `canceled_at`,
      `midtrans_subscription_id`. Partial unique index: 1 tenant = 1 langganan aktif
- [ ] Migrasi `invoices` — `tenant_id`, `subscription_id`, `number` (unik,
      `INV-2026-0001`), `period_start/end`, `amount`, `status`, `midtrans_order_id`,
      `midtrans_transaction_id`, `paid_at`, `due_at`
- [ ] Migrasi `licenses` — `key`, `tenant_id`, `plan_id`, `status`, `activated_at`,
      `expires_at`
- [ ] Migrasi `usage_counters` — `tenant_id`, `metric`, `period_start`, `count`.
      Unique (`tenant_id`, `metric`, `period_start`) → ini yang reset kuota bulanan
- [ ] Model + factory + seeder plans untuk `Plan`, `Subscription`, `Invoice`,
      `License`, `UsageCounter`
- [ ] `Tenant` dapat relasi `subscription()`, `invoices()`, `license()`

## Fase 2 — Service layer (belum ada Midtrans)

- [ ] `app/Contracts/Billing/PaymentGateway.php` — interface:
      `createCheckout()`, `verifyNotification()`, `disableRecurring()`
- [ ] `app/Services/Billing/PlanCatalog.php` — plan aktif, cache, `resolveByKey()`
- [ ] `app/Services/Billing/SubscriptionService.php` — `subscribe()`,
      `changePlan()`, `cancel()`, `resume()`, `startTrial()`
- [ ] `app/Services/Billing/InvoiceService.php` — `issueForPeriod()`,
      `markPaid()`, `markFailed()`, `nextNumber()`
- [ ] `app/Services/Billing/EntitlementService.php` — `allows($feature)`,
      `checkLimit($metric)`, `withinGrace()`
- [ ] `app/Services/Billing/UsageMeter.php` — `record('journals', 1)`,
      `assertWithin('journals_per_month')`
- [ ] `app/Services/Billing/Gateways/ManualGateway.php` — buat install
      self-hosted / dev tanpa kredensial Midtrans
- [ ] Trait `Billable` di `Subscription` — `hasFeature()`,
      `remainingQuota()`, `planKey()`

## Fase 3 — Enforcement

- [ ] Middleware `EnsureTenantIsBillable`, alias `billing` di
      `bootstrap/app.php` → `$middleware->alias()`
- [ ] Pasang di group `{tenant}` di `routes/api.php`, **setelah** middleware
      `tenant`, sebelum route bisnis
- [ ] Kalau `! config('billing.enforce')` → middleware langsung lolos (jalur
      self-hosted). Ini yang menjaga fork tidak pecah
- [ ] Platform admin (`is_admin`, sudah ada `EnsureUserIsAdmin`) selalu bypass
- [ ] Response code yang konsisten dengan pola existing (403/404/409 di
      `bootstrap/app.php`):
      - `402 Payment Required` → kuota habis / subscription expired
      - `403 Forbidden` → fitur terkunci di plan Free
- [ ] Hook `UsageMeter::assertWithin('journals_per_month')` di
      `JournalController::store()`
- [ ] Batasi seat di `tenant_users` dan jumlah org di `TenantController::store()`

## Fase 4 — Midtrans

- [ ] `app/Services/Billing/Gateways/MidtransGateway.php` — Snap token
      (`/snap/v1/transactions`, Basic auth = base64(`server_key` + `:`)) +
      Payment Link untuk invoice renewal
- [ ] `MidtransNotificationController` — route **tanpa auth**, di luar tenant group,
      dengan `throttle:billing-webhook`
- [ ] Verifikasi signature `\Midtrans\Notification::status()` — tolak 403 kalau
      tidak valid
- [ ] Idempoten: `where('midtrans_order_id', …)->where('status', Pending)` dalam
      satu DB transaction
- [ ] Handle status: `capture`/`settlement` → aktifkan; `pending`/`deny`/`expire`
      → catat, jangan aktifkan
- [ ] Command: `billing:issue-invoices` (harian — periode mau habis),
      `billing:expire-trials`, `billing:mark-overdue`,
      `billing:sync-midtrans` (reconcile `GET /v1/subscriptions/{id}`)
- [ ] Daftarkan schedule di `withSchedule()` pada `bootstrap/app.php`
      (precedent: `journal-templates:process`)

## Fase 5 — API + frontend

- [ ] `GET /v1/plans` — **publik, tanpa auth** (dipakai halaman pricing)
- [ ] Di group `{tenant}`: `GET billing`, `POST billing/subscribe`,
      `billing/change-plan`, `billing/cancel`, `billing/resume`,
      `billing/invoices/{invoice}/checkout`, `GET billing/usage`
- [ ] API Resources baru mengikuti pola 18 resource yang sudah ada di
      `app/Http/Resources/`
- [ ] Update `docs/openapi.yaml` + `docs/FE_MIGRATION.md` (perubahan breaking
      dari header `X-Tenant` sudah pernah didokumentasikan di sana —Ikuti gaya yang
      sama)
- [ ] FE: route `/pricing` (publik, baca `GET /v1/plans`)
- [ ] FE: route `/settings/billing` — plan sekarang, meter kuota, riwayat
      invoice, upgrade/downgrade/cancel, bayar invoice
- [ ] FE: komponen `UpgradeDialog` (kena 402/403) + hook `useBilling()` +
      `QuotaMeter`
- [ ] FE: integrasi Snap JS — sandbox
      `https://app.sandbox.midtrans.com/snap/v2.3.js`, `snap.pay(token, {onSuccess,
      onPending, onClose})`. Setelah sukses FE cuma refresh; penentu status tetap
      webhook

## Fase 6 — Trial + onboarding (tanpa ini tidak ada yang bayar)

- [ ] Trial 14 hari, tanpa kartu, mulai saat org pertama dibuat
- [ ] Template Chart of Accounts saat buat org (retail / jasa / freelance) —
      tanpa ini user berhenti di langkah 1
- [ ] Onboarding checklist di FE
- [ ] Password reset email — **sekarang belum ada sama sekali** dan ini menutup
      funnel
- [ ] Email pengingat invoice (penting, bukan opsional — lihat batasan Midtrans)

## Fase 7 — Self-hosted

- [ ] Jalur `BILLING_ENFORCED=false`: semua route/UI billing nonaktif otomatis
- [ ] `license:generate` / `license:activate` — lisensi hanya meng-*entitlement*
      fitur (mis. batas jumlah org), **bukan** untuk gate akses
- [ ] Paket installer + panduan deploy untuk versi self-hosted
- [ ] Test yang mengunci jalur ini (lihat daftar test di bawah)

## Fase 8 — Funnel (setelah core solid)

- [ ] Tulis ulang landing page (`laradger-web/src/routes/index.tsx` sekarang
      cuma placeholder; `about.tsx` kosong)
- [ ] Halaman marketing yang jujur soal value proposition + pricing + CTA
- [ ] Import CSV journal (enum `source: 'imported'` sudah ada, fiturnya belum)
- [ ] Laporan siap-audit / PDF export sebagai add-on berbayar

---

## Test (Pest, di `tests/Feature/Api/`)

- [ ] Resolusi plan; akses `trialing`/`active`/`grace`/`expired`
- [ ] Kuota jurnal habis → `402`; counter reset di periode berikutnya
- [ ] Seat limit & org limit
- [ ] Webhook: signature invalid ditolak; notifikasi duplikat idempoten;
      `deny` tidak mengaktifkan subscription
- [ ] Upgrade/downgrade berlaku di akhir periode (tanpa prorasi)
- [ ] **Self-hosted bypass**: dengan `BILLING_ENFORCED=false` semua test akses
      tetap lolos — kunci agar fork tidak pecah
- [ ] Platform admin bypass

## Risiko yang harus diingat

- [ ] **Midtrans tidak auto-charge QRIS/VA** → pengingat email WAJIB, bukan
      tambahan
- [ ] Prorasi & refund Midtrans merepotkan → v1 pakai perubahan di akhir periode
- [ ] Butuh MySQL/Postgres di produksi: `usage_counters` dan partial unique
      index belum tentu jalan di SQLite (dev)
- [ ] Bersihkan stub mati: `app/Http/Controllers/{Investment,Pocket,SavingGoal}Controller.php`
      modelnya tidak ada dan seed-nya kosong (`InvestmentSeeder`, `PocketSeeder`,
      `SavingGoalSeeder`)

## Setelah selesai

- [ ] Catat keputusan durable ke `.ai/rules` (bukan chat) supaya agent/team
      berikutnya mewarisi: aturan gate billing, response code 402 vs 403, kenapa
      prorasi ditunda
- [ ] `CHANGELOG.md` — tambahkan entri untuk fitur billing
- [ ] `vendor/bin/pint --format agent` + `php artisan test --compact`
