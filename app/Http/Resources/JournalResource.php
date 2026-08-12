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
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'transaction_date' => $this->transaction_date?->toIso8601String(),
            'description' => $this->description,
            'reference' => $this->reference,
            'status' => $this->status,
            'source' => $this->source,
            'reverse_from_id' => $this->reverse_from_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => new UserResource($this->user)),
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
