<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workshop extends Model
{
    protected $fillable = [
        'course_id',
        'instructor_id',
        'assigned_by',
        'title',
        'slug',
        'description',
        'status',
        'start_date',
        'start_time',
        'end_date',
        'end_time',
        'location_type',
        'meeting_link',
        'registration_open',
        'sort_order',
    ];

    protected $casts = [
        'start_date'        => 'datetime',
        'end_date'          => 'datetime',
        'registration_open' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LmsSession::class, 'workshop_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class, 'workshop_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'workshop_id');
    }
}
