<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One line of a response's history. Written by the workflow service only; never edited. */
class FormSubmissionEvent extends Model
{
    public const STATUS_CHANGED = 'status_changed';

    public const ASSIGNED = 'assigned';

    public const UNASSIGNED = 'unassigned';

    public const CORRECTED = 'corrected';

    public const NOTE_ADDED = 'note_added';

    public const UPDATED_AT = null;

    protected $fillable = ['submission_id', 'user_id', 'type', 'from_value', 'to_value', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(FormAnswerCorrection::class, 'event_id');
    }
}
