<?php

namespace App\Services\Forms;

use App\Rules\FormAttachmentFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where public-form attachments live and how they are read back.
 *
 * New files go to the PRIVATE disk under a server-generated name (the client's name is only ever display text),
 * and are served by an authorized endpoint, never by a public URL. Files stored before this change sit on the
 * public disk; they are still readable through the same endpoint (private first, then the legacy location) so
 * nothing is lost, and `forms:copy-legacy-uploads` can copy them to the private disk.
 */
class FormAttachmentService
{
    public const DISK = 'private';

    public const LEGACY_DISK = 'public';

    public const DIRECTORY = 'form-uploads';

    /** @return array{path: string, name: string} */
    public function store(UploadedFile $file, int $formId): array
    {
        $ext = FormAttachmentFile::extensionOf($file);
        if ($ext === null) {
            throw new \RuntimeException('Unsupported form attachment type.');
        }

        $filename = Str::uuid()->toString().'.'.$ext;
        $written = Storage::disk(self::DISK)->putFileAs(self::DIRECTORY.'/'.$formId, $file, $filename);
        if ($written === false) {
            throw new \RuntimeException('Failed to write the form attachment to the private disk.');
        }

        return ['path' => self::DIRECTORY.'/'.$formId.'/'.$filename, 'name' => self::displayName($file->getClientOriginalName())];
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * Stored paths are always `form-uploads/...` with plain segments. Anything else (a parent-directory hop, an
     * absolute path, another directory) is treated as missing rather than read, whatever the storage layer would
     * make of it.
     */
    public static function isSafePath(string $path): bool
    {
        return str_starts_with($path, self::DIRECTORY.'/')
            && preg_match('#^[A-Za-z0-9._/-]+$#', $path) === 1
            && ! str_contains($path, '..')
            && ! str_contains($path, '//');
    }

    /** The disk that holds this stored path, or null when the file is gone (or the path is not one we would have written). */
    public function diskFor(string $path): ?string
    {
        if (! self::isSafePath($path)) {
            return null;
        }

        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    /** The client's file name reduced to something safe to show and to send in a header. */
    public static function displayName(string $original): string
    {
        $base = basename(str_replace(chr(92), '/', $original));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/:*?"<>|]+/u', '_', $base));
        $name = mb_substr($name, 0, 150);

        return $name === '' ? 'attachment' : $name;
    }
}
