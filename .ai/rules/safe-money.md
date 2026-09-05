# Safe Money — Sprint 3 Locked Decisions

## Formula V1

```
STS = EA - AA - O
```

- **EA (Eligible Assets)** = Σ `postedNetBalance` over asset leaf accounts (`type=asset`, `is_header=false`, `status=active`), posted+archived journals only.
- **AA (Active Allocated)** = Σ `account_allocations.amount` where `allocations.status = 'active'`.
- **O (Other Obligations)** = `0.0` in V1. Explicitly deferred to V2.

Safe Money may be negative. This is surfaced as `is_over_allocated: true` in the API and as a badge in the UI.

## Allocation Semantics

- **`target_amount`** is aspirational. It may exceed the eligible asset balance.
- **Reserved amount** (pivot `account_allocations.amount`) is strictly bounded by the account's `postedNetBalance`. You cannot reserve more than the posted balance.

## Lifecycle Status

`AllocationStatus` enum: `Active`, `Completed`, `Cancelled`, `Expired`. Only `Active` counts toward Safe Money (`countsTowardsAllocated()`).

Completing or cancelling an allocation deletes all pivot rows (releases reservations). Pulling reserved money is done via the `release` action, not by mutating status.

## Float Epsilon

`0.0001` is the project-wide tolerance for decimal comparisons. Reuse this constant in all money comparisons.

```php
// AllocationAdjustmentService
if ($currentTotal + $amount > $available + 0.0001) { ... }
if ($amount > $current + 0.0001) { ... }
if ($amount >= $current - 0.0001) { ... }

// SafeMoneyService
'is_over_allocated' => $allocated > $eligibleAssets + 0.0001,
```

## Tenant Scoping on Raw Queries

`SafeMoneyService::activeAllocated()` uses `DB::table('account_allocations')` and joins `allocations` for tenant scoping. It does **not** leverage the `BelongsToTenant` global scope. Tenant isolation depends entirely on `allocations.tenant_id` being correct.

## Legacy Dual `safe_money` Field

`OverviewController` returns two distinct money values:

- `safe_money` (line 66, 139) = budget-based heuristic (`income_actual − (expense_budgeted + overspend)`). Legacy/parallel.
- `safe_to_spend` (line 88, 140) = allocation-based formula from `SafeMoneyService`. This is the authoritative Safe Money value.

Frontend consumers should use `safe_to_spend`.

## Eligible Assets Scope

`eligibleAssets()` includes **all** asset accounts (equipment, inventory, etc.), not just cash-like accounts. The filter is `type=asset AND is_header=false AND status=active`. There is no "liquidity" tier. If the business later wants "spendable cash" vs "illiquid assets", a new filter is needed.

## V2 Deferrals

- **Other Obligations** — requires a new `bills`/`obligations` model/migration with `due_at`, a new scope in `SafeMoneyService`, and updated tests.
- **Expiring allocations** — `expires_at` column exists but no scheduled job moves `active → expired`.
- **Multi-currency Safe Money** — `Account.currency` exists but no FX. Currently single-currency per tenant; mixing would corrupt totals.
- **`SavingGoal`, `Pocket`, `Investment`** — empty scaffolds; do not assume they exist.

## Relevant Files

- `app/Services/SafeMoneyService.php`
- `app/Http/Controllers/Api/OverviewController.php`
- `app/Services/AllocationAdjustmentService.php`
- `app/Models/Allocation.php`
- `app/Enums/AllocationStatus.php`
