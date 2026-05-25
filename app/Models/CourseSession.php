<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseSession extends Model
{
    use SoftDeletes;
    protected $table = 'course_sessions';

    protected $fillable = [
        'course_id',
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

    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class, 'course_session_id')->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CourseAssignment::class, 'course_session_id')->orderBy('sort_order');
    }
}
