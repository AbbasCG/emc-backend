<?php

namespace App\Models;

use App\Observers\TicketAssignmentObserver;
use App\Observers\TicketStatusObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([TicketStatusObserver::class, TicketAssignmentObserver::class])]
class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket_number',
        'title',
        'description',
        'ticket_category',
        'priority',
        'task_scope',
        'task_points',
        'estimated_hours',
        'department_id',
        'unit_id',
        'created_by_id',
        'created_by_name',
        'created_by_email',
        'approved_by_id',
        'assigned_to_id',
        'status',
        'expected_resolution_time',
        'accepted_at',
        'resolved_at',
        'admin_rejection_reason',
        'assignee_rejection_reason',
        'resolution_summary',
        'unresolved_reason',
        'internal_notes',
    ];

    protected $casts = [
        'expected_resolution_time' => 'datetime',
        'accepted_at' => 'datetime',
        'resolved_at' => 'datetime',
        // Planning estimate (see TicketWorkEstimate); credited only at the final approval (TicketCreditService).
        'task_points' => 'integer',
        'estimated_hours' => 'float',
        // Snapshot of the one credit (see TicketCreditService); all NULL until the final approval.
        'credited_points' => 'integer',
        'credited_hours' => 'float',
        'credited_minutes' => 'integer',
        // The reviewer-entered ACTUAL duration (minutes); written only by TicketCreditService, never mass assigned.
        'actual_minutes' => 'integer',
        'credited_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    /**
     * The credit snapshot never leaves in a raw serialization: staff payloads carry the redacted `credit` block
     * (TicketAccess::creditInfo) and everyone else gets nothing.
     */
    // credited_* are deliberately NOT fillable: only TicketCreditService writes them (forceFill), never a request.
    protected $hidden = ['credited_points', 'credited_hours', 'credited_at', 'credited_by_id', 'credited_minutes', 'submitted_by_id', 'submitted_at'];

    protected $appends = ['is_delayed'];

    public function getIsDelayedAttribute(): bool
    {
        if (in_array($this->status, ['RESOLVED', 'REJECTED_BY_ADMIN', 'UNRESOLVED'])) {
            return false;
        }

        if (!$this->expected_resolution_time) {
            return false;
        }

        return now()->greaterThan($this->expected_resolution_time);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(DepartmentalUnit::class, 'unit_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /** The person who gave the final approval that credited the ticket. */
    public function creditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credited_by_id');
    }

    public function credit(): HasOne
    {
        return $this->hasOne(TicketCredit::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(TicketActivityLog::class)->orderBy('logged_at', 'desc');
    }
}
