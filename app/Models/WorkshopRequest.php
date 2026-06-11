<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopRequest extends Model
{
    protected $fillable = [
        'requester_name',
        'requester_email',
        'requester_phone',
        'requester_department',
        'program_name',
        'speaker_name',
        'speaker_job_title',
        'speaker_photo',
        'topics',
        'categories',
        'target_audience',
        // Legacy date fields (kept for backward compat)
        'proposed_date',
        'proposed_time',
        // Three date/time options
        'proposed_date_1',
        'proposed_time_1',
        'proposed_date_2',
        'proposed_time_2',
        'proposed_date_3',
        'proposed_time_3',
        'location_types',
        'price_type',
        'price_amount',
        'generated_announcement_text',
        'status',
        'current_step',
        'current_department',
        'workflow_status',
    ];

    protected $casts = [
        'categories'      => 'array',
        'location_types'  => 'array',
        'proposed_date'   => 'date',
        'proposed_date_1' => 'date',
        'proposed_date_2' => 'date',
        'proposed_date_3' => 'date',
        'price_amount'    => 'decimal:2',
        'current_step'    => 'integer',
    ];

    public function workflowSteps(): HasMany
    {
        return $this->hasMany(WorkshopRequestWorkflowStep::class)->orderBy('step_number');
    }
}
