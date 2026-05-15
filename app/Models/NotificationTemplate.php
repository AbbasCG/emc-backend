<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = ['key', 'channel', 'language', 'subject', 'content', 'status'];

    /**
     * Find active template and interpolate variables.
     *
     * @param  array<string, string>  $variables  e.g. ['name' => 'Ahmed', 'course' => 'Laravel']
     */
    public static function render(string $key, string $channel, array $variables = [], string $language = 'en'): ?array
    {
        $template = static::query()
            ->where('key', $key)
            ->where('channel', $channel)
            ->where('language', $language)
            ->where('status', 'active')
            ->first();

        if (! $template) {
            return null;
        }

        $replace = function (string $text) use ($variables): string {
            foreach ($variables as $k => $v) {
                $text = str_replace("{{$k}}", (string) $v, $text);
                $text = str_replace("{{ $k }}", (string) $v, $text);
            }
            return $text;
        };

        return [
            'subject' => $template->subject ? $replace($template->subject) : null,
            'content' => $replace($template->content),
        ];
    }
}
