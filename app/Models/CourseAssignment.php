<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseAssignment extends Model
{
    use SoftDeletes;
    protected $table = 'course_assignments';

    protected $fillable = [
        'course_id',
        'module_id',
        'course_session_id',
        'title',
        'description',
        'instructions',
        'deadline',
        'max_points',
        'submission_type',
        'is_required',
        'resubmission_allowed',
        'is_visible',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'deadline'    => 'datetime',
        'is_required' => 'boolean',
        'is_visible'  => 'boolean',
        'resubmission_allowed' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    public function courseSession(): BelongsTo
    {
        return $this->belongsTo(CourseSession::class, 'course_session_id');
    }
}
