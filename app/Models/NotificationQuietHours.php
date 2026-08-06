<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationQuietHours extends Model
{
    protected $table = 'notification_quiet_hours';

    protected $fillable = [
        'user_id', 'enabled', 'start_time', 'end_time', 'timezone',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
