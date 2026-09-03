# QA Checklist — Allocation Feature

Manual test plan for the **Account Allocation** feature (allocate/release, cap enforcement, journal-side adjustments, audit trail). Covers backend `laradger/` + frontend `laradger-web/`.

## Setup

Reseed first so all numbers below match:

```bash
cd laradger && php artisan migrate:fresh --seed
```

Then run the apps:

```bash
# terminal 1 (backend :8000)
cd laradger && composer dev

# terminal 2 (web :3000)
cd laradger-web && npm run dev
```

Login: `test@example.com` / `password` → tenant `test-company`.

Seeded state used by the expectations below:

| Account | Balance (posted) | Allocated | Free |
|---|---|---|---|
| Jago | Rp18.100.000 | Rp7.000.000 (Dana Darurat 3jt, Laptop 2,5jt, Liburan 1,5jt) | Rp11.100.000 |
| BRI | Rp2.000.000 | Rp1.000.000 (Dana Darurat) | Rp1.000.000 |

> Note: drafts are excluded from the allocatable balance (matching Budgets/Overview). Allocating only writes pivot + audit rows — **it never creates a journal entry**.

## A. Verify seeded state

| # | Steps | Expected |
|---|---|---|
| TC-01 | Sidebar → **Allocations** | 4 funds: Dana Darurat, Laptop, Liburan, Pernikahan |
| TC-02 | Open **Dana Darurat** | Total Rp4.000.000 across **2 accounts**: Jago Rp3.000.000 + BRI Rp1.000.000 |
| TC-03 | Accounts → **Jago** → "Allocations" card | Available Rp18.100.000 · Allocated Rp7.000.000 · Unallocated Rp11.100.000 · rows: Dana Darurat 3jt, Laptop 2,5jt, Liburan 1,5jt |
| TC-04 | Accounts → **BRI** card | Unallocated Rp1.000.000 (only 1jt free to allocate) |

## B. Core allocate / release

| # | Steps | Expected |
|---|---|---|
| TC-05 | Allocations → **New** → name "PS5", target optional → save | Appears in list; card shows Rp0 |
| TC-06 | On "PS5": **Allocate** → Jago → Rp500.000 | Jago card: Allocated +500rb, Unallocated −500rb. **No journal created** (Journal list unchanged) |
| TC-07 | On "PS5": **Release** Rp200.000, then release the rest | Partial: 300rb stays. Full: row disappears from Jago card |
| TC-08 | On **BRI**, try to allocate Rp1.200.000 more to any fund (only 1jt free) | Error: `Allocating this amount would exceed the account's available balance of 2000000.00.` — nothing changes |
| TC-09 | Try to **Release** Rp10.000.000 from **Laptop** (only 2,5jt stored) | Error: `Cannot release more than the 2500000.00 currently allocated on this account.` |
| TC-10 | Try allocating on a **non-asset** account (e.g. Salary Income / Makanan) | Rejected: `Allocations can only be placed on asset accounts where money actually exists.` (picker may already hide them) |
| TC-11 | Edit "PS5" name/description → save; then delete it | Edit works; delete removes it; both appear in Audit Logs (`allocation.updated` / `.deleted`) |

## C. Rule enforcement on the ledger side

| # | Steps | Expected |
|---|---|---|
| TC-12 | Delete account **Jago** (it has allocations) | Blocked with 409: `The account cannot be deleted because it has allocations. Release them first.` — account still exists |
| TC-13 | Journal → New: Debit Makanan Rp19.000.000 / Credit Jago Rp19.000.000 → **posted** | Jago now over-allocated: card shows red warning **"Over-allocated"** and negative Unallocated. Soft rule — journal still posts (no blocking) |
| TC-14 | (cleanup) delete that journal → verify Jago card is back to normal | Warning gone, Unallocated Rp11.100.000 again |

## D. Allocation adjustments when creating a journal

| # | Steps | Expected |
|---|---|---|
| TC-15 | New journal "Top Up GoPay": Debit GoPay 500.000 / Credit Jago 500.000, status **posted** → panel "Alokasi (opsional)": **+ Penyesuaian** → Akun Jago · Aksi **Sisihkan (tambah)** · Alokasi **Laptop** · Rp500.000 → save | One save = journal posted **and** Laptop +500rb on Jago (7,5jt allocated total; no second journal created) |
| TC-16 | New journal: Debit Makanan 1.000.000 / Credit Jago 1.000.000, status **posted** → **+ Penyesuaian** → Akun Jago · Aksi **Kurangi (pakai)** · Alokasi **Dana Darurat** · Rp1.000.000 → save | Dana Darurat on Jago drops 3jt → 2jt; fund total 4jt → 3jt |
| TC-17 | Same as TC-15 but status **draft** (or any non-posted status) | 422, errors.status: `Allocation adjustments can only be applied to a posted journal.` — panel also shows a notice and disabled buttons while status = draft |
| TC-18 | New journal that empties Jago: Debit Makanan 20.000.000 / Credit Jago 20.000.000, status **posted**, with adjustment **Sisihkan** Rp500.000 on Jago | Error `Allocating this amount would exceed the account's available balance of 0.00.` — **entire journal is rolled back** (not created) |
| TC-19 | Adjustment **Kurangi (pakai)** more than stored: e.g. Dana Darurat on Jago, amount Rp10.000.000 (only 3jt stored) | Error `Cannot release more than the 3000000.00 currently allocated on this account.` — journal rolled back |
| TC-20 | New journal whose Lines contain **no asset account** (e.g. Debit Makanan / Credit Hiburan) | Panel shows: "Tambahkan minimal satu akun asset di bagian Lines untuk bisa mengatur alokasi." |

## E. Audit trail

After all the above, open **Audit Logs**: you should see `allocation.created / allocated / released / updated / deleted` entries with correct amounts. Allocations never appear as journals, and journal-side adjustments carry no `journal_id` (by design).

## Automated equivalent (fast pass)

```bash
cd laradger && php artisan test --filter='AllocationApiTest|AllocationJournalApiTest' --compact
```
