<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An internal note on a response. Staff only, append-only, never part of anything a respondent or a public endpoint returns. */
class FormSubmissionNote extends Model
{
    public const MAX_LENGTH = 2000;

    protected $fillable = ['submission_id', 'user_id', 'body'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
