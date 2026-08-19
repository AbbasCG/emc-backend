<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExpertApplication extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'uuid', 'status',
        'full_name', 'country', 'city', 'whatsapp_number', 'email', 'linkedin_url',
        'primary_specialty', 'academic_qualification', 'current_employer', 'job_title', 'years_of_experience',
        'expertise_fields', 'expertise_level', 'tools_technologies',
        'contribution_roles',
        'trainer_fields', 'trainer_program_types', 'trainer_target_audiences', 'trainer_delivery_mode', 'trainer_has_experience', 'trainer_years_experience', 'trainer_previous_courses', 'trainer_content_readiness',
        'expert_specialty_areas', 'expert_contribution_types', 'expert_achievements', 'expert_has_certifications', 'expert_certifications_details',
        'consultant_fields', 'consultant_types', 'consultant_previous_clients', 'consultant_target_clients',
        'events_types', 'events_contribution_areas', 'events_previous_events',
        'mentor_fields', 'mentor_target_audiences', 'mentor_session_types',
        'evaluator_fields', 'evaluator_types',
        'participant_project_types', 'participant_contribution_method',
        'availability_times', 'availability_level', 'collaboration_preferences',
        'bio', 'unique_value',
        'agree_to_contact', 'agree_to_store_data',
        'admin_notes', 'reviewed_by', 'reviewed_at',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'expertise_fields' => 'array',
        'contribution_roles' => 'array',
        'trainer_fields' => 'array',
        'trainer_program_types' => 'array',
        'trainer_target_audiences' => 'array',
        'trainer_delivery_mode' => 'array',
        'trainer_has_experience' => 'boolean',
        'expert_contribution_types' => 'array',
        'expert_has_certifications' => 'boolean',
        'consultant_fields' => 'array',
        'consultant_types' => 'array',
        'consultant_target_clients' => 'array',
        'events_types' => 'array',
        'events_contribution_areas' => 'array',
        'mentor_fields' => 'array',
        'mentor_target_audiences' => 'array',
        'mentor_session_types' => 'array',
        'evaluator_fields' => 'array',
        'evaluator_types' => 'array',
        'participant_project_types' => 'array',
        'availability_times' => 'array',
        'collaboration_preferences' => 'array',
        'agree_to_contact' => 'boolean',
        'agree_to_store_data' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
