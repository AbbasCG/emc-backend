<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'course_id'         => $this->course_id,
            'title'             => $this->title,
            'description'       => $this->description,
            'sort_order'        => $this->sort_order,
            'status'            => $this->status,
            'is_active'         => $this->is_active,
            'lessons'           => LessonResource::collection($this->whenLoaded('lessons')),
            'lessons_count'     => $this->whenCounted('lessons'),
            'sessions'          => CourseSessionResource::collection($this->whenLoaded('sessions')),
            'sessions_count'    => $this->whenCounted('sessions'),
            'materials'         => CourseMaterialResource::collection($this->whenLoaded('materials')),
            'materials_count'   => $this->whenCounted('materials'),
            'assignments'       => CourseAssignmentResource::collection($this->whenLoaded('courseAssignments')),
            'assignments_count' => $this->whenCounted('courseAssignments'),
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
