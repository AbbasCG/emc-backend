<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingReport extends Model
{
    protected $fillable = [
        // Department ops report (Operations\MeetingReportController)
        'department_id', 'meeting_id', 'title', 'meeting_date',
        'achieved', 'planned', 'needs', 'needs_reason', 'decisions', 'notes',
        'created_by',

        // Per-meeting quick report (Meeting::report() / Admin\MeetingController::storeReport)
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
        'meeting_date' => 'date',
        'absentees' => 'array',
        'team_commitment_score' => 'integer',
        'kpi_percentage' => 'integer',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(MeetingReportRating::class);
    }
}
