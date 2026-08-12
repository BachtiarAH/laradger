<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalTagResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'journal_id' => $this->journal_id,
            'tag_id' => $this->tag_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'journal' => $this->whenLoaded('journal', fn () => new JournalResource($this->journal)),
            'tag' => $this->whenLoaded('tag', fn () => new TagResource($this->tag)),
        ];
    }
}
