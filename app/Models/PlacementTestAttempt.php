<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PlacementTestAttempt extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'placement_test_id',
        'score',
        'total_questions',
        'percentage',
        'estimated_level',
        'started_at',
        'submitted_at',
        'answers',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'answers'         => 'array',
            'started_at'      => 'datetime',
            'submitted_at'    => 'datetime',
            'percentage'      => 'float',
            'score'           => 'integer',
            'total_questions' => 'integer',
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

    public function placementTest(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class);
    }

    public function oralBooking(): HasOne
    {
        return $this->hasOne(OralAssessmentBooking::class, 'placement_test_attempt_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }
}
