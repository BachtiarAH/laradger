<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalTemplateResource extends JsonResource
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
            'period_type' => $this->period_type,
            'is_active' => $this->is_active,
            'day_of_week' => $this->day_of_week,
            'day_of_month' => $this->day_of_month,
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lines_count' => (int) ($this->lines_count ?? ($this->relationLoaded('lines') ? $this->lines->count() : 0)),
            'lines' => JournalTemplateLineResource::collection($this->whenLoaded('lines')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
