<?php

namespace App\Http\Resources;

use App\Enums\MaterialType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LmsCourseMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof MaterialType ? $this->type : MaterialType::tryFrom((string) $this->type);

        $hasFile   = ! empty($this->file_path);
        $isUrlOnly = $type?->isUrlOnly() ?? false;

        return [
            // ── Identity ──────────────────────────────────────────────────────
            'id'          => $this->id,
            'course_id'   => $this->course_id,
            'module_id'   => $this->module_id,
            'lesson_id'   => $this->lesson_id,
            'session_id'  => $this->session_id,
            'course_session_id' => $this->course_session_id,
            'workshop_id' => $this->workshop_id,

            // ── Content ───────────────────────────────────────────────────────
            'title'        => $this->title,
            'description'  => $this->description,
            'sort_order'   => $this->sort_order,
            'is_visible'   => $this->is_visible,

            // ── Type & presentation ───────────────────────────────────────────
            'type'         => $type?->value,
            'type_label'   => $type?->label(),
            'type_icon'    => $type?->icon(),
            'type_color'   => $type?->color(),

            // ── External URL (for URL-only types) ─────────────────────────────
            'external_url' => $this->external_url,
            'is_url_only'  => $isUrlOnly,

            // ── File metadata (never exposes storage path) ─────────────────────
            'has_file'          => $hasFile,
            'original_filename' => $this->original_filename,
            'extension'         => $this->extension,
            'mime_type'         => $this->mime_type,
            'size'              => $this->size,
            'size_human'        => $this->humanSize(),

            // ── Processing status ─────────────────────────────────────────────
            'processing_status' => $this->processing_status ?? 'ready',
            'image_optimized'   => $this->image_optimized ?? false,

            // ── Stats ─────────────────────────────────────────────────────────
            'download_count'      => $this->download_count ?? 0,
            'last_downloaded_at'  => $this->last_downloaded_at?->toIso8601String(),

            // ── Secure delivery URLs (path never exposed) ─────────────────────
            'download_url'   => $hasFile ? route('materials.download', $this->id) : null,
            'thumbnail_url'  => $this->thumbnail_path ? route('materials.thumbnail', $this->id) : null,
            'medium_url'     => $this->medium_path    ? route('materials.medium', $this->id)    : null,
            'preview_url'    => $hasFile && $this->isPreviewable() ? route('materials.download', $this->id) : null,
            'can_preview'    => $hasFile && $this->isPreviewable(),

            // ── Uploader ──────────────────────────────────────────────────────
            'uploaded_by'  => $this->whenLoaded('uploader', fn () => [
                'id'   => $this->uploader?->id,
                'name' => $this->uploader?->name,
            ]),
            'course_title' => $this->whenLoaded('course', fn () => $this->course?->title),

            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
        ];
    }

    private function humanSize(): ?string
    {
        if (! $this->size) {
            return null;
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->size;
        $i     = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    private function isPreviewable(): bool
    {
        $previewableMimes = [
            'application/pdf',
            'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
            'video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg',
        ];
        $mime = $this->mime_type;
        if ($mime && in_array($mime, $previewableMimes, true)) {
            return true;
        }
        // Legacy fallback: check extension
        $ext = strtolower($this->extension ?? pathinfo((string) $this->file_path, PATHINFO_EXTENSION));
        return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'mp4', 'webm', 'mp3', 'ogg'], true);
    }
}
