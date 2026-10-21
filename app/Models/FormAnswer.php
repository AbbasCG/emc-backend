<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormAnswer extends Model
{
    protected $fillable = ['submission_id', 'question_id', 'value', 'file_path', 'original_value', 'corrected_at', 'corrected_by'];

    protected $casts = ['corrected_at' => 'datetime'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }

    public function question(): BelongsTo
    {
        // A removed question stays readable: its answers must keep their label and type.
        return $this->belongsTo(FormQuestion::class, 'question_id')->withTrashed();
    }

    public function isCorrected(): bool
    {
        return $this->corrected_at !== null;
    }
}
