<?php

namespace App\Services\Ai\Providers;

class ProviderResponse
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, int|string>  $usage
     */
    public function __construct(
        public string $content,
        public array $raw,
        public array $usage,
    ) {}
}
