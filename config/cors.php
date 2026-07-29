<?php

return [

    /*
     * Paths that CORS headers will be applied to.
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Supports a single URL (FRONTEND_URL) or a comma-separated list of
     * origins via CORS_ALLOWED_ORIGINS.
     *
     * Examples:
     *   CORS_ALLOWED_ORIGINS=http://localhost:5173
     *   CORS_ALLOWED_ORIGINS=http://localhost:5173,https://app.emc.sa
     */
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    // Preflight cache in seconds (24 h in production, 0 in dev)
    'max_age' => (int) env('CORS_MAX_AGE', 0),

    // We use Bearer tokens, not cookies — keep this false
    'supports_credentials' => false,

];
