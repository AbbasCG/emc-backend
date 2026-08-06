<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AmbassadorApplication extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'uuid', 'status', 'is_draft', 'draft_token',
        'full_name', 'email', 'mobile_phone', 'nationality', 'gender', 'date_of_birth', 'country', 'city',
        'university_name', 'university_type', 'faculty', 'major', 'study_year', 'student_number',
        'student_status', 'expected_graduation_date',
        'motivation_why', 'contribution_what', 'expected_gain', 'biggest_achievement', 'biggest_challenge',
        'has_volunteer_experience', 'has_event_organization_experience',
        'has_teaching_experience', 'has_student_club_involvement',
        'social_instagram', 'social_linkedin', 'social_facebook', 'social_tiktok', 'social_github', 'website_url',
        'followers_count',
        'interests', 'skills',
        'has_led_team', 'has_organized_events', 'events_attendees_count', 'has_represented_university',
        'weekly_hours_available', 'can_attend_university_events', 'can_represent_emc_outside',
        'can_travel', 'owns_laptop', 'has_stable_internet', 'can_attend_weekly_meetings',
        'volunteer_experience_types', 'certifications',
        'cv_path', 'student_id_path', 'photo_path', 'certificate_paths',
        'admin_notes', 'interview_scheduled_at',
        'reviewed_by', 'reviewed_at', 'status_updated_by', 'status_updated_at',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'date_of_birth'              => 'date',
        'expected_graduation_date'   => 'date',
        'interests'                  => 'array',
        'skills'                     => 'array',
        'volunteer_experience_types' => 'array',
        'certifications'             => 'array',
        'certificate_paths'          => 'array',
        'has_volunteer_experience'            => 'boolean',
        'has_event_organization_experience'   => 'boolean',
        'has_teaching_experience'             => 'boolean',
        'has_student_club_involvement'        => 'boolean',
        'has_led_team'                        => 'boolean',
        'has_organized_events'                => 'boolean',
        'has_represented_university'          => 'boolean',
        'can_attend_university_events'        => 'boolean',
        'can_represent_emc_outside'           => 'boolean',
        'can_travel'                          => 'boolean',
        'owns_laptop'                         => 'boolean',
        'has_stable_internet'                 => 'boolean',
        'can_attend_weekly_meetings'          => 'boolean',
        'is_draft'                            => 'boolean',
        'reviewed_at'                         => 'datetime',
        'status_updated_at'                   => 'datetime',
        'interview_scheduled_at'              => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_updated_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(AmbassadorApplicationNote::class)->latest();
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(AmbassadorApplicationStatusHistory::class)->latest('changed_at');
    }

    public function files(): HasMany
    {
        return $this->hasMany(AmbassadorApplicationFile::class)->latest();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function allowedStatuses(): array
    {
        return ['new', 'under_review', 'interview_scheduled', 'approved', 'rejected', 'waitlisted', 'cancelled'];
    }

    public function cvStoragePath(): ?string
    {
        $path = $this->cv_path;
        return $path && trim($path) !== '' ? $path : null;
    }
}
