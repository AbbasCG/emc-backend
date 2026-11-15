<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentSubmission extends Model
{
    protected $fillable = [
        'assignment_id',
        'user_id',
        'file_path',
        'text_answer',
        'notes',
        'score',
        'feedback',
        'submitted_at',
        'reviewed_at',
        'resubmitted_at',
        'status',
    ];

    protected $casts = [
        'submitted_at'   => 'datetime',
        'reviewed_at'    => 'datetime',
        'resubmitted_at' => 'datetime',
        'score'          => 'decimal:2',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The CURRENT attachment set (attempt_number 0). History sets are read through the history service. */
    public function currentFiles(): HasMany
    {
        return $this->hasMany(AssignmentSubmissionFile::class, 'submission_id')
            ->where('attempt_number', 0)
            ->orderBy('id');
    }
}
