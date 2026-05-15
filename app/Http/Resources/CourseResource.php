<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'track_id'         => $this->track_id,
            'title'            => $this->title,
            'slug'             => $this->slug,
            'description'      => $this->description,
            'short_description' => $this->short_description,
            'type'             => $this->type,
            'price'            => $this->price,
            'location'         => $this->location,
            'is_online'        => $this->is_online,
            'start_date'       => $this->start_date,
            'end_date'         => $this->end_date,
            'capacity'         => $this->capacity,
            'status'           => $this->status,
            'duration'         => $this->duration,
            'training_hours'   => $this->training_hours,
            'target_audience'  => $this->target_audience,
            'language'         => $this->language,
            'level'            => $this->level,
            'study_days'       => $this->study_days,
            'study_time'       => $this->study_time,
            'certificate'      => $this->certificate,
            'learning_outcomes' => $this->learning_outcomes,
            'course_image'     => $this->course_image,
            'instructor_id'    => $this->instructor_id,
            // Nested relationships — only present when eager-loaded
            'track'            => TrackResource::make($this->whenLoaded('track')),
            'instructor'       => InstructorResource::make($this->whenLoaded('instructor')),
            'features'         => CourseFeatureResource::collection($this->whenLoaded('features')),
            'created_at'       => $this->created_at,
        ];
    }
}
