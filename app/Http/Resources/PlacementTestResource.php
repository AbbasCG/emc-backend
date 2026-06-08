<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlacementTestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'description'      => $this->description,
            'language'         => $this->language,
            'duration_minutes' => $this->duration_minutes,
            'total_questions'  => $this->total_questions,
            'questions'        => $this->whenLoaded('questions', function () {
                return $this->questions->map(fn ($q) => [
                    'id'            => $q->id,
                    'order'         => $q->order,
                    'question_text' => $q->question_text,
                    // Array of {key, text} — correct_option intentionally omitted
                    'options'       => collect($q->optionsArray())
                        ->map(fn ($text, $key) => ['key' => strtoupper($key), 'text' => $text])
                        ->values(),
                ]);
            }),
        ];
    }
}
