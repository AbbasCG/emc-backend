<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'description'      => $this->description,
            'content'          => $this->when($request->route('lesson'), $this->content),
            'video_url'        => $this->video_url,
            'duration_minutes' => $this->duration_minutes,
            'sort_order'       => $this->sort_order,
            'status'           => $this->status,
            'quizzes'          => QuizResource::collection($this->whenLoaded('quizzes')),
        ];
    }
}
