<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClassGroup extends Model
{
    protected $fillable = [
        'course_id',
        'instructor_id',
        'department_id',
        'level_code',
        'name',
        'capacity',
        'start_date',
        'schedule_day',
        'schedule_time',
        'location_type',
        'meeting_link',
        'status',
        'created_by',
        'is_institute',
    ];

    protected $casts = [
        'start_date' => 'date',
        'capacity'   => 'integer',
        'is_institute' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(ClassGroupStudent::class);
    }

    public function activeStudents(): HasMany
    {
        return $this->hasMany(ClassGroupStudent::class)->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES);
    }

    /** Weekly recurring schedule rows — the canonical source for the class's
     *  meeting days/times. `schedule_day`/`schedule_time` are kept as
     *  deprecated single-row columns for backward compatibility only. */
    public function schedules(): HasMany
    {
        return $this->hasMany(ClassGroupSchedule::class)->orderBy('day_of_week');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(ClassAnnouncement::class);
    }

    /** Statuses in which day-to-day teaching actions (creating a session,
     *  taking attendance) are permitted. Ticket 1 Phase 4: draft classes
     *  aren't ready for students yet; completed/archived/cancelled classes
     *  are historical and read-only. Single source of truth — every
     *  controller enforcing this must call this method, not re-derive the
     *  status list inline. */
    public const TEACHING_ACTIVE_STATUSES = ['ready', 'active'];

    public function canPerformTeachingActions(): bool
    {
        return in_array($this->status, self::TEACHING_ACTIVE_STATUSES, true);
    }

    /** Statuses in which class configuration and student membership may
     *  still be edited. `completed`/`archived` classes are historical —
     *  their configuration and roster are frozen, matching Ticket 1 Phase 3's
     *  "Completed: read-only... may not add/remove students" /
     *  "Archived: no teaching mutation allowed" rules. */
    public const EDITABLE_STATUSES = ['draft', 'ready', 'active'];

    public function canBeEdited(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }
}
