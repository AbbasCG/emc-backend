<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmbassadorApplicationFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\AmbassadorApplicationFile $file */
        $file = $this->resource;

        $applicationId = $file->ambassador_application_id;

        return [
            'id'                 => $file->id,
            'category'           => $file->category,
            'category_label'     => $file->category_label,
            'original_name'      => $file->original_name,
            'mime_type'          => $file->mime_type,
            'extension'          => $file->extension,
            'original_size'      => $file->original_size,
            'optimized_size'     => $file->optimized_size,
            'compression_ratio'  => $file->compression_ratio,
            'file_size_humans'   => $file->file_size_for_humans,
            'processing_status'  => $file->processing_status,
            'processing_error'   => $file->processing_error,
            'uploaded_at'        => $file->uploaded_at?->toIso8601String(),
            'processed_at'       => $file->processed_at?->toIso8601String(),
            'has_thumbnail'      => (bool) $file->thumbnail_path,
            // Previewable by mime type — the /preview endpoint serves the original file
            // inline regardless of whether a separate optimized preview variant exists.
            'has_preview'        => str_starts_with($file->mime_type, 'image/') || $file->mime_type === 'application/pdf',

            // Safe URLs — no disk/path/stored_name exposed
            'preview_url'        => route('admin.ambassador-applications.files.preview',  [$applicationId, $file->id]),
            'download_url'       => route('admin.ambassador-applications.files.download', [$applicationId, $file->id]),
            'thumbnail_url'      => $file->thumbnail_path
                ? route('admin.ambassador-applications.files.thumbnail', [$applicationId, $file->id])
                : null,
        ];
    }
}
