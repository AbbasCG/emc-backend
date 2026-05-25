<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'course_id'     => $this->course_id,
            'module_id'     => $this->module_id,
            'title'         => $this->title,
            'description'   => $this->description,
            'start_at'      => $this->start_at?->toISOString(),
            'end_at'        => $this->end_at?->toISOString(),
            'location_type' => $this->location_type,
            'location'      => $this->location,
            'meeting_link'  => $this->meeting_link,
            'sort_order'    => $this->sort_order,
            'status'        => $this->status,
            'is_visible'    => $this->is_visible,
            'materials'     => CourseMaterialResource::collection($this->whenLoaded('materials')),
            'assignments'   => CourseAssignmentResource::collection($this->whenLoaded('assignments')),
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
