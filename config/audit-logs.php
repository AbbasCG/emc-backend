<?php

return [
    /*
     |--------------------------------------------------------------------------
     | Retention periods (in months)
     |--------------------------------------------------------------------------
     | After this period logs are moved to audit_logs_archive, never deleted.
     | Override with AUDIT_RETENTION_MONTHS for the general default.
     */
    'default_retention_months' => (int) env('AUDIT_RETENTION_MONTHS', 24),

    'retention' => [
        'financial'        => 84, // 7 years — compliance
        'security'         => 36, // 3 years
        'roles_permissions' => 60, // 5 years
        'general'          => 24, // 2 years
        'technical'        => 3,  // 3 months (GET/polling noise if ever logged)
    ],

    /*
     | Actions mapped to their retention category.
     | Any action not listed falls into 'general'.
     */
    'action_categories' => [
        'financial'        => ['PAYMENT', 'REFUND', 'INVOICE', 'FINANCIAL_ACTION'],
        'security'         => ['LOGIN', 'LOGOUT', 'LOGIN_FAILED', 'DENIED', 'PERMISSION_CHANGE', 'SECURITY_ACTION'],
        'roles_permissions' => ['ROLE_CHANGE', 'role_changed'],
        'technical'        => ['GET_LIST', 'PING', 'HEALTH_CHECK'],
    ],

    /*
     | Archive table name (logs are moved here, never deleted outright).
     */
    'archive_table' => 'audit_logs_archive',

    /*
     | Chunk size for archive command — prevents huge memory spikes.
     */
    'archive_chunk' => (int) env('AUDIT_ARCHIVE_CHUNK', 1000),
];
