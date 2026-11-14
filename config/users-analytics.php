<?php

/*
|--------------------------------------------------------------------------
| Users Management hub — analytics (read-only aggregates)
|--------------------------------------------------------------------------
| Served by App\Http\Controllers\Admin\UsersAnalyticsController under
| /api/admin/users-analytics/*. Every figure is an aggregate computed on the
| server; no endpoint returns emails, phone numbers or IP addresses.
*/
return [
    // Reporting day boundaries and the day/hour distributions use this zone (the database stores UTC).
    'timezone' => env('USERS_ANALYTICS_TIMEZONE', env('BOOKING_TIMEZONE', 'Europe/Amsterdam')),

    // Longest range a request may ask for, and the range used when none is given.
    'max_range_days'     => 366,
    'default_range_days' => 30,

    // Aggregates are cached briefly (they hold no per-viewer data). 0 disables the cache.
    'cache_ttl' => (int) env('USERS_ANALYTICS_CACHE_TTL', 300),

    // Requests per minute per authenticated user (named limiter `users_analytics`).
    'rate_limit_per_minute' => (int) env('USERS_ANALYTICS_RATE_LIMIT', 30),

    // Distribution widgets show this many entries and fold the rest into "other".
    'top_limit' => 12,

    // «غير نشط» cut-offs, in days since the last login.
    'inactivity_days' => [30, 60, 90],
];
