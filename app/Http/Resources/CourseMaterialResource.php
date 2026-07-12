<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $downloadUrl = $this->file_path ? url('/api/materials/' . $this->id . '/download') : null;

        return [
            'id'                => $this->id,
            'course_id'         => $this->course_id,
            'module_id'         => $this->module_id,
            'course_session_id' => $this->course_session_id,
            'title'             => $this->title,
            'description'       => $this->description,
            'kind'              => $this->type,
            'type'              => $this->type,
            'file_url'          => $downloadUrl,
            'download_url'      => $downloadUrl,
            'external_url'      => $this->external_url,
            'sort_order'        => $this->sort_order,
            'is_visible'        => $this->is_visible,
            'size_human'        => $this->humanSize(),
            'extension'         => $this->extension,
            'mime_type'         => $this->mime_type,
            'created_at'        => $this->created_at?->toIso8601String(),
            'updated_at'        => $this->updated_at?->toIso8601String(),
        ];
    }
}
