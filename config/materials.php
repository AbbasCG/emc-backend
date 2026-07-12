<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    | All uploaded files land on this disk. Must be a private disk (never public/).
    */
    'disk' => env('MATERIALS_DISK', 'private'),

    /*
    |--------------------------------------------------------------------------
    | Upload Rate Limit
    |--------------------------------------------------------------------------
    | Maximum uploads per user per hour.
    */
    'rate_limit' => (int) env('MATERIALS_RATE_LIMIT', 20),

    /*
    |--------------------------------------------------------------------------
    | File Size Limits (in kilobytes)
    |--------------------------------------------------------------------------
    */
    'max_size' => [
        'pdf'                 => (int) env('MATERIAL_MAX_PDF_KB',      102400),  // 100 MB
        'image'               => (int) env('MATERIAL_MAX_IMAGE_KB',     20480),  // 20 MB
        'video'               => (int) env('MATERIAL_MAX_VIDEO_KB',   2097152),  // 2 GB
        'audio'               => (int) env('MATERIAL_MAX_AUDIO_KB',    204800),  // 200 MB
        'document'            => (int) env('MATERIAL_MAX_DOC_KB',       51200),  // 50 MB
        'spreadsheet'         => (int) env('MATERIAL_MAX_XLS_KB',       51200),  // 50 MB
        'presentation'        => (int) env('MATERIAL_MAX_PPT_KB',      102400),  // 100 MB
        'programming_project' => (int) env('MATERIAL_MAX_ZIP_KB',      524288),  // 512 MB
        'other'               => (int) env('MATERIAL_MAX_OTHER_KB',     51200),  // 50 MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types per Material Type
    |--------------------------------------------------------------------------
    */
    'allowed_mimes' => [
        'pdf' => [
            'application/pdf',
        ],
        'image' => [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'image/svg+xml', 'image/avif', 'image/bmp', 'image/tiff', 'image/heic',
        ],
        'video' => [
            'video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska',
        ],
        'audio' => [
            'audio/mpeg', 'audio/wav', 'audio/mp4', 'audio/aac', 'audio/ogg',
            'audio/x-m4a',
        ],
        'document' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
        ],
        'spreadsheet' => [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
            'text/csv',
        ],
        'presentation' => [
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation',
        ],
        'programming_project' => [
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream', // some clients send this for ZIP
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Extensions per Material Type
    |--------------------------------------------------------------------------
    */
    'allowed_extensions' => [
        'pdf'                 => ['pdf'],
        'image'               => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif', 'bmp', 'tiff', 'heic'],
        'video'               => ['mp4', 'webm', 'mov', 'mkv'],
        'audio'               => ['mp3', 'wav', 'm4a', 'aac', 'ogg'],
        'document'            => ['pdf', 'doc', 'docx', 'odt'],
        'spreadsheet'         => ['xls', 'xlsx', 'ods', 'csv'],
        'presentation'        => ['ppt', 'pptx', 'odp'],
        'programming_project' => ['zip'],
    ],

    /*
    |--------------------------------------------------------------------------
    | URL-Only Material Types (no file upload)
    |--------------------------------------------------------------------------
    */
    'url_only_types' => [
        'github_repository',
        'google_colab',
        'youtube_video',
        'vimeo_video',
        'external_resource',
        'link',
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Optimization
    |--------------------------------------------------------------------------
    */
    'image' => [
        'thumbnail_width' => (int) env('MATERIAL_THUMB_WIDTH',  150),
        'medium_width'    => (int) env('MATERIAL_MEDIUM_WIDTH', 800),
        'original_width'  => (int) env('MATERIAL_ORIG_WIDTH', 1920),
        'thumb_quality'   => (int) env('MATERIAL_THUMB_QUALITY',  80),
        'medium_quality'  => (int) env('MATERIAL_MEDIUM_QUALITY', 85),
        'orig_quality'    => (int) env('MATERIAL_ORIG_QUALITY',   90),
    ],

    /*
    |--------------------------------------------------------------------------
    | ZIP Inspection
    |--------------------------------------------------------------------------
    */
    'zip' => [
        'inspect'                => (bool) env('MATERIAL_ZIP_INSPECT', true),
        'max_ratio'              => (int) env('MATERIAL_ZIP_MAX_RATIO', 100),      // compression ratio limit
        'max_uncompressed_bytes' => (int) env('MATERIAL_ZIP_MAX_BYTES', 2 * 1024 * 1024 * 1024), // 2 GB
        'dangerous_extensions'   => [
            'exe', 'bat', 'cmd', 'com', 'dll', 'scr', 'php', 'asp', 'aspx',
            'jsp', 'cgi', 'sh', 'ps1', 'msi', 'vbs', 'pif', 'lnk', 'reg',
            'hta', 'wsf', 'wsh', 'app', 'deb', 'rpm',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Antivirus (ClamAV)
    |--------------------------------------------------------------------------
    */
    'antivirus' => [
        'enabled' => (bool) env('MATERIALS_ANTIVIRUS_ENABLED', false),
        'socket'  => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
        'host'    => env('CLAMAV_HOST', '127.0.0.1'),
        'port'    => (int) env('CLAMAV_PORT', 3310),
    ],

];
