<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'transaction_date' => $this->transaction_date?->toIso8601String(),
            'description' => $this->description,
            'reference' => $this->reference,
            'status' => $this->status,
            'source' => $this->source,
            'reverse_from_id' => $this->reverse_from_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];

        // Line aggregates are only available when the listing query
        // loads them via withCount/withSum.
        if (array_key_exists('lines_sum_debit', $this->getAttributes())) {
            $data['total_debit'] = number_format((float) ($this->lines_sum_debit ?? 0), 2, '.', '');
            $data['total_credit'] = number_format((float) ($this->lines_sum_credit ?? 0), 2, '.', '');
            $data['lines_count'] = (int) ($this->lines_count ?? 0);
        }

        return $data;
    }
}
