<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    | Supported: "openai", "anthropic", "mock"
    |
    | "mock"     — returns structured fake responses, safe for dev/testing.
    | "openai"   — requires OPENAI_API_KEY and OPENAI_MODEL.
    | "anthropic"— requires ANTHROPIC_API_KEY.
    */
    'provider' => env('AI_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key'        => env('OPENAI_API_KEY'),
        'model'          => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'embedding_model'=> env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'base_url'       => 'https://api.openai.com/v1',
        'timeout'        => 60,
        'max_tokens'     => (int) env('OPENAI_MAX_TOKENS', 2048),
        'temperature'    => (float) env('OPENAI_TEMPERATURE', 0.7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic
    |--------------------------------------------------------------------------
    */
    'anthropic' => [
        'api_key'   => env('ANTHROPIC_API_KEY'),
        'model'     => env('ANTHROPIC_MODEL', 'claude-3-haiku-20240307'),
        'base_url'  => 'https://api.anthropic.com/v1',
        'timeout'   => 60,
        'max_tokens'=> (int) env('ANTHROPIC_MAX_TOKENS', 2048),
    ],

    /*
    |--------------------------------------------------------------------------
    | General Settings
    |--------------------------------------------------------------------------
    */
    'rate_limit_per_minute' => (int) env('AI_RATE_LIMIT', 20),

    // Queue connection for AI jobs (use 'sync' to process inline for testing)
    'queue'  => env('AI_QUEUE', 'default'),

    // Maximum conversation history messages to include in context
    'context_window' => (int) env('AI_CONTEXT_WINDOW', 20),

    // Enable/disable AI tools (function calling)
    'tools_enabled' => (bool) env('AI_TOOLS_ENABLED', true),

    // Estimated cost per 1k tokens (USD) — used for usage_log cost estimates
    'cost_per_1k_tokens' => [
        'gpt-4.1-mini'     => 0.00015,
        'gpt-4o'           => 0.005,
        'claude-3-haiku'   => 0.00025,
        'text-embedding-3-small' => 0.00002,
    ],
];
