<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Centralised secure file upload handler.
 *
 * All upload rules are defined per-module so that MIME types, size limits,
 * and storage visibility are consistent and can be audited in one place.
 */
class FileUploadService
{
    /**
     * Module definitions.
     * Keys map to the $module argument of store().
     *
     * Each entry: [disk, max_kb, allowed_mimes[], path_prefix]
     */
    private const MODULES = [
        'course_materials' => [
            'disk'    => 'private',
            'max_kb'  => 51_200, // 50 MB
            'mimes'   => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'mp4', 'zip'],
            'path'    => 'courses/materials',
        ],
        'assignment_submissions' => [
            'disk'    => 'private',
            'max_kb'  => 20_480, // 20 MB
            'mimes'   => ['pdf', 'doc', 'docx', 'zip', 'png', 'jpg', 'jpeg'],
            'path'    => 'assignments/submissions',
        ],
        'documents' => [
            'disk'    => 'private',
            'max_kb'  => 20_480, // 20 MB
            'mimes'   => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'],
            'path'    => 'documents',
        ],
        'knowledge_attachments' => [
            'disk'    => 'private',
            'max_kb'  => 10_240, // 10 MB
            'mimes'   => ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'gif'],
            'path'    => 'knowledge/attachments',
        ],
        'marketing_assets' => [
            'disk'    => 'public',
            'max_kb'  => 5_120, // 5 MB
            'mimes'   => ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'],
            'path'    => 'marketing',
        ],
        'certificates' => [
            'disk'    => 'private',
            'max_kb'  => 5_120, // 5 MB
            'mimes'   => ['pdf', 'png'],
            'path'    => 'certificates',
        ],
        'partner_documents' => [
            'disk'    => 'private',
            'max_kb'  => 20_480, // 20 MB
            'mimes'   => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
            'path'    => 'partners/documents',
        ],
        'avatars' => [
            'disk'    => 'public',
            'max_kb'  => 2_048, // 2 MB
            'mimes'   => ['png', 'jpg', 'jpeg', 'webp'],
            'path'    => 'avatars',
        ],
    ];

    /**
     * Validate and store an uploaded file securely.
     *
     * @param  UploadedFile  $file      The incoming upload.
     * @param  string        $module    One of the MODULES keys above.
     * @param  string|null   $subPath   Optional sub-folder within the module path.
     * @return array{path: string, disk: string, url: string|null, original_name: string, size: int, mime: string}
     *
     * @throws ValidationException  When the file violates the module rules.
     */
    public function store(UploadedFile $file, string $module, ?string $subPath = null): array
    {
        $config = $this->moduleConfig($module);

        $this->validate($file, $config);

        $safeName = $this->safeName($file);
        $directory = $subPath
            ? $config['path'] . '/' . trim($subPath, '/')
            : $config['path'];

        $path = Storage::disk($config['disk'])->putFileAs($directory, $file, $safeName);

        return [
            'path'          => $path,
            'disk'          => $config['disk'],
            'url'           => $config['disk'] === 'public' ? Storage::disk('public')->url($path) : null,
            'original_name' => $file->getClientOriginalName(),
            'size'          => $file->getSize(),
            'mime'          => $file->getMimeType(),
        ];
    }

    /**
     * Delete a previously stored file.
     */
    public function delete(string $path, string $module): bool
    {
        $config = $this->moduleConfig($module);

        return Storage::disk($config['disk'])->delete($path);
    }

    /**
     * Get a temporary signed URL for private files.
     */
    public function temporaryUrl(string $path, string $module, int $expiryMinutes = 30): string
    {
        $config = $this->moduleConfig($module);

        return Storage::disk($config['disk'])->temporaryUrl(
            $path,
            now()->addMinutes($expiryMinutes)
        );
    }

    // -----------------------------------------------------------------------

    private function moduleConfig(string $module): array
    {
        if (! isset(self::MODULES[$module])) {
            throw new \InvalidArgumentException("Unknown upload module [{$module}].");
        }

        return self::MODULES[$module];
    }

    private function validate(UploadedFile $file, array $config): void
    {
        $errors = [];

        // Size check (UploadedFile::getSize() returns bytes)
        $maxBytes = $config['max_kb'] * 1024;
        if ($file->getSize() > $maxBytes) {
            $maxMb = $config['max_kb'] / 1024;
            $errors['file'] = ["File size exceeds the maximum allowed {$maxMb} MB."];
        }

        // MIME / extension check
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $config['mimes'], true)) {
            $allowed = implode(', ', $config['mimes']);
            $errors['file'] = array_merge(
                $errors['file'] ?? [],
                ["File type .{$extension} is not allowed. Allowed: {$allowed}."]
            );
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Generate a safe, unique filename — no path traversal, no special chars.
     */
    private function safeName(UploadedFile $file): string
    {
        $ext  = strtolower($file->getClientOriginalExtension());
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        // Slug-ify the original name so it stays human-readable
        $slug = Str::slug($base);

        if (empty($slug)) {
            $slug = 'file';
        }

        return Str::limit($slug, 60, '') . '_' . Str::random(12) . '.' . $ext;
    }
}
