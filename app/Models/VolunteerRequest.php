<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VolunteerRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'country',
        'city',
        'gender',
        'desired_department',
        'experience_level',
        'skills',
        'availability',
        'motivation',
        'previous_experience',
        'cv_file_path',
        'portfolio_url',
        'notes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
        'converted_to_member_at',
        'converted_member_id',
    ];

    protected $casts = [
        'reviewed_at'           => 'datetime',
        'converted_to_member_at' => 'datetime',
    ];

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

    public static function allowedStatuses(): array
    {
        return ['pending', 'reviewing', 'reviewed', 'accepted', 'rejected', 'contacted'];
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
