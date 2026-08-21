<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingReport extends Model
{
    protected $fillable = [
        'department_id', 'meeting_id', 'title', 'meeting_date',
        'achieved', 'planned', 'needs', 'needs_reason', 'decisions', 'notes',
        'created_by',
    ];

    protected $casts = [
        'meeting_date' => 'date',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(MeetingReportRating::class);
    }
}
