<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class EmailSetting extends Model
{
    protected $fillable = ['key', 'enabled', 'sender_name', 'sender_email'];

    protected $casts = ['enabled' => 'boolean'];

    public static function isEnabled(string $key): bool
    {
        $setting = static::where('key', $key)->first();
        return $setting === null || (bool) $setting->enabled;
    }

    public static function senderName(): string
    {
        $row = static::where('key', 'global_sender_name')->first();
        $override = $row?->sender_name;
        return ($override && trim($override) !== '') ? $override : (string) config('mail.from.name', 'EMC Platform');
    }

    public static function senderEmail(): string
    {
        $row = static::where('key', 'global_sender_email')->first();
        $override = $row?->sender_email;
        return ($override && trim($override) !== '') ? $override : (string) config('mail.from.address', 'no-reply@emc.test');
    }
}
