<?php

/*
|--------------------------------------------------------------------------
| Assignment submission uploads — the ONE place the limits live
|--------------------------------------------------------------------------
|
| Nothing else in the codebase may hardcode these numbers. The validator, the
| storage service, the rules endpoint (GET /api/student/assignment-upload-rules,
| which the frontend reads instead of duplicating the numbers), the oversize
| middleware and `php artisan assignments:check-upload-limits` all read from
| here. Every value can be overridden per environment with ASSIGNMENT_UPLOAD_*.
|
| Sizes are written in MEGABYTES (1 MB = 1024 * 1024 bytes) so the env values
| stay readable; use `AssignmentUploadPolicy` to get bytes.
|
| IMPORTANT: PHP and the web server cap uploads BEFORE Laravel runs. These
| numbers are only reachable when upload_max_filesize >= the largest per-file
| limit, post_max_size >= max_total + multipart overhead and Nginx
| client_max_body_size >= post_max_size. See docs/operations/ASSIGNMENT_UPLOADS.md.
*/

// A blank value (`ASSIGNMENT_UPLOAD_X=`) must mean "use the default", never 0.
$env = static function (string $key, int|float $default): int|float {
    $value = env($key);

    return ($value === null || trim((string) $value) === '') ? $default : (float) $value + 0;
};

return [

    // Maximum number of files in one submission.
    'max_files' => (int) $env('ASSIGNMENT_UPLOAD_MAX_FILES', 5),

    // Maximum combined size of all files in one submission (MB).
    'max_total_mb' => (float) $env('ASSIGNMENT_UPLOAD_MAX_TOTAL_MB', 25),

    // Multipart framing (boundaries, field headers, the text answer) added on top of the
    // file bytes. Used only to derive the PHP/Nginx values the checker asks for.
    'multipart_overhead_mb' => (float) $env('ASSIGNMENT_UPLOAD_OVERHEAD_MB', 3),

    /*
     | Allowed types, grouped. `extensions` is the closed allow-list: every extension has a
     | content signature check in App\Services\AssignmentUploads\UploadedFileInspector,
     | so adding an extension here WITHOUT teaching the inspector about it is reported by
     | AssignmentUploadPolicy::configErrors() (shown by `php artisan assignments:check-upload-limits`).
     */
    'groups' => [
        'images' => [
            'label_ar'   => 'الصور',
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_mb'     => (float) $env('ASSIGNMENT_UPLOAD_IMAGE_MAX_MB', 5),
        ],
        'documents' => [
            'label_ar'   => 'المستندات',
            'extensions' => ['pdf', 'doc', 'docx'],
            'max_mb'     => (float) $env('ASSIGNMENT_UPLOAD_DOCUMENT_MAX_MB', 10),
        ],
        'text' => [
            'label_ar'   => 'الملفات النصية',
            'extensions' => ['txt'],
            'max_mb'     => (float) $env('ASSIGNMENT_UPLOAD_TEXT_MAX_MB', 2),
        ],
        'sheets' => [
            'label_ar'   => 'جداول البيانات',
            'extensions' => ['xls', 'xlsx', 'csv'],
            'max_mb'     => (float) $env('ASSIGNMENT_UPLOAD_SHEET_MAX_MB', 10),
        ],
        'presentations' => [
            'label_ar'   => 'العروض التقديمية',
            'extensions' => ['ppt', 'pptx'],
            'max_mb'     => (float) $env('ASSIGNMENT_UPLOAD_PRESENTATION_MAX_MB', 10),
        ],
        'archives' => [
            'label_ar'   => 'الملفات المضغوطة',
            'extensions' => ['zip', 'rar', '7z'],
            'max_mb'     => (float) $env('ASSIGNMENT_UPLOAD_ARCHIVE_MAX_MB', 10),
        ],
    ],

    /*
     | Extensions that are refused outright (and reported as "unsupported type"). The allow-list
     | above is what actually admits files; this list only exists so that a deliberate attempt to
     | upload something executable/active is logged and can never be enabled by a config typo.
     */
    'blocked_extensions' => [
        'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'scr', 'pif', 'cpl', 'sh', 'bash', 'zsh', 'csh', 'ksh',
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'inc',
        'js', 'mjs', 'cjs', 'jsx', 'ts', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'psm1', 'psd1',
        'jar', 'class', 'war', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'hta',
        'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb', 'lnk', 'reg', 'apk', 'app', 'dmg', 'deb', 'rpm',
        'docm', 'xlsm', 'pptm', 'dotm', 'xltm', 'potm', 'htaccess',
    ],

    /*
     | ZIP safety (archives are NEVER extracted: entries are only listed from the central
     | directory). rar / 7z are validated by magic bytes only, they cannot be listed without
     | an extraction library, so their contents are not inspected.
     */
    'zip' => [
        // Reject archives with more entries than this.
        'max_entries' => (int) $env('ASSIGNMENT_UPLOAD_ZIP_MAX_ENTRIES', 10000),
        // Reject when the DECLARED uncompressed total exceeds this (zip-bomb guard).
        'max_uncompressed_mb' => (float) $env('ASSIGNMENT_UPLOAD_ZIP_MAX_UNCOMPRESSED_MB', 500),
        // Reject when declared-uncompressed / compressed-size exceeds this ratio, but only once
        // the declared total is above `ratio_floor_mb` (small, highly compressible files are fine).
        'max_ratio' => (int) $env('ASSIGNMENT_UPLOAD_ZIP_MAX_RATIO', 200),
        'ratio_floor_mb' => (float) $env('ASSIGNMENT_UPLOAD_ZIP_RATIO_FLOOR_MB', 20),
    ],

    /*
     | Extensions that may never appear INSIDE an Office (OOXML) package, e.g. an embedded program under
     | word/embeddings. A different, narrower list than `blocked_extensions` on purpose: that one would also match
     | the .xml parts every package contains.
     */
    'embedded_blocked_extensions' => [
        'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'scr', 'pif', 'cpl', 'sh', 'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'wsh',
        'jar', 'hta', 'lnk', 'reg', 'js', 'mjs', 'php', 'phar', 'apk',
    ],

    // Stored on the private disk (never reachable by URL), under <directory>/<assignment_id>/<user_id>/<uuid>.<ext>.
    'disk' => 'private',
    'directory' => 'assignment-submissions',

    // Longest original file name kept for DISPLAY only.
    'display_name_max' => 180,
];
