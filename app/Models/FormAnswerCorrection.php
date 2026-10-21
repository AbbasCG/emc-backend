<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A correction of one answer: the value before, the value after, who changed it, when and why. Protected: only reviewers read it. */
class FormAnswerCorrection extends Model
{
    public const REASON_MAX = 500;

    public const UPDATED_AT = null;

    protected $fillable = ['submission_id', 'answer_id', 'event_id', 'question_id', 'previous_value', 'new_value', 'corrected_by', 'reason'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(FormAnswer::class, 'answer_id');
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
