<?php

namespace App\Models;

use App\Models\Attendance;
use App\Models\Concerns\BelongsToClassGroup;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseSession extends Model
{
    use Auditable, SoftDeletes, BelongsToClassGroup;
    protected $table = 'course_sessions';

    protected $fillable = [
        'course_id',
        'class_group_id',
        'module_id',
        'title',
        'description',
        'start_at',
        'end_at',
        'location_type',
        'location',
        'meeting_link',
        'sort_order',
        'status',
        'is_visible',
    ];

    protected $casts = [
        'start_at'   => 'datetime',
        'end_at'     => 'datetime',
        'is_visible' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'course_session_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class, 'course_session_id')->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CourseAssignment::class, 'course_session_id')->orderBy('sort_order');
    }

    public function linkOpens(): HasMany
    {
        return $this->hasMany(SessionLinkOpen::class, 'session_id')
            ->where('session_type', 'course_session');
    }
}
