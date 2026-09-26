<?php

namespace App\Http\Resources;

use App\Models\AiDraftRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiDraftRequest
 */
class AiDraftRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'reply' => $this->reply,
            'status' => $this->status,
            'error' => $this->error,
            'drafts_count' => $this->drafts_count,
            // Set only when the turn deliberately proposed nothing, so the UI can
            // report a decision instead of rendering an empty result as a failure.
            'outcome' => $this->outcome,
            'outcome_reason' => $this->outcome_reason,
            'outcome_reference' => $this->outcome_reference,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
