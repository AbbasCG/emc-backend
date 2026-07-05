<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancialRequest extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'budget', 'purchase', 'invoice_payment',
        'reimbursement', 'operational_expense', 'transfer', 'other',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = [
        'draft', 'submitted', 'finance_review', 'finance_approved',
        'finance_rejected', 'executive_review', 'approved',
        'rejected', 'returned', 'cancelled',
    ];

    protected $fillable = [
        'requester_id', 'department_id', 'title', 'description',
        'request_type', 'amount', 'currency', 'needed_by_date',
        'priority', 'status', 'finance_note', 'executive_note',
        'rejection_reason', 'returned_reason',
        'finance_reviewer_id', 'executive_reviewer_id',
        'submitted_at', 'finance_reviewed_at', 'executive_reviewed_at',
    ];

    protected $casts = [
        'amount'               => 'decimal:2',
        'needed_by_date'       => 'date',
        'submitted_at'         => 'datetime',
        'finance_reviewed_at'  => 'datetime',
        'executive_reviewed_at'=> 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function financeReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_reviewer_id');
    }

    public function executiveReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executive_reviewer_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FinancialRequestAttachment::class);
    }

    public function workflowSteps(): HasMany
    {
        return $this->hasMany(FinancialRequestWorkflowStep::class)->orderBy('created_at');
    }
}
