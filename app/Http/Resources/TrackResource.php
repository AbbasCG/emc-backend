<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'outcome_title' => $this->outcome_title,
            'duration' => $this->duration,
            'level' => $this->level,
            'status' => $this->status,
            'image' => $this->image,
            'price' => $this->price,
            'is_free' => $this->is_free,
            'courses_count' => $this->whenCounted('courses'),
            'courses' => CourseResource::collection($this->whenLoaded('courses')),
            'created_at' => $this->created_at,
        ];
    }
}
