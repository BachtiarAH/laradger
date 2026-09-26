<?php

namespace App\Services\Ai\Tools;

abstract class AbstractAiTool implements AiTool
{
    /**
     * Money is always sent and received as a decimal string, never a JSON
     * number, so a float can never silently round an amount.
     *
     * @return array<string, mixed>
     */
    protected static function moneySchema(string $description): array
    {
        return [
            'type' => 'string',
            'pattern' => '^\d+(\.\d{1,2})?$',
            'description' => $description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function uuidSchema(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /**
     * Read a required string argument, trimmed.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function stringArgument(array $arguments, string $key, string $default = ''): string
    {
        $value = $arguments[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, mixed>
     */
    protected function arrayArgument(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
