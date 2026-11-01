<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolunteerPointRequest extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVISION = 'revision';

    protected $fillable = [
        'user_id',
        'department_id',
        'requester_id',
        'activity_id',
        'category_key',
        'activity_name',
        'points_requested',
        'points_approved',
        'reason',
        'attachment_path',
        'attachment_name',
        'status',
        'reviewer_id',
        'reviewed_at',
        'review_notes',
        'rejection_reason',
        'award_id',
    ];

    protected $casts = [
        'points_requested' => 'integer',
        'points_approved'  => 'integer',
        'reviewed_at'      => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(VolunteerPointActivity::class, 'activity_id');
    }

    public function award(): BelongsTo
    {
        return $this->belongsTo(VolunteerPointAward::class, 'award_id');
    }
}
