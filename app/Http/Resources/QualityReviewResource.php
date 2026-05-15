<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QualityReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'reviewable_type'          => $this->reviewable_type,
            'reviewable_id'            => $this->reviewable_id,
            'overall_score'            => $this->overall_score,
            'objective_clarity_score'  => $this->objective_clarity_score,
            'content_quality_score'    => $this->content_quality_score,
            'instructor_score'         => $this->instructor_score,
            'organization_score'       => $this->organization_score,
            'time_commitment_score'    => $this->time_commitment_score,
            'completion_score'         => $this->completion_score,
            'output_quality_score'     => $this->output_quality_score,
            'repeatability_score'      => $this->repeatability_score,
            'notes'                    => $this->notes,
            'recommendations'          => $this->recommendations,
            'status'                   => $this->status,
            'reviewed_at'              => $this->reviewed_at,
            'reviewer'                 => $this->whenLoaded('reviewer', fn () => [
                'id'   => $this->reviewer?->id,
                'name' => $this->reviewer?->name,
            ]),
            'created_at'               => $this->created_at,
        ];
    }
}
