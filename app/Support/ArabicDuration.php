<?php

namespace App\Support;

/**
 * A duration in whole minutes as natural Arabic: «ساعة و15 دقيقة», «ساعتان», «3 ساعات و5 دقائق», «45 دقيقة».
 *
 * Only the non-zero parts are written, and the plural forms follow the usual Arabic rules (the same ones the
 * frontend uses): 1 → ساعة / دقيقة, 2 → ساعتان / دقيقتان, 3-10 → ساعات / دقائق, 11+ → ساعة / دقيقة.
 */
final class ArabicDuration
{
    public static function describe(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = self::unit($hours, 'ساعة', 'ساعتان', 'ساعات');
        }
        if ($rest > 0) {
            $parts[] = self::unit($rest, 'دقيقة', 'دقيقتان', 'دقائق');
        }

        if ($parts === []) {
            return '0 دقيقة';
        }

        // The conjunction is written attached to the second part: «ساعة و15 دقيقة».
        return count($parts) === 2 ? $parts[0].' و'.$parts[1] : $parts[0];
    }

    private static function unit(int $n, string $one, string $two, string $few): string
    {
        return match (true) {
            $n === 1 => $one,
            $n === 2 => $two,
            $n >= 3 && $n <= 10 => "{$n} {$few}",
            default => "{$n} {$one}",
        };
    }
}
