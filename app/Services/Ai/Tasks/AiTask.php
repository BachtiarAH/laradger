<?php

namespace App\Services\Ai\Tasks;

use Illuminate\Contracts\Support\Arrayable;

interface AiTask
{
    /**
     * The natural-language statement a call was made against (for recording).
     *
     * @param  array<string, mixed>  $context
     */
    public function statement(array $context): ?string;

    /**
     * The full prompt sent to the provider (for recording).
     *
     * @param  array<string, mixed>  $context
     */
    public function prompt(array $context): string;

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array{role: string, content: string}>
     */
    public function messages(array $context): array;

    /**
     * @return array<string, mixed>
     */
    public function options(): array;

    /**
     * Interpret the provider's raw content into a task value object (or
     * recordable array) and attach the call record id.
     *
     * @return Arrayable|array<string, mixed>
     */
    public function interpret(string $content, string $recordId): Arrayable|array;
}
