<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OralAssessmentBooking extends Model
{
    // ─── Explicit status workflow (Phase 7) ────────────────────────────────
    public const STATUS_BOOKED                  = 'booked';
    public const STATUS_CONFIRMED                = 'confirmed';
    public const STATUS_RESCHEDULE_REQUESTED     = 'reschedule_requested';
    public const STATUS_RESCHEDULED               = 'rescheduled';
    public const STATUS_CANCELLED_BY_STUDENT     = 'cancelled_by_student';
    public const STATUS_CANCELLED_BY_INSTRUCTOR  = 'cancelled_by_instructor';
    public const STATUS_COMPLETED                = 'completed';
    public const STATUS_NO_SHOW                  = 'no_show';

    public const ACTIVE_STATUSES = [
        self::STATUS_BOOKED,
        self::STATUS_CONFIRMED,
        self::STATUS_RESCHEDULE_REQUESTED,
    ];

    protected $fillable = [
        'user_id',
        'course_id',
        'instructor_id',
        'placement_test_attempt_id',
        'slot_id',
        'status',
        'oral_score',
        'final_level',
        'instructor_notes',
        'strengths',
        'weaknesses',
        'recommendations',
        'evaluated_at',
        'previous_oral_score',
        'previous_final_level',
        'edited_by',
        'edited_at',
        'pronunciation_score',
        'grammar_score',
        'vocabulary_score',
        'fluency_score',
        'comprehension_score',
        'confidence_score',
    ];

    protected function casts(): array
    {
        return [
            'oral_score'          => 'integer',
            'pronunciation_score' => 'integer',
            'grammar_score'       => 'integer',
            'vocabulary_score'    => 'integer',
            'fluency_score'       => 'integer',
            'comprehension_score' => 'integer',
            'confidence_score'    => 'integer',
            'previous_oral_score' => 'integer',
            'evaluated_at'        => 'datetime',
            'edited_at'           => 'datetime',
        ];
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PlacementTestAttempt::class, 'placement_test_attempt_id');
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(OralAssessmentSlot::class, 'slot_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OralAssessmentBookingStatusHistory::class, 'oral_assessment_booking_id')
            ->latest('changed_at');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** Human-friendly booking reference, e.g. "PI-202607-0019". Derived, not stored. */
    public function reference(): string
    {
        return 'PI-' . $this->created_at->format('Ym') . '-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Transition status and record an audit-trail row in one call. Does not
     * persist the model itself beyond the `status` column — callers are
     * responsible for any related side effects (slot updates, notifications).
     */
    public function changeStatus(string $toStatus, ?int $changedBy, ?string $reason = null): void
    {
        $from = $this->status;

        $this->update(['status' => $toStatus]);

        $this->statusHistory()->create([
            'changed_by'  => $changedBy,
            'from_status' => $from,
            'to_status'   => $toStatus,
            'reason'      => $reason,
            'changed_at'  => now(),
        ]);
    }
}
