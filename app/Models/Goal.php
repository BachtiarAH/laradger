<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'target_amount',
        'target_date',
        'recurring_contribution_amount',
        'contribution_frequency',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'target_date' => 'date',
            'recurring_contribution_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Goal $goal): void {
            if (! $goal->status) {
                $goal->status = 'active';
            }
            if (! $goal->contribution_frequency) {
                $goal->contribution_frequency = 'monthly';
            }
        });
    }

    /**
     * @param  Builder<Goal>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    public function accumulatedAmount(): float
    {
        return (float) JournalLine::query()
            ->whereHas('journal', fn ($q) => $q->where('goal_id', $this->id)
                ->whereIn('status', ['posted', 'archived'])
                ->whereDoesntHave('reversals')
            )
            ->where('debit', '>', 0)
            ->whereHas('account', fn ($q) => $q->where('type', 'asset'))
            ->sum('debit');
    }

    public function actualContributionThisPeriod(?CarbonInterface $start = null, ?CarbonInterface $end = null): float
    {
        $start = $start ?? now()->startOfMonth();
        $end = $end ?? now()->endOfMonth();

        return (float) JournalLine::query()
            ->whereHas('journal', fn ($q) => $q->where('goal_id', $this->id)
                ->whereIn('status', ['posted', 'archived'])
                ->whereDoesntHave('reversals')
                ->whereDate('transaction_date', '>=', $start)
                ->whereDate('transaction_date', '<=', $end)
            )
            ->where('debit', '>', 0)
            ->whereHas('account', fn ($q) => $q->where('type', 'asset'))
            ->sum('debit');
    }

    public function pendingContributionThisPeriod(): float
    {
        $planned = (float) ($this->recurring_contribution_amount ?? 0);
        if ($planned <= 0) {
            return 0.0;
        }

        $actual = $this->actualContributionThisPeriod();

        return max(0.0, $planned - $actual);
    }

    public function remainingAmount(): float
    {
        return max(0.0, (float) $this->target_amount - $this->accumulatedAmount());
    }

    public function progressPercent(): float
    {
        if ((float) $this->target_amount <= 0) {
            return 0.0;
        }

        return min(100.0, round(($this->accumulatedAmount() / (float) $this->target_amount) * 100, 2));
    }
}
