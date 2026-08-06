<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClassGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use BelongsToClassGroup;

    protected $fillable = [
        'session_id',
        'course_session_id',
        'class_group_id',
        'user_id',
        'status',
        'checked_in_at',
        'notes',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
    ];

    /** LMS session (live class) — may be null when course_session_id is set */
    public function session(): BelongsTo
    {
        return $this->belongsTo(LmsSession::class, 'session_id');
    }

    /** Course content session — may be null when session_id (LmsSession) is set */
    public function courseSession(): BelongsTo
    {
        return $this->belongsTo(CourseSession::class, 'course_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
