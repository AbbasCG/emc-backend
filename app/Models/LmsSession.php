<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LMS live/scheduled class session (table: sessions).
 */
class LmsSession extends Model
{
    protected $table = 'lms_sessions';

    protected $fillable = [
        'workshop_id',
        'course_id',
        'instructor_id',
        'title',
        'description',
        'session_date',
        'start_time',
        'end_time',
        'meeting_url',
        'recording_url',
        'status',
        'location',
    ];

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
