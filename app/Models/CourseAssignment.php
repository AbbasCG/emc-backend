<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseAssignment extends Model
{
    use Auditable, SoftDeletes;
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

    /** The bridge Assignment row created for submission compatibility. */
    public function lmsAssignment(): HasOne
    {
        return $this->hasOne(Assignment::class, 'course_assignment_id');
    }

    /** Student submissions via the bridge Assignment row. */
    public function submissions(): HasManyThrough
    {
        return $this->hasManyThrough(
            AssignmentSubmission::class,
            Assignment::class,
            'course_assignment_id', // FK on assignments → course_assignments.id
            'assignment_id',        // FK on assignment_submissions → assignments.id
            'id',                   // local key on course_assignments
            'id'                    // local key on assignments
        );
    }
}
