<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseAssignmentResource extends JsonResource
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
            'instructions'      => $this->instructions,
            'deadline'          => $this->deadline?->toISOString(),
            'max_points'        => $this->max_points,
            'submission_type'   => $this->submission_type,
            'is_required'       => $this->is_required,
            'is_visible'        => $this->is_visible,
            'status'            => $this->status,
            'sort_order'        => $this->sort_order,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
