<?php

namespace App\Services\AssignmentUploads;

use LogicException;

/**
 * Read-only view of config/assignment_uploads.php. The single place the rest of the code
 * asks "what is allowed?" so no limit is ever hardcoded elsewhere.
 */
class AssignmentUploadPolicy
{
    private const MB = 1048576;

    /** Canonical (server-decided) MIME type per extension. The browser's Content-Type is never used. */
    public const MIME_BY_EXTENSION = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv'  => 'text/csv',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip'  => 'application/zip',
        'rar'  => 'application/vnd.rar',
        '7z'   => 'application/x-7z-compressed',
    ];

    /**
     * Misconfiguration that would otherwise surface as a confusing failure on the first student submission. Checked by
     * `php artisan assignments:check-upload-limits` (and logged once at boot by AppServiceProvider).
     *
     * @return list<string>
     */
    public function configErrors(): array
    {
        $errors = [];

        if ((int) config('assignment_uploads.max_files', 0) < 1) {
            $errors[] = 'max_files must be at least 1 (ASSIGNMENT_UPLOAD_MAX_FILES).';
        }

        // Zero or negative values would silently disable a guard (or reject every file), so they are errors.
        foreach ([
            'max_total_mb'                 => 'ASSIGNMENT_UPLOAD_MAX_TOTAL_MB',
            'zip.max_entries'              => 'ASSIGNMENT_UPLOAD_ZIP_MAX_ENTRIES',
            'zip.max_ratio'                => 'ASSIGNMENT_UPLOAD_ZIP_MAX_RATIO',
            'zip.max_uncompressed_mb'      => 'ASSIGNMENT_UPLOAD_ZIP_MAX_UNCOMPRESSED_MB',
        ] as $key => $env) {
            if ((float) config('assignment_uploads.'.$key, 0) <= 0) {
                $errors[] = "{$key} must be greater than 0 ({$env}).";
            }
        }
        foreach (['multipart_overhead_mb', 'zip.ratio_floor_mb'] as $key) {
            if ((float) config('assignment_uploads.'.$key, 0) < 0) {
                $errors[] = "{$key} must not be negative.";
            }
        }

        try {
            $groups = $this->groups();
        } catch (LogicException $e) {
            return [...$errors, $e->getMessage()];
        }

        foreach ($groups as $key => $group) {
            if ($group['max_bytes'] < 1) {
                $errors[] = "group [{$key}] has a per-file limit below 1 byte.";
            }
        }

        $largest = $groups ? max(array_map(fn ($g) => $g['max_bytes'], $groups)) : 0;
        if ($this->maxTotalBytes() < $largest) {
            $errors[] = 'max_total_mb ('.self::formatBytesAr($this->maxTotalBytes()).') is smaller than the largest per-file limit ('
                .self::formatBytesAr($largest).'): the largest allowed file could never be uploaded.';
        }

        return $errors;
    }

    public function maxFiles(): int
    {
        return max(1, (int) config('assignment_uploads.max_files', 5));
    }

    public function maxTotalBytes(): int
    {
        return (int) round((float) config('assignment_uploads.max_total_mb', 25) * self::MB);
    }

    public function overheadBytes(): int
    {
        return (int) round((float) config('assignment_uploads.multipart_overhead_mb', 3) * self::MB);
    }

    /** @return array<string, array{label_ar:string, extensions:list<string>, max_bytes:int}> keyed by group key */
    public function groups(): array
    {
        $out = [];
        foreach ((array) config('assignment_uploads.groups', []) as $key => $group) {
            $extensions = array_values(array_map('strtolower', (array) ($group['extensions'] ?? [])));
            foreach ($extensions as $ext) {
                if (! isset(self::MIME_BY_EXTENSION[$ext])) {
                    throw new LogicException("assignment_uploads: extension [{$ext}] has no content verifier; remove it from config or implement it in UploadedFileInspector.");
                }
            }
            $out[$key] = [
                'label_ar'  => (string) ($group['label_ar'] ?? $key),
                'extensions' => $extensions,
                'max_bytes' => (int) round((float) ($group['max_mb'] ?? 0) * self::MB),
            ];
        }

        return $out;
    }

    /** @return array{key:string,label_ar:string,extensions:list<string>,max_bytes:int}|null */
    public function groupForExtension(string $extension): ?array
    {
        $extension = strtolower($extension);
        foreach ($this->groups() as $key => $group) {
            if (in_array($extension, $group['extensions'], true)) {
                return ['key' => $key] + $group;
            }
        }

        return null;
    }

    public function isBlockedExtension(string $extension): bool
    {
        return in_array(strtolower($extension), array_map('strtolower', (array) config('assignment_uploads.blocked_extensions', [])), true);
    }

    /** @return list<string> */
    public function allExtensions(): array
    {
        $all = [];
        foreach ($this->groups() as $group) {
            array_push($all, ...$group['extensions']);
        }

        return array_values(array_unique($all));
    }

    public function maxPerFileBytes(): int
    {
        return (int) max(array_map(fn ($g) => $g['max_bytes'], $this->groups()) ?: [0]);
    }

    public function canonicalMime(string $extension): string
    {
        return self::MIME_BY_EXTENSION[strtolower($extension)] ?? 'application/octet-stream';
    }

    /** `accept` attribute value for the file input, e.g. ".jpg,.jpeg,.png". */
    public function acceptAttribute(): string
    {
        return implode(',', array_map(fn ($e) => '.'.$e, $this->allExtensions()));
    }

    /** Payload of GET /api/student/assignment-upload-rules. */
    public function rules(): array
    {
        return [
            'max_files'       => $this->maxFiles(),
            'max_total_bytes' => $this->maxTotalBytes(),
            'groups'          => array_values(array_map(
                fn ($key, $g) => [
                    'key'        => $key,
                    'label_ar'   => $g['label_ar'],
                    'extensions' => $g['extensions'],
                    'max_bytes'  => $g['max_bytes'],
                ],
                array_keys($this->groups()),
                $this->groups(),
            )),
            'accept'          => $this->acceptAttribute(),
        ];
    }

    /** Arabic rendering of a byte limit, "10 ميجابايت" / "2.5 ميجابايت" / "512 كيلوبايت". */
    public static function formatBytesAr(int $bytes): string
    {
        if ($bytes >= self::MB) {
            $mb = $bytes / self::MB;
            $text = floor($mb) == $mb ? (string) (int) $mb : rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.');

            return $text.' ميجابايت';
        }

        return max(1, (int) round($bytes / 1024)).' كيلوبايت';
    }

    /** Parse php.ini shorthand ("8M", "2G", "512K", "-1", "0") into bytes. -1 / 0 are returned as-is. */
    public static function iniToBytes(string|int|false|null $value): int
    {
        if ($value === false || $value === null || $value === '') {
            return 0;
        }
        $value = trim((string) $value);
        if (! preg_match('/^(-?\d+)\s*([kmg]?)b?$/i', $value, $m)) {
            return (int) $value;
        }
        $n = (int) $m[1];

        return match (strtolower($m[2])) {
            'k' => $n * 1024,
            'm' => $n * self::MB,
            'g' => $n * self::MB * 1024,
            default => $n,
        };
    }
}
