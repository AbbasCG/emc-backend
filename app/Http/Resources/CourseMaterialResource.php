<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $downloadUrl = $this->file_path ? url('/api/materials/' . $this->id . '/download') : null;

        $scope = 'course';
        if ($this->class_group_id) $scope = 'class';
        elseif ($this->lesson_id) $scope = 'lesson';
        elseif ($this->session_id || $this->course_session_id) $scope = 'session';
        elseif ($this->module_id) $scope = 'module';

        return [
            'id'                => $this->id,
            'course_id'         => $this->course_id,
            'class_group_id'    => $this->class_group_id,
            'module_id'         => $this->module_id,
            'lesson_id'         => $this->lesson_id,
            'session_id'        => $this->session_id,
            'course_session_id' => $this->course_session_id,
            'scope'             => $scope,
            'title'             => $this->title,
            'description'       => $this->description,
            'kind'              => $this->type,
            'type'              => $this->type,
            'original_filename' => $this->original_filename,
            'file_url'          => $downloadUrl,
            'download_url'      => $downloadUrl,
            'preview_url'       => $this->mime_type === 'application/pdf' ? url('/api/materials/' . $this->id . '/preview') : null,
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
