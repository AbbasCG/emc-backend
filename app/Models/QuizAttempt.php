<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED   = 'submitted';
    public const STATUS_GRADED      = 'graded';
    public const STATUS_EXPIRED     = 'expired';

    /** Legacy values written before this migration ('passed'/'failed') count as graded. */
    public const GRADED_STATUSES = [self::STATUS_GRADED, 'passed', 'failed'];

    protected $fillable = [
        'quiz_id', 'user_id', 'attempt_number', 'score', 'max_score', 'passed',
        'answers', 'status', 'started_at', 'submitted_at',
    ];

    protected $casts = [
        'answers'      => 'array',
        'started_at'   => 'datetime',
        'submitted_at' => 'datetime',
        'score'        => 'decimal:2',
        'max_score'    => 'decimal:2',
        'passed'       => 'boolean',
        'attempt_number' => 'integer',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isGraded(): bool
    {
        return in_array($this->status, self::GRADED_STATUSES, true);
    }
}
