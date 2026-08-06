<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionLinkOpen extends Model
{
    protected $table = 'session_link_opens';

    protected $fillable = [
        'session_id',
        'session_type',
        'course_id',
        'user_id',
        'opened_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
