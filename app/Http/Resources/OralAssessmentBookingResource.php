<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OralAssessmentBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'status'              => $this->status,
            'oral_score'          => $this->oral_score,
            'final_level'         => $this->final_level,
            'instructor_notes'    => $this->instructor_notes,
            'pronunciation_score' => $this->pronunciation_score,
            'grammar_score'       => $this->grammar_score,
            'vocabulary_score'    => $this->vocabulary_score,
            'fluency_score'       => $this->fluency_score,
            'comprehension_score' => $this->comprehension_score,
            'confidence_score'    => $this->confidence_score,
            'created_at'      => $this->created_at->toIso8601String(),
            'slot'            => $this->whenLoaded('slot', fn () => [
                'id'        => $this->slot->id,
                'starts_at' => $this->slot->starts_at->toIso8601String(),
                'ends_at'   => $this->slot->ends_at->toIso8601String(),
                'meeting_link' => $this->slot->meeting_link,
            ]),
            'instructor'      => $this->whenLoaded('instructor', fn () => [
                'id'   => $this->instructor->id,
                'name' => $this->instructor->name,
            ]),
            'written_test'    => $this->whenLoaded('attempt', fn () => [
                'score'           => $this->attempt->score,
                'percentage'      => $this->attempt->percentage,
                'estimated_level' => $this->attempt->estimated_level,
            ]),
        ];
    }
}
