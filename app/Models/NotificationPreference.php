<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'channel', 'type', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Check if a user has a specific channel+type enabled (defaults to true when no preference set). */
    public static function isEnabled(int $userId, string $channel, string $type): bool
    {
        $pref = static::query()
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->where('type', $type)
            ->first();

        return $pref === null || $pref->enabled;
    }
}
