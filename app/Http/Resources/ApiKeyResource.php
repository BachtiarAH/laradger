<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A key's metadata.
 *
 * The secret is never part of this. `plainTextToken` exists exactly once, on the
 * create response, and is never stored — only its SHA-256 hash is — so there is
 * nothing here to leak. Anything derived from the secret is likewise withheld:
 * no prefix, no hint.
 */
class ApiKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label($request),
            'abilities' => $this->abilities ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'is_expired' => $this->expires_at !== null && $this->expires_at->isPast(),
        ];
    }

    /**
     * The user-supplied note, without the two things `ApiKeyIssuer` prepends for
     * the console's benefit: the `apikey:` prefix, which is how issued keys are
     * told apart from login tokens, and the account's own email address, which
     * the payload's caller already knows.
     *
     * Both are stripped rather than hidden, so what the UI shows is exactly what
     * the person typed. Stripping the prefix alone would leave the email glued
     * to the front of every label.
     */
    private function label(Request $request): string
    {
        $name = (string) $this->name;

        $prefix = (string) config('api-keys.name_prefix');
        if ($prefix !== '' && str_starts_with($name, $prefix)) {
            $name = substr($name, strlen($prefix));
        }

        $email = (string) ($request->user()?->email ?? '');
        if ($email !== '' && str_starts_with($name, $email)) {
            $name = substr($name, strlen($email));
        }

        return trim($name);
    }
}
