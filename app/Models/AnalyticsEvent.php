<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'visitor_id', 'session_id', 'user_id', 'type', 'name', 'path', 'referrer',
        'utm_source', 'utm_medium', 'utm_campaign',
        'locale', 'timezone', 'device', 'browser', 'os',
        'screen_w', 'screen_h', 'meta', 'created_at',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];
}
