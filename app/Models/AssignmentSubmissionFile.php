<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One verified attachment of an assignment submission. `attempt_number` 0 is the current set, N > 0 a history
 * attempt's set. `stored_path` is an internal path on the private disk and is never serialised to clients.
 */
class AssignmentSubmissionFile extends Model
{
    protected $table = 'assignment_submission_files';

    protected $fillable = [
        'submission_id', 'attempt_number', 'original_name', 'stored_path', 'mime', 'extension', 'size_bytes', 'sha256',
    ];

    protected $hidden = ['stored_path'];

    protected $casts = [
        'attempt_number' => 'integer',
        'size_bytes'     => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'submission_id');
    }
}
