<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrInterview extends Model
{
    protected $fillable = [
        'application_id', 'candidate_name', 'candidate_email',
        'position', 'type', 'status',
        'scheduled_at', 'duration_minutes',
        'interviewer_id', 'location', 'notes',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(HrApplication::class, 'application_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }
}
