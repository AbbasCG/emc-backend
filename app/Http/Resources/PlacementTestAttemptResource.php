<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlacementTestAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'status'          => $this->status,
            'score'           => $this->score,
            'total_questions' => $this->total_questions,
            'percentage'      => $this->percentage,
            'estimated_level' => $this->estimated_level,
            'started_at'      => $this->started_at?->toIso8601String(),
            'submitted_at'    => $this->submitted_at?->toIso8601String(),
        ];
    }
}
