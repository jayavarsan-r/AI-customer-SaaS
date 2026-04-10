<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LLM Provider Configuration
    |--------------------------------------------------------------------------
    */
    'provider'      => env('LLM_PROVIDER', 'anthropic'),
    'default_model' => env('LLM_DEFAULT_MODEL', 'claude-sonnet-4-6'),
    'max_tokens'    => (int) env('LLM_MAX_TOKENS', 4096),
    'temperature'   => (float) env('LLM_TEMPERATURE', 0.3),
    'timeout'       => (int) env('LLM_TIMEOUT', 60),
    'max_retries'   => (int) env('LLM_MAX_RETRIES', 3),
    'retry_delay'   => (int) env('LLM_RETRY_DELAY', 1000), // ms

    'cache_enabled' => (bool) env('LLM_CACHE_ENABLED', true),
    'cache_ttl'     => (int) env('LLM_CACHE_TTL', 3600), // seconds

    'anthropic' => [
        'api_key'    => env('ANTHROPIC_API_KEY'),
        'base_url'   => env('ANTHROPIC_API_URL', 'https://api.anthropic.com'),
        'version'    => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    ],

    'openai' => [
        'api_key'      => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Aliases (human-friendly → real model IDs)
    |--------------------------------------------------------------------------
    */
    'models' => [
        'fast'    => 'claude-haiku-4-5-20251001',
        'default' => 'claude-sonnet-4-6',
        'premium' => 'claude-opus-4-6',
    ],
];
