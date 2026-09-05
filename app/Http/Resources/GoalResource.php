<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'target_amount' => number_format((float) $this->target_amount, 2, '.', ''),
            'current_amount' => number_format($this->accumulatedAmount(), 2, '.', ''),
            'remaining_amount' => number_format($this->remainingAmount(), 2, '.', ''),
            'progress_percent' => $this->progressPercent(),
            'target_date' => $this->target_date?->toDateString(),
            'recurring_contribution_amount' => $this->recurring_contribution_amount !== null
                ? number_format((float) $this->recurring_contribution_amount, 2, '.', '')
                : null,
            'contribution_frequency' => $this->contribution_frequency ?? 'monthly',
            'actual_contribution_this_period' => number_format($this->actualContributionThisPeriod(), 2, '.', ''),
            'pending_contribution_this_period' => number_format($this->pendingContributionThisPeriod(), 2, '.', ''),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
