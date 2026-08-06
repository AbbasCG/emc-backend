<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'endpoint_id', 'event', 'payload', 'status',
        'response_code', 'response_body', 'attempted_at', 'error_message',
    ];

    protected $casts = [
        'payload'      => 'array',
        'attempted_at' => 'datetime',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class);
    }
}
