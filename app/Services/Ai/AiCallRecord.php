<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

class AiCallRecord implements Arrayable
{
    /**
     * @param  array<string, mixed>|null  $draft
     * @param  array<string, mixed>|null  $raw_response
     * @param  array<string, int|string>  $usage
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $user_id,
        public ?string $tenant_id,
        public string $provider,
        public string $model,
        public string $statement,
        public string $prompt,
        public ?array $draft,
        public ?array $raw_response,
        public array $usage,
        public int $latency_ms,
        public bool $success,
        public ?string $error = null,
        public ?string $journal_id = null,
    ) {}

    public static function start(
        string $provider,
        string $model,
        ?string $user_id,
        ?string $tenant_id,
        string $statement,
        string $prompt,
    ): self {
        return new self(
            id: (string) Str::uuid(),
            type: 'ai_call',
            user_id: $user_id,
            tenant_id: $tenant_id,
            provider: $provider,
            model: $model,
            statement: $statement,
            prompt: $prompt,
            draft: null,
            raw_response: null,
            usage: [],
            latency_ms: 0,
            success: false,
        );
    }

    /**
     * @param  array<string, mixed>|null  $draft
     * @param  array<string, mixed>|null  $raw_response
     * @param  array<string, int|string>  $usage
     */
    public function finish(
        int $latencyMs,
        bool $success,
        ?array $draft = null,
        ?array $rawResponse = null,
        array $usage = [],
        ?string $error = null,
    ): self {
        $this->latency_ms = $latencyMs;
        $this->success = $success;
        $this->draft = $draft;
        $this->raw_response = $rawResponse;
        $this->usage = $usage;
        $this->error = $error;

        return $this;
    }

    public static function confirmation(
        string $recordId,
        ?string $journalId,
        ?string $userId,
        ?string $tenantId,
    ): self {
        return new self(
            id: $recordId,
            type: 'confirmation',
            user_id: $userId,
            tenant_id: $tenantId,
            provider: '',
            model: '',
            statement: '',
            prompt: '',
            draft: null,
            raw_response: null,
            usage: [],
            latency_ms: 0,
            success: true,
            journal_id: $journalId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'timestamp' => now()->toIso8601String(),
            'user_id' => $this->user_id,
            'tenant_id' => $this->tenant_id,
            'provider' => $this->provider,
            'model' => $this->model,
            'statement' => $this->statement,
            'prompt' => $this->prompt,
            'draft' => $this->draft,
            'raw_response' => $this->raw_response,
            'usage' => $this->usage,
            'latency_ms' => $this->latency_ms,
            'success' => $this->success,
            'error' => $this->error,
            'journal_id' => $this->journal_id,
        ];
    }
}
