<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalEventLog extends Model
{
    protected $fillable = [
        'source', 'event_type', 'payload',
        'status', 'processed_at', 'error_message',
    ];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];

    public function markProcessed(): void
    {
        $this->update(['status' => 'processed', 'processed_at' => now()]);
    }

    public function markFailed(string $reason): void
    {
        $this->update(['status' => 'failed', 'error_message' => $reason]);
    }
}
