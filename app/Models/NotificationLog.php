<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    protected $fillable = [
        'user_id', 'channel', 'type', 'recipient', 'subject',
        'status', 'payload', 'error_message', 'sent_at', 'queued_at', 'failed_at',
    ];

    protected $casts = [
        'payload'   => 'array',
        'sent_at'   => 'datetime',
        'queued_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
