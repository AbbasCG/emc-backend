<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassGroupStudent extends Model
{
    /**
     * The single source of truth for "this membership counts as an active
     * class assignment" — used by every count query, list query, and
     * assignment-creation path (manual addStudent(), ClassAssignmentService)
     * so a student can never be created with one status and hidden by a
     * query expecting another. Do not duplicate this list inline elsewhere.
     */
    public const ACTIVE_STATUSES = ['assigned', 'completed'];

    protected $fillable = [
        'class_group_id',
        'user_id',
        'registration_id',
        'placement_attempt_id',
        'oral_assessment_booking_id',
        'assigned_by',
        'assigned_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(ClassGroupStudentHistory::class)->latest('changed_at');
    }

    /** Null when ClassAssignmentService assigned the row automatically. */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
