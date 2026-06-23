<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class LmsCourseMaterialResource extends JsonResource
{
    /** Extensions that browsers can preview inline. */
    private const PREVIEWABLE = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'txt'];

    /** Material type values that are treated as previewable even without a recognised extension. */
    private const PREVIEWABLE_TYPES = ['pdf', 'image', 'slide', 'document'];

    public function toArray(Request $request): array
    {
        $hasFile  = ! empty($this->file_path);
        $fileName = $hasFile ? basename($this->file_path) : null;
        $fileExt  = $fileName ? strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) : null;

        // A hashed filename has no extension — also check the `type` DB column so
        // materials uploaded as type="pdf" are still flagged as previewable.
        $canPreview = ($fileExt !== null && in_array($fileExt, self::PREVIEWABLE, true))
                   || in_array($this->type, self::PREVIEWABLE_TYPES, true);

        // Best-effort file size — probe disks without throwing.
        $fileSize = null;
        if ($hasFile) {
            try {
                if (Storage::disk('local')->exists($this->file_path)) {
                    $fileSize = Storage::disk('local')->size($this->file_path);
                } elseif (Storage::disk('public')->exists($this->file_path)) {
                    $fileSize = Storage::disk('public')->size($this->file_path);
                }
            } catch (\Throwable) {
                // Non-fatal — leave null
            }
        }

        return [
            'id'          => $this->id,
            'course_id'   => $this->course_id,
            'workshop_id' => $this->workshop_id,
            'session_id'  => $this->session_id,
            'title'       => $this->title,
            'description' => $this->description,
            'external_url' => $this->external_url,
            'type'        => $this->type,
            'sort_order'  => $this->sort_order,
            'is_visible'  => $this->is_visible,
            'course_title' => $this->whenLoaded('course', fn () => $this->course?->title),

            // ── File metadata (raw file_path NOT exposed) ─────────────────────
            'has_file'     => $hasFile,
            'file_name'    => $fileName,
            'file_type'    => $fileExt,
            'can_preview'  => $canPreview,
            'file_size'    => $fileSize,
            'preview_url'  => $hasFile ? "/api/admin/lms/materials/{$this->id}/file"     : null,
            'download_url' => $hasFile ? "/api/admin/lms/materials/{$this->id}/download" : null,

            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
