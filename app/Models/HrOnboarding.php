<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrOnboarding extends Model
{
    protected $table = 'hr_onboarding';

    protected $fillable = [
        'user_id', 'status', 'steps',
        'start_date', 'target_date', 'assigned_to', 'notes',
    ];

    protected $casts = [
        'steps'       => 'array',
        'start_date'  => 'date',
        'target_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
