<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyDepartmentReport extends Model
{
    protected $fillable = [
        'department_id', 'week_start', 'achievements', 'planned',
        'blockers', 'needs', 'notes', 'submitted_by', 'submitted_at',
    ];

    protected $casts = [
        'week_start'   => 'date',
        'submitted_at' => 'datetime',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(WeeklyReportRating::class);
    }
}
