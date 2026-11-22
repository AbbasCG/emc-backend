<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An administrator's on/off switch for one non-email channel of one notification type
 * (e.g. in_app for operations_ticket_assigned). The email channel keeps using EmailSetting,
 * the existing admin email switch. No row means the channel is on.
 *
 * Read at send time by SendPlatformNotificationJob, so turning in_app off stops new rows of that
 * type for everyone; it never touches rows that already exist or anyone's own preference.
 */
class NotificationChannelSetting extends Model
{
    protected $fillable = ['type', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public static function isEnabled(string $type, string $channel): bool
    {
        $row = static::query()->where('type', $type)->where('channel', $channel)->first();

        return $row === null || (bool) $row->enabled;
    }
}
