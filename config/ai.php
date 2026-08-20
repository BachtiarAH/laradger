<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | The provider used to generate draft journal entries from natural-language
    | statements. Swap drivers at any time by changing this value; no code
    | changes are required.
    |
    */

    'default' => env('AI_DEFAULT_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Each provider is an HTTP-backed driver resolved through the AI gateway
    | (App\Services\Ai\Gateway\AiGateway). Add a new provider by implementing
    | App\Services\Ai\Providers\Contracts\AiProvider and registering it in the
    | gateway's PROVIDERS map.
    |
    */

    'providers' => [

        'openai' => [
            'base_uri' => env('AI_OPENAI_BASE_URI', 'https://api.openai.com'),
            'api_key' => env('AI_OPENAI_API_KEY'),
            'model' => env('AI_OPENAI_MODEL', 'gpt-4o-mini'),
            'timeout' => (int) env('AI_OPENAI_TIMEOUT', 30),
        ],

        'anthropic' => [
            'base_uri' => env('AI_ANTHROPIC_BASE_URI', 'https://api.anthropic.com'),
            'api_key' => env('AI_ANTHROPIC_API_KEY'),
            'model' => env('AI_ANTHROPIC_MODEL', 'claude-3-5-haiku-20241022'),
            'version' => env('AI_ANTHROPIC_VERSION', '2023-06-01'),
            'timeout' => (int) env('AI_ANTHROPIC_TIMEOUT', 30),
        ],

        'openai_compatible' => [
            'base_uri' => env('AI_COMPATIBLE_BASE_URI', 'https://api.openai.com'),
            'api_key' => env('AI_COMPATIBLE_API_KEY'),
            'model' => env('AI_COMPATIBLE_MODEL', 'gpt-4o-mini'),
            'endpoint' => env('AI_COMPATIBLE_ENDPOINT', '/v1/chat/completions'),
            'timeout' => (int) env('AI_COMPATIBLE_TIMEOUT', 30),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | System Prompt
    |--------------------------------------------------------------------------
    |
    | The system prompt sent to the provider when generating a draft journal.
    | Leave empty to use the built-in default prompt. The placeholders
    | :accounts and :statement are replaced at call time.
    |
    */

    'prompt' => env('AI_PROMPT', ''),

    /*
    |--------------------------------------------------------------------------
    | Call Recording
    |--------------------------------------------------------------------------
    |
    | Records every AI call (prompt, response, token usage, latency) plus the
    | confirmation event when a draft is saved as a journal. The driver is
    | swappable: the default "file" driver appends one JSON line per event to
    | the configured path. Swap to another backend by implementing the
    | App\Services\Ai\Contracts\AiCallRecorder contract.
    |
    */

    'recording' => [
        'enabled' => (bool) env('AI_RECORDING_ENABLED', true),
        'driver' => env('AI_RECORDING_DRIVER', 'file'),
        'file' => [
            'path' => env('AI_RECORDING_PATH', 'logs/ai-calls.jsonl'),
        ],
    ],

];
