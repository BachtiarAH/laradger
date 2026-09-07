<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\AllocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Allocation extends Model
{
    /** @use HasFactory<AllocationFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'target_amount',
        'type',
        'period_type',
        'starts_at',
        'ends_at',
        'roll_forward_mode',
        'carry_over_amount',
        'manual_realized_amount',
        'status',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'carry_over_amount' => 'decimal:2',
            'manual_realized_amount' => 'decimal:2',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'status' => AllocationStatus::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Allocation $allocation): void {
            if (! $allocation->status) {
                $allocation->status = AllocationStatus::Active;
            }
            if (! $allocation->type) {
                $allocation->type = 'recurring';
            }
            if (! $allocation->period_type) {
                $allocation->period_type = 'monthly';
            }
            if (! $allocation->roll_forward_mode) {
                $allocation->roll_forward_mode = 'reset';
            }
        });
    }

    /**
     * @param  Builder<Allocation>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AllocationStatus::Active);
    }

    /**
     * Accounts this allocation reserves money on, with the reserved amount.
     * Kept for backward compatibility with account-level reservations.
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_allocations', 'allocation_id', 'account_id')
            ->withPivot('amount')
            ->withTimestamps();
    }

    /**
     * Expense accounts linked to this allocation for auto-spend fulfillment.
     */
    public function expenseAccounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'allocation_expense_accounts', 'allocation_id', 'account_id')
            ->withTimestamps();
    }

    /**
     * Journals linked to this allocation.
     */
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    /**
     * Sum of every reservation across all accounts for this allocation.
     */
    public function allocatedTotal(): float
    {
        return (float) $this->accounts()->sum('account_allocations.amount');
    }

    /**
     * Realized spending from journal transactions linked to this allocation (explicitly or via auto-spend expense accounts).
     */
    public function journalRealizedAmount(?CarbonInterface $start = null, ?CarbonInterface $end = null): float
    {
        $expenseAccountIds = $this->relationLoaded('expenseAccounts')
            ? $this->expenseAccounts->pluck('id')->all()
            : $this->expenseAccounts()->pluck('accounts.id')->all();

        $query = JournalLine::query()
            ->whereHas('journal', function ($q) use ($start, $end) {
                $q->whereIn('status', ['posted', 'archived'])
                    ->whereDoesntHave('reversals');

                if ($start) {
                    $q->whereDate('transaction_date', '>=', $start);
                }
                if ($end) {
                    $q->whereDate('transaction_date', '<=', $end);
                }
            })
            ->where(function ($q) use ($expenseAccountIds) {
                $q->whereHas('journal', fn ($j) => $j->where('allocation_id', $this->id));

                if ($expenseAccountIds !== []) {
                    $q->orWhere(function ($auto) use ($expenseAccountIds) {
                        $auto->whereHas('journal', fn ($j) => $j->whereNull('allocation_id'))
                            ->whereIn('account_id', $expenseAccountIds);
                    });
                }
            })
            ->whereHas('account', fn ($q) => $q->where('type', 'expense'));

        $totalDebits = (float) (clone $query)->sum('debit');
        $totalCredits = (float) (clone $query)->sum('credit');

        return max(0.0, $totalDebits - $totalCredits);
    }

    /**
     * Manual direct deduction without transactions.
     */
    public function manualRealizedAmount(): float
    {
        return (float) ($this->manual_realized_amount ?? 0);
    }

    /**
     * Total realized spending across journal transactions and manual direct deductions.
     */
    public function realizedAmount(?CarbonInterface $start = null, ?CarbonInterface $end = null): float
    {
        return max(0.0, $this->journalRealizedAmount($start, $end) + $this->manualRealizedAmount());
    }

    /**
     * Total planned / effective target including carry-over.
     */
    public function effectiveTargetAmount(): float
    {
        return (float) ($this->target_amount ?? 0) + (float) ($this->carry_over_amount ?? 0);
    }

    /**
     * Remaining unspent commitment for this allocation.
     */
    public function remainingAmount(?CarbonInterface $start = null, ?CarbonInterface $end = null): float
    {
        $effective = $this->effectiveTargetAmount();
        $realized = $this->realizedAmount($start, $end);

        return max(0.0, $effective - $realized);
    }

    /**
     * Percentage of the allocation that has been realized/used.
     */
    public function progressPercent(?CarbonInterface $start = null, ?CarbonInterface $end = null): float
    {
        $effective = $this->effectiveTargetAmount();
        if ($effective <= 0) {
            return 0.0;
        }

        return min(100.0, round(($this->realizedAmount($start, $end) / $effective) * 100, 2));
    }
}
