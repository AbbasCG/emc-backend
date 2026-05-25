<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformNotification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'entity_type',
        'entity_id',
        'action_url',
        'icon',
        'color',
        'is_read',
        'read_at',
        'created_by',
    ];

    protected $casts = [
        'is_read'   => 'boolean',
        'read_at'   => 'datetime',
        'entity_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
