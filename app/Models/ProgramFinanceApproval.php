<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProgramFinanceApproval extends Model
{
    protected $fillable = [
        'approvable_type',
        'approvable_id',
        'status',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'rejection_reason',
        'price_snapshot',
        'currency_snapshot',
        'request_type',
        'changes_snapshot',
        'proposed_hash',
    ];

    protected $casts = [
        'submitted_at'     => 'datetime',
        'reviewed_at'      => 'datetime',
        'price_snapshot'   => 'decimal:2',
        'changes_snapshot' => 'array',
    ];

    public function isCreateRequest(): bool { return ($this->request_type ?? 'create') === 'create'; }
    public function isUpdateRequest(): bool { return ($this->request_type ?? 'create') === 'update'; }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool  { return $this->status === 'pending'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isRejected(): bool { return $this->status === 'rejected'; }
}
