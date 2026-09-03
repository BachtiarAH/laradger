<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalTemplateLineResource extends JsonResource
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
            'journal_template_id' => $this->journal_template_id,
            'account_id' => $this->account_id,
            'line_number' => (int) $this->line_number,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'account' => $this->whenLoaded('account', fn () => new AccountResource($this->account)),
        ];
    }
}
