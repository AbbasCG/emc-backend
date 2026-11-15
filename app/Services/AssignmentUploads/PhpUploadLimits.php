<?php

namespace App\Services\AssignmentUploads;

/**
 * The PHP runtime's own upload ceilings, read from ini. A tiny class on purpose: the oversize middleware and the
 * `assignments:check-upload-limits` command both depend on it, and tests swap it for fixed values (ini values such as
 * post_max_size cannot be changed at runtime).
 *
 * NOTE: `php artisan ...` runs the CLI SAPI, which can load a DIFFERENT php.ini than PHP-FPM / Apache serve web
 * requests with. The command warns about this; verify the FPM values separately (see docs/operations/ASSIGNMENT_UPLOADS.md).
 */
class PhpUploadLimits
{
    /** Bytes; 0 means "no limit" (post_max_size = 0). */
    public function postMaxBytes(): int
    {
        return max(0, AssignmentUploadPolicy::iniToBytes(ini_get('post_max_size')));
    }

    public function uploadMaxFilesizeBytes(): int
    {
        return max(0, AssignmentUploadPolicy::iniToBytes(ini_get('upload_max_filesize')));
    }

    public function maxFileUploads(): int
    {
        return (int) ini_get('max_file_uploads');
    }

    /** PHP extensions the upload pipeline needs: zip (archive/OOXML listing), fileinfo (Laravel's file handling), mbstring (names, UTF-16), dom + libxml (relationship parts). GD is not needed by this path. */
    public const REQUIRED_EXTENSIONS = ['zip', 'fileinfo', 'mbstring', 'dom', 'libxml'];

    public function extensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    public function sapi(): string
    {
        return PHP_SAPI;
    }

    public function iniFile(): string
    {
        return (string) (php_ini_loaded_file() ?: '(none)');
    }

    public function raw(string $key): string
    {
        return (string) ini_get($key);
    }
}
