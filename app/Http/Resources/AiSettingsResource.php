<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiSettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The stored API key is deliberately absent: only `has_key` and the
     * `key_hint` the user chose to reveal are ever exposed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
