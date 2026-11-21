<?php

namespace App\Models;

use App\Observers\TicketAssignmentObserver;
use App\Observers\TicketStatusObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    ];

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

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(TicketActivityLog::class)->orderBy('logged_at', 'desc');
    }
}
