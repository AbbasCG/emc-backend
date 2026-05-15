<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workshop extends Model
{
    protected $fillable = [
        'course_id',
        'title',
        'slug',
        'description',
        'status',
        'start_date',
        'end_date',
        'sort_order',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
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
