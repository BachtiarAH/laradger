<?php

namespace App\Console\Commands;

use App\Models\Allocation;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('allocations:roll-forward {--as-of= : Date to evaluate roll forward for}')]
#[Description('Roll forward recurring allocations to the next period according to recurrence rules and carry-over settings')]
class RollForwardAllocationsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $asOf = $this->option('as-of') ? Carbon::parse($this->option('as-of')) : now();

        $allocations = Allocation::withoutGlobalScopes()
            ->where('type', 'recurring')
            ->where('status', 'active')
            ->get();

        $count = 0;

        foreach ($allocations as $allocation) {
            $periodEnd = $allocation->ends_at ?? $asOf->copy()->startOfMonth()->subDay();

            // If the allocation period has ended
            if ($periodEnd->isPast() || $periodEnd->isSameDay($asOf)) {
                $remaining = $allocation->remainingAmount(
                    $allocation->starts_at,
                    $allocation->ends_at
                );

                $carryOver = $allocation->roll_forward_mode === 'carry_over' ? $remaining : 0.0;

                $nextStart = ($allocation->ends_at ? $allocation->ends_at->copy()->addDay() : $asOf->copy()->startOfMonth());
                $nextEnd = match ($allocation->period_type) {
                    'weekly' => $nextStart->copy()->endOfWeek(),
                    'yearly' => $nextStart->copy()->endOfYear(),
                    default => $nextStart->copy()->endOfMonth(),
                };

                $allocation->update([
                    'starts_at' => $nextStart,
                    'ends_at' => $nextEnd,
                    'carry_over_amount' => $carryOver,
                ]);

                $count++;
            }
        }

        $this->info("Rolled forward {$count} recurring allocations.");

        return self::SUCCESS;
    }
}
