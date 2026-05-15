<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiPrompt extends Model
{
    protected $fillable = ['key', 'name', 'description', 'system_prompt', 'variables', 'category', 'version', 'status'];

    protected $casts = ['variables' => 'array'];

    /**
     * Find active prompt by key and interpolate variables.
     *
     * @param  array<string, string>  $variables
     */
    public static function render(string $key, array $variables = []): ?string
    {
        $prompt = static::query()
            ->where('key', $key)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();

        if (! $prompt) {
            return null;
        }

        $text = $prompt->system_prompt;
        foreach ($variables as $k => $v) {
            $text = str_replace("{{{$k}}}", (string) $v, $text);
            $text = str_replace("{{ {$k} }}", (string) $v, $text);
        }

        return $text;
    }
}
