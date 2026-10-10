<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'meeting_id'               => $this->meeting_id,
            'absentees'                => $this->absentees,
            'team_commitment_score'    => $this->team_commitment_score,
            'team_commitment_notes'    => $this->team_commitment_notes,
            'completed_tasks_summary'  => $this->completed_tasks_summary ?? $this->achieved,
            'kpi_percentage'           => $this->kpi_percentage,
            'top_interactive_member'   => $this->top_interactive_member,
            'challenges'               => $this->challenges ?? $this->needs_reason,
            'executive_requests'       => $this->executive_requests ?? $this->needs,
            'next_month_goals'         => $this->next_month_goals ?? $this->planned,
            'improvement_suggestions'  => $this->improvement_suggestions,
            'achieved'                 => $this->achieved,
            'planned'                  => $this->planned,
            'needs'                    => $this->needs,
            'needs_reason'             => $this->needs_reason,
            'notes'                    => $this->notes,
            'created_at'               => $this->created_at,
        ];
    }
}
