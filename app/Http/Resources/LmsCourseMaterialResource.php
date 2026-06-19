<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LmsCourseMaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'course_id'   => $this->course_id,
            'workshop_id' => $this->workshop_id,
            'session_id'  => $this->session_id,
            'title'       => $this->title,
            'description' => $this->description,
            'file_path'   => $this->file_path,
            'external_url' => $this->external_url,
            'type'        => $this->type,
            'sort_order'  => $this->sort_order,
            'course_title' => $this->whenLoaded('course', fn () => $this->course?->title),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
