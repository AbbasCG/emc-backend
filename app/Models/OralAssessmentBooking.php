<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OralAssessmentBooking extends Model
{
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
        ];
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

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
