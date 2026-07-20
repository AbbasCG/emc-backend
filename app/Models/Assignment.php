<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClassGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use BelongsToClassGroup;

    public const SCOPE_COURSE = 'course';
    public const SCOPE_CLASS_GROUP = 'class_group';
    public const SCOPE_SESSION = 'session';
    public const SCOPES = [self::SCOPE_COURSE, self::SCOPE_CLASS_GROUP, self::SCOPE_SESSION];

    protected $fillable = [
        'course_id',
        'class_group_id',
        'session_id',
        'scope',
        'workshop_id',
        'course_assignment_id',
        'title',
        'description',
        'due_date',
        'max_score',
        'max_attempts',
        'status',
        'resubmission_allowed',
        'is_visible',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'max_score' => 'decimal:2',
        'max_attempts' => 'integer',
        'resubmission_allowed' => 'boolean',
        'is_visible' => 'boolean',
    ];

    /**
     * `scope` is derived, not independently settable — it must always agree
     * with which FK is actually populated, so it can never drift out of
     * sync with the real ownership of the row.
     */
    protected static function booted(): void
    {
        static::saving(function (Assignment $assignment) {
            $assignment->scope = $assignment->session_id
                ? self::SCOPE_SESSION
                : ($assignment->class_group_id ? self::SCOPE_CLASS_GROUP : self::SCOPE_COURSE);
        });
    }

    public function courseAssignment(): BelongsTo
    {
        return $this->belongsTo(CourseAssignment::class, 'course_assignment_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class, 'workshop_id');
    }

    /** The Ticket-4-canonical live session this assignment is scoped to (not CourseSession). */
    public function session(): BelongsTo
    {
        return $this->belongsTo(LmsSession::class, 'session_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class, 'assignment_id');
    }
}
