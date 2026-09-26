<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Tools\ToolCall;

class ProviderResponse
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, int|string>  $usage
     * @param  array<int, ToolCall>  $toolCalls
     */
    public function __construct(
        public string $content,
        public array $raw,
        public array $usage,
        public array $toolCalls = [],
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
