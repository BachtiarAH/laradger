<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'amount' => $this->amount,
            'budget_type' => $this->budget_type,
            'period_type' => $this->period_type ?? 'custom',
            'is_recurring' => (bool) ($this->is_recurring ?? false),
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'accounts' => AccountResource::collection($this->whenLoaded('accounts')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
