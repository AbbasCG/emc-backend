<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingReport extends Model
{
    protected $fillable = [
        'meeting_id',
        'absentees',
        'team_commitment_score',
        'team_commitment_notes',
        'completed_tasks_summary',
        'kpi_percentage',
        'top_interactive_member',
        'challenges',
        'executive_requests',
        'next_month_goals',
        'improvement_suggestions',
    ];

    protected $casts = [
        'absentees' => 'array',
        'team_commitment_score' => 'integer',
        'kpi_percentage' => 'integer',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
