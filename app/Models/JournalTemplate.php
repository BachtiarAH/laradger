<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\JournalTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalTemplate extends Model
{
    /** @use HasFactory<JournalTemplateFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'period_type',
        'is_active',
        'day_of_week',
        'day_of_month',
        'next_run_at',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => 'string',
            'is_active' => 'boolean',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalTemplateLine::class)->orderBy('line_number');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'journal_template_tags')->withTimestamps();
    }

    /**
     * Calculate the next run date following the template's periodicity.
     */
    public function nextRunDate(?CarbonInterface $from = null): CarbonInterface
    {
        $from = $from ?? now();

        return match ($this->period_type) {
            'weekly' => ($this->day_of_week !== null)
                ? $this->nextWeekly((int) $this->day_of_week, $from)
                : $from->copy()->addWeek()->startOfDay(),
            'monthly' => ($this->day_of_month !== null)
                ? $this->nextMonthly((int) $this->day_of_month, $from)
                : $from->copy()->addMonth()->startOfMonth(),
            default => $from->copy()->addDay()->startOfDay(),
        };
    }

    /**
     * Find the next occurrence of a weekday on/after $from.
     */
    private function nextWeekly(int $day, CarbonInterface $from): CarbonInterface
    {
        $candidate = $from->copy()->startOfDay();
        while ((int) $candidate->dayOfWeek !== $day) {
            $candidate->addDay();
        }

        return $candidate;
    }

    /**
     * Find the next occurrence of a given day of month on/after $from.
     */
    private function nextMonthly(int $day, CarbonInterface $from): CarbonInterface
    {
        $base = $from->copy()->startOfMonth();
        $candidate = $base->copy()->day(min($day, $base->daysInMonth))->startOfDay();

        if ($candidate->lt($from->copy()->startOfDay())) {
            $next = $base->addMonth();
            $candidate = $next->copy()->day(min($day, $next->daysInMonth))->startOfDay();
        }

        return $candidate;
    }
}
