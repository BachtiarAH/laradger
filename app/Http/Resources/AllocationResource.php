<?php

namespace App\Http\Resources;

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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'target_amount' => $this->target_amount !== null ? number_format((float) $this->target_amount, 2, '.', '') : null,
            'total_allocated' => $this->when(
                $this->totalAllocatedValue() !== null,
                number_format((float) $this->totalAllocatedValue(), 2, '.', ''),
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
