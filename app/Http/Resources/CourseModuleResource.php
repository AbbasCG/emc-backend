<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'sort_order'  => $this->sort_order,
            'status'      => $this->status,
            'lessons'     => LessonResource::collection($this->whenLoaded('lessons')),
            'lessons_count' => $this->whenCounted('lessons'),
        ];
    }
}
