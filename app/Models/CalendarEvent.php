<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CalendarEvent extends Model
{
    protected $fillable = [
        'user_id', 'title', 'description',
        'start_at', 'end_at', 'location', 'meeting_url',
        'external_provider', 'external_event_id', 'sync_status',
        'eventable_type', 'eventable_id',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eventable(): MorphTo
    {
        return $this->morphTo();
    }
}
