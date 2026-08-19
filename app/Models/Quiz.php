<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quiz extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_CLOSED    = 'closed';
    public const STATUS_ARCHIVED  = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_ACTIVE,
        self::STATUS_CLOSED, self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'course_id', 'lesson_id', 'class_group_id', 'title', 'description', 'passing_score', 'status',
        'opens_at', 'closes_at', 'max_attempts', 'counts_toward_final_grade', 'is_final_exam',
        'required', 'weight', 'shuffle_questions', 'shuffle_answers', 'show_result_after_submission',
        'show_correct_answers', 'created_by', 'published_at', 'position',
    ];

    protected $casts = [
        'passing_score' => 'decimal:2',
        'weight' => 'decimal:2',
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'published_at' => 'datetime',
        'max_attempts' => 'integer',
        'position' => 'integer',
        'counts_toward_final_grade' => 'boolean',
        'is_final_exam' => 'boolean',
        'required' => 'boolean',
        'shuffle_questions' => 'boolean',
        'shuffle_answers' => 'boolean',
        'show_result_after_submission' => 'boolean',
        'show_correct_answers' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Effective visibility status for a student, accounting for scheduling.
     * `scheduled` auto-opens into `active` once opens_at passes and auto-
     * closes into `closed` once closes_at passes, without a cron flipping the
     * stored `status` column — the column stays instructor-authored intent,
     * this derives the real-time state a student should see.
     */
    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_DRAFT || $this->status === self::STATUS_ARCHIVED) {
            return $this->status;
        }

        $now = now();
        if ($this->closes_at && $now->greaterThanOrEqualTo($this->closes_at)) {
            return self::STATUS_CLOSED;
        }
        if ($this->status === self::STATUS_CLOSED) {
            return self::STATUS_CLOSED;
        }
        if ($this->opens_at && $now->lessThan($this->opens_at)) {
            return self::STATUS_SCHEDULED;
        }

        return self::STATUS_ACTIVE;
    }

    public function isVisibleToStudents(): bool
    {
        return in_array($this->effectiveStatus(), [self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_CLOSED], true);
    }

    public function acceptsNewAttempts(): bool
    {
        return $this->effectiveStatus() === self::STATUS_ACTIVE;
    }

    /** A student may see this quiz if it's course-wide, or their active class matches. */
    public function scopeVisibleForClassGroups($query, ?int $studentClassGroupId)
    {
        return $query->where(function ($q) use ($studentClassGroupId) {
            $q->whereNull('class_group_id');
            if ($studentClassGroupId) {
                $q->orWhere('class_group_id', $studentClassGroupId);
            }
        });
    }

    public function scopeForCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    public function scopeCountsTowardFinalGrade($query)
    {
        return $query->where('counts_toward_final_grade', true)->where('is_final_exam', false);
    }

    public function scopeFinalExam($query)
    {
        return $query->where('is_final_exam', true);
    }
}
