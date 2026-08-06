<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstructorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'title'      => $this->title,
            'bio'        => $this->bio,
            'image'      => $this->image,
            // Courses only included when the relationship was eager-loaded
            'courses'    => CourseResource::collection($this->whenLoaded('courses')),
            'created_at' => $this->created_at,
        ];
    }
}
