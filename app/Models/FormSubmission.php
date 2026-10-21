<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormSubmission extends Model
{
    public const NEW = 'new';

    public const IN_REVIEW = 'in_review';

    public const NEEDS_FOLLOWUP = 'needs_followup';

    public const COMPLETED = 'completed';

    public const ARCHIVED = 'archived';

    /** The internal review statuses, in the order the workflow reads. Never shown to a respondent. */
    public const STATUSES = [self::NEW, self::IN_REVIEW, self::NEEDS_FOLLOWUP, self::COMPLETED, self::ARCHIVED];

    public const STATUS_LABELS = [
        self::NEW            => 'جديد',
        self::IN_REVIEW      => 'قيد المراجعة',
        self::NEEDS_FOLLOWUP => 'يحتاج متابعة',
        self::COMPLETED      => 'مكتمل',
        self::ARCHIVED       => 'مؤرشف',
    ];

    /** The one old spelling still accepted from existing clients: `reviewed` meant "completed". */
    public const LEGACY_STATUS = ['reviewed' => self::COMPLETED];

    protected $fillable = [
        'form_id', 'form_version_id', 'user_id', 'status', 'submitted_at', 'metadata', 'share_link_id',
        'assigned_to', 'assigned_at', 'corrected_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'assigned_at'  => 'datetime',
        'corrected_at' => 'datetime',
        'metadata'     => 'array',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'form_id');
    }

    /** The published version the respondent filled in (empty for responses that predate versions and could not be linked). */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(FormShareLink::class, 'share_link_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class, 'submission_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(FormSubmissionNote::class, 'submission_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FormSubmissionEvent::class, 'submission_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(FormAnswerCorrection::class, 'submission_id');
    }
}
