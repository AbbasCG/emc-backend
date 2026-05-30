<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'course_id'         => $this->course_id,
            'module_id'         => $this->module_id,
            'course_session_id' => $this->course_session_id,
            'title'             => $this->title,
            'description'       => $this->description,
            'type'              => $this->type,
            'download_url'      => $this->file_path ? url('/api/materials/' . $this->id . '/download') : null,
            'external_url'      => $this->external_url,
            'sort_order'        => $this->sort_order,
            'is_visible'        => $this->is_visible,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
