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
    | Each provider is an HTTP-backed driver resolved through Laravel's Http
    | client. Add a new provider by implementing the
    | App\Services\Ai\Contracts\JournalDraftProvider contract and registering
    | it here.
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

    ],

];
