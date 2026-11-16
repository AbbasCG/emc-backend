<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The Arabic 429 for limiters whose users read the message (resend credentials, change password). Used by the named limiters through
 * Limit::response() and by code that checks a limit itself, so the shape is identical: message in minutes, `retry_after` in seconds,
 * and a Retry-After header. The global handler in bootstrap/app.php (English, for every other route) is untouched.
 */
class ThrottleResponse
{
    public static function make(int $retryAfterSeconds, array $headers = []): JsonResponse
    {
        $seconds = max(1, $retryAfterSeconds);
        $minutes = (int) ceil($seconds / 60);

        $unit = match (true) {
            $minutes === 1 => 'دقيقة',
            $minutes === 2 => 'دقيقتين',
            $minutes <= 10 => $minutes . ' دقائق',
            default        => $minutes . ' دقيقة',
        };
        // "after a minute" reads as "بعد دقيقة", "after 2 minutes" as "بعد دقيقتين"
        $message = 'حاولت كثيرًا. أعد المحاولة بعد ' . $unit . '.';

        return response()->json([
            'success'     => false,
            'code'        => 'too_many_requests',
            'message'     => $message,
            'retry_after' => $seconds,
        ], 429, array_merge($headers, ['Retry-After' => $seconds]));
    }

    /** For Limit::response(): the headers Laravel hands over carry Retry-After (and X-RateLimit-*). */
    public static function forLimit(): \Closure
    {
        return fn ($request, array $headers) => self::make((int) ($headers['Retry-After'] ?? 60), $headers);
    }
}
