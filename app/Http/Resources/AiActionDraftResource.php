<?php

namespace App\Http\Resources;

use App\Models\AiActionDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiActionDraft
 */
class AiActionDraftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'kind' => $this->kind,
            'title' => $this->title,
            'status' => $this->status,
            // Exactly what will be sent to the real endpoint on approval.
            'payload' => $this->payload,
            'result' => $this->result,
            'error' => $this->error,
            // What this draft is waiting on, and what state that is in. The card
            // needs this to say "approving this also creates the tag" instead of
            // letting the user find out by getting an error.
            'depends_on' => $this->whenLoaded(
                'dependencies',
                fn (): array => $this->dependencies->map(fn (AiActionDraft $dependency): array => [
                    'id' => $dependency->id,
                    'tool' => $dependency->tool,
                    'title' => $dependency->title,
                    'status' => $dependency->status,
                ])->values()->all(),
            ),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
