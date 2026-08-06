<?php

namespace App\Services\Material;

use App\Enums\MaterialType;
use App\Jobs\OptimizeMaterialImageJob;
use App\Jobs\ScanMaterialForVirusJob;
use App\Models\CourseMaterial;
use App\Models\MaterialAuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MaterialUploadService
{
    public function __construct(private ZipInspectionService $zipInspector) {}

    /**
     * Securely store an uploaded file and create the CourseMaterial record.
     *
     * @param  array<string, mixed>  $attributes  Validated non-file fields (course_id, title, type, …)
     */
    public function store(UploadedFile $file, array $attributes, User $uploader): CourseMaterial
    {
        $type = MaterialType::tryFrom($attributes['type'] ?? '') ?? MaterialType::Other;

        // ── 1. Validate MIME type against allowed list ────────────────────────
        $this->assertMimeAllowed($file, $type);

        // ── 2. Validate extension ─────────────────────────────────────────────
        $this->assertExtensionAllowed($file, $type);

        // ── 3. ZIP security inspection ────────────────────────────────────────
        if ($type->isZip()) {
            $this->zipInspector->inspect($file);
        }

        // ── 4. Compute SHA-256 ────────────────────────────────────────────────
        $sha256 = hash_file('sha256', $file->getRealPath());

        // ── 5. Generate UUID storage path ─────────────────────────────────────
        $extension     = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $uuid          = Str::uuid()->toString();
        $storedName    = "{$uuid}.{$extension}";
        $disk          = config('materials.disk', 'private');

        // ── 6. Store file on private disk ────────────────────────────────────
        $storedPath = $file->storeAs('materials', $storedName, $disk);

        if (! $storedPath) {
            throw new \RuntimeException('فشل حفظ الملف على القرص.');
        }

        // ── 7. Persist record ─────────────────────────────────────────────────
        $material = CourseMaterial::create(array_merge($attributes, [
            'file_path'         => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename'   => $storedName,
            'mime_type'         => $file->getMimeType(),
            'extension'         => $extension,
            'size'              => $file->getSize(),
            'uploaded_by'       => $uploader->id,
            'disk'              => $disk,
            'sha256'            => $sha256,
            'processing_status' => 'ready',
            'download_count'    => 0,
        ]));

        // ── 8. Audit log ──────────────────────────────────────────────────────
        MaterialAuditLog::record($material, 'uploaded', $uploader->id, metadata: [
            'size'      => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'sha256'    => $sha256,
        ]);

        // ── 9. Background jobs ────────────────────────────────────────────────
        if ($type->isImage()) {
            $material->update(['processing_status' => 'processing']);
            OptimizeMaterialImageJob::dispatch($material)->onQueue('media');
        }

        if (config('materials.antivirus.enabled')) {
            ScanMaterialForVirusJob::dispatch($material)->onQueue('security');
        }

        return $material;
    }

    /**
     * Replace the file on an existing CourseMaterial record.
     */
    public function replace(UploadedFile $file, CourseMaterial $material, User $uploader): CourseMaterial
    {
        $old = $material->only(['file_path', 'disk', 'thumbnail_path', 'medium_path']);

        $updated = $this->store($file, [
            'course_id'        => $material->course_id,
            'module_id'        => $material->module_id,
            'session_id'       => $material->session_id,
            'course_session_id'=> $material->course_session_id,
            'title'            => $material->title,
            'description'      => $material->description,
            'type'             => $material->type?->value ?? 'other',
            'sort_order'       => $material->sort_order,
            'is_visible'       => $material->is_visible,
        ], $uploader);

        // Remove old physical files after writing the new record
        $this->deletePhysical(
            $old['disk'] ?? 'private',
            array_filter([$old['file_path'], $old['thumbnail_path'], $old['medium_path']]),
        );

        // Soft-delete the old record (audit trail)
        $material->delete();

        return $updated;
    }

    /**
     * Physically delete all files associated with a material.
     */
    public function deleteFiles(CourseMaterial $material): void
    {
        $this->deletePhysical(
            $material->disk ?? 'private',
            array_filter([
                $material->file_path,
                $material->thumbnail_path,
                $material->medium_path,
            ]),
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function assertMimeAllowed(UploadedFile $file, MaterialType $type): void
    {
        $allowed = $type->allowedMimes();
        if (empty($allowed)) {
            return; // no restriction for this type
        }

        $actual = $file->getMimeType();

        // programming_project ZIP: browsers sometimes send application/octet-stream
        if ($type->isZip() && in_array($actual, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            // Additional magic-byte check for ZIP (PK header)
            $fh = fopen($file->getRealPath(), 'rb');
            $magic = fread($fh, 4);
            fclose($fh);
            if (substr($magic, 0, 2) !== 'PK') {
                throw ValidationException::withMessages([
                    'file' => ['الملف ليس ZIP صالحاً (توقيع خاطئ).'],
                ]);
            }
            return;
        }

        if (! in_array($actual, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => ["نوع الملف الفعلي ({$actual}) غير مسموح به لهذا النوع من المواد."],
            ]);
        }
    }

    private function assertExtensionAllowed(UploadedFile $file, MaterialType $type): void
    {
        $allowed = $type->allowedExtensions();
        if (empty($allowed)) {
            return;
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => ["الامتداد .{$ext} غير مسموح به لهذا النوع من المواد. المسموح به: " . implode(', ', $allowed)],
            ]);
        }
    }

    private function deletePhysical(string $disk, array $paths): void
    {
        $storage = Storage::disk($disk);
        foreach ($paths as $path) {
            try {
                if ($storage->exists($path)) {
                    $storage->delete($path);
                }
            } catch (\Throwable) {
                // Non-fatal — log elsewhere if needed
            }
        }
    }
}
