<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentSubmissionAttempt extends Model
{
    protected $fillable = [
        'assignment_submission_id', 'attempt_number', 'file_path', 'text_answer',
        'notes', 'score', 'feedback', 'status', 'submitted_at', 'reviewed_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at'  => 'datetime',
        'score'        => 'float',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'assignment_submission_id');
    }
}
