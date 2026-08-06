<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'title'         => $this->title,
            'description'   => $this->description,
            'passing_score' => $this->passing_score,
            'status'        => $this->status,
            'questions'     => QuizQuestionResource::collection($this->whenLoaded('questions')),
            'questions_count' => $this->whenCounted('questions'),
        ];
    }
}
