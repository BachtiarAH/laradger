<?php

namespace App\Http\Resources;

use App\Enums\AllocationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AllocationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof AllocationStatus ? $this->status->value : ($this->status ?? 'active');
        $totalAllocated = $this->totalAllocatedValue();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'target_amount' => $this->target_amount !== null ? number_format((float) $this->target_amount, 2, '.', '') : null,
            'type' => $this->type ?? 'recurring',
            'period_type' => $this->period_type ?? 'monthly',
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'roll_forward_mode' => $this->roll_forward_mode ?? 'reset',
            'carry_over_amount' => number_format((float) ($this->carry_over_amount ?? 0), 2, '.', ''),
            'realized_amount' => number_format($this->realizedAmount(), 2, '.', ''),
            'remaining_amount' => number_format($this->remainingAmount(), 2, '.', ''),
            'progress_percent' => $this->progressPercent(),
            'status' => $status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'total_allocated' => $this->when(
                $totalAllocated !== null,
                number_format((float) $totalAllocated, 2, '.', ''),
            ),
            'unfunded_amount' => $this->when(
                $this->target_amount !== null && $totalAllocated !== null,
                number_format(max(0.0, (float) $this->target_amount - (float) $totalAllocated), 2, '.', ''),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'accounts' => $this->whenLoaded('accounts', function (): array {
                return $this->accounts->map(fn ($account) => [
                    'account_id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'currency' => $account->currency,
                    'amount' => number_format((float) $account->pivot->amount, 2, '.', ''),
                ])->values()->all();
            }),
        ];
    }

    /**
     * Total reserved across every account, when it can be derived without
     * extra queries (withSum aggregate on lists, or loaded accounts on detail).
     */
    private function totalAllocatedValue(): ?float
    {
        if (array_key_exists('total_allocated', $this->getAttributes())) {
            return (float) ($this->total_allocated ?? 0);
        }

        if ($this->relationLoaded('accounts')) {
            return (float) $this->accounts->sum(fn ($account) => (float) $account->pivot->amount);
        }

        return null;
    }
}
