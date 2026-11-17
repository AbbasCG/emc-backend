<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VolunteerRequest extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'opportunity_id',
        'full_name',
        'email',
        'phone',
        'country',
        'city',
        'gender',
        'birth_date',
        'nationality',
        'education_level',
        'current_status',
        'job_title',
        'specialization',
        'volunteer_days_availability',
        'volunteer_days',
        'preferred_time_slot',
        'daily_hours',
        'in_person_capable',
        'remote_capable',
        'has_previous_volunteering',
        'volunteering_organizations',
        'volunteering_duration',
        'past_volunteer_tasks',
        'personal_skills',
        'skill_level_rating',
        'technical_tools',
        'value_add_contributions',
        'portfolio_link_status',
        'motivation_ratings',
        'commitment_ratings',
        'situational_responses',
        'expected_duration',
        'commitment_three_months',
        'reasons_for_applying',
        'expected_gains',
        'secondary_departments',
        'primary_value_department',
        'department_skills',
        'preferred_work_environment',
        'quit_reasons',
        'suggestions',
        'assessment_data',
        'desired_department',
        'experience_level',
        'skills',
        'availability',
        'motivation',
        'previous_experience',
        'cv_file_path',
        'portfolio_url',
        'languages',
        'linkedin_url',
        'professional_bio',
        'professional_profile_consent',
        'notes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
        'accepted_at',
        'accepted_by',
        'converted_to_member_at',
        'converted_member_id',
        'converted_by',
    ];

    protected $casts = [
        'birth_date'             => 'date',
        'has_previous_volunteering' => 'boolean',
        'volunteer_days'         => 'array',
        'past_volunteer_tasks'   => 'array',
        'personal_skills'        => 'array',
        'technical_tools'        => 'array',
        'motivation_ratings'     => 'array',
        'commitment_ratings'     => 'array',
        'situational_responses'  => 'array',
        'reasons_for_applying'   => 'array',
        'expected_gains'         => 'array',
        'secondary_departments'  => 'array',
        'department_skills'      => 'array',
        'quit_reasons'           => 'array',
        'languages'              => 'array',
        'professional_profile_consent' => 'boolean',
        'assessment_data'        => 'array',
        'reviewed_at'            => 'datetime',
        'accepted_at'            => 'datetime',
        'converted_to_member_at' => 'datetime',
    ];

    /** The published opportunity this application was submitted for, if any. */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(VolunteerOpportunity::class, 'opportunity_id');
    }

    public function convertedMember(): BelongsTo
    {
        return $this->belongsTo(TeamProfile::class, 'converted_member_id');
    }

    public function isConverted(): bool
    {
        return $this->converted_to_member_at !== null;
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getCvFileUrlAttribute(): ?string
    {
        // Direct public storage URLs are intentionally not exposed.
        // Admin API returns signed download URLs via VolunteerRequestResource.
        return null;
    }

    public function cvStoragePath(): ?string
    {
        $path = $this->cv_file_path;

        return $path && trim($path) !== '' ? $path : null;
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public static function allowedStatuses(): array
    {
        return ['pending', 'reviewing', 'reviewed', 'accepted', 'rejected', 'contacted', 'converted_to_member'];
    }

    /** Normalize legacy/alternate status values before persistence. */
    public static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'reviewing' => 'reviewing',
            'reviewed'  => 'reviewed',
            default     => $status,
        };
    }
}
