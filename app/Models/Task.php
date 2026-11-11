<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Task extends Model
{
    // Task Scope / Size constants
    public const SCOPE_SIMPLE = 'simple';
    public const SCOPE_MEDIUM = 'medium';
    public const SCOPE_LARGE = 'large';
    public const SCOPE_STRATEGIC = 'strategic';

    // Acceptance status
    public const ACCEPTANCE_PENDING = 'pending';
    public const ACCEPTANCE_ACCEPTED = 'accepted';
    public const ACCEPTANCE_REJECTED = 'rejected';

    protected $fillable = [
        'parent_task_id',
        'title',
        'description',
        'department_id',
        'section_id',
        'assigned_to',
        'created_by',
        'priority',
        'task_scope',
        'points',
        'estimated_hours',
        'status',
        'acceptance_status',
        'rejection_reason',
        'accepted_at',
        'rejected_at',
        'manager_approved_at',
        'manager_approved_by',
        'hr_approved_at',
        'hr_approved_by',
        'start_date',
        'due_date',
        'due_datetime',
        'notes',
        'related_type',
        'related_id',
        'tags',
        'locked_at',
        'locked_by',
    ];

    protected $casts = [
        'tags'                => 'array',
        'start_date'          => 'date',
        'due_date'            => 'date',
        'due_datetime'        => 'datetime',
        'locked_at'           => 'datetime',
        'accepted_at'         => 'datetime',
        'rejected_at'         => 'datetime',
        'manager_approved_at' => 'datetime',
        'hr_approved_at'      => 'datetime',
        'points'              => 'integer',
        'estimated_hours'     => 'float',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(DepartmentSection::class, 'section_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }

    public function hrApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_approved_by');
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('sort_order');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(TaskEvaluation::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo('related');
    }
}
