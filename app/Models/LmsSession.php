<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClassGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LMS live/scheduled class session (table: sessions).
 */
class LmsSession extends Model
{
    use BelongsToClassGroup;

    protected $table = 'lms_sessions';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LIVE      = 'live';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_MISSED    = 'missed';
    public const STATUS_ARCHIVED  = 'archived';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_LIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_MISSED,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'workshop_id',
        'course_id',
        'class_group_id',
        'instructor_id',
        'title',
        'description',
        'session_date',
        'start_time',
        'end_time',
        'meeting_url',
        'meeting_provider',
        'recording_url',
        'status',
        'location',
    ];

    public const MEETING_PROVIDERS = ['none', 'custom', 'google_meet', 'zoom', 'microsoft_teams'];

    protected $casts = [
        'session_date' => 'date',
    ];

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class, 'workshop_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class, 'instructor_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'session_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class, 'session_id');
    }
}
