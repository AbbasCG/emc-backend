<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $casts = [
        'start_date' => 'date',
        'capacity'   => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
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
        return $this->hasMany(ClassGroupStudent::class)->whereIn('status', ['assigned', 'completed']);
    }
}
