<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VolunteerHrProfile extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'user_id', 'full_name', 'email', 'normalized_email', 'phone', 'phone_country_code', 'country', 'country_code', 'city',
        'date_of_birth', 'gender', 'nationality',
        'department_id', 'job_title', 'employment_type', 'join_date', 'availability', 'weekly_hours',
        'skills', 'languages', 'education', 'experience', 'motivation',
        'emergency_contact_name', 'emergency_contact_phone',
        'cv_path', 'cv_original_name', 'cv_mime_type', 'cv_size', 'profile_photo_path', 'linkedin_url', 'portfolio_url',
        'status', 'submitted_at', 'reviewed_at', 'reviewed_by', 'rejection_reason',
        'approved_at', 'approved_by', 'team_profile_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'join_date'     => 'date',
        'submitted_at'  => 'datetime',
        'reviewed_at'   => 'datetime',
        'approved_at'   => 'datetime',
        'weekly_hours'  => 'integer',
    ];

    /** Statuses in workflow order. */
    public const STATUSES = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'archived'];

    /** Statuses that count as "an active claim on this email/user" for duplicate prevention. */
    public const OPEN_STATUSES = ['draft', 'submitted', 'under_review'];

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * `languages` is stored as a JSON-encoded array, but rows created before
     * the multi-select UI hold a plain free-text string — decode safely so
     * old data still renders instead of returning null.
     */
    protected function languages(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function (?string $value) {
                if (! $value) {
                    return [];
                }
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return array_values(array_unique($decoded));
                }
                // Legacy free-text row (pre-dates the JSON format) — best-effort
                // split so it still renders as a list instead of disappearing.
                return array_values(array_filter(array_map('trim', explode(',', $value))));
            },
            set: fn (array|string|null $value) => is_array($value)
                ? json_encode(array_values(array_unique(array_filter($value, fn ($v) => trim((string) $v) !== ''))), JSON_UNESCAPED_UNICODE)
                : $value,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function teamProfile(): BelongsTo
    {
        return $this->belongsTo(TeamProfile::class);
    }
}
