<?php

namespace App\Services;

use App\Models\NotificationQuietHours;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ticket 8 completion pass — quiet hours (Step 8).
 *
 * Policy (documented per the ticket's explicit requirement to state the
 * exact business rule): quiet hours suppress-by-delay, never discard.
 * In-app notifications (`platform_notifications`) are ALWAYS written
 * immediately, quiet hours or not — a user opening the Notification Center
 * during their own quiet hours must still see what happened. Only the
 * disruptive external channels (SMS, push; WhatsApp/email are not gated by
 * this service in this pass — see ADR) are delayed to the next allowed
 * local time when quiet hours are active, unless the caller marks the
 * notification `urgent`, which always bypasses quiet hours entirely.
 */
class QuietHoursService
{
    public function isWithinQuietHours(User $user, ?Carbon $at = null): bool
    {
        $settings = NotificationQuietHours::where('user_id', $user->id)->first();

        if (! $settings || ! $settings->enabled || ! $settings->start_time || ! $settings->end_time) {
            return false;
        }

        $tz = $settings->timezone ?: 'UTC';
        $now = ($at ?? now())->clone()->setTimezone($tz);

        $start = Carbon::createFromFormat('H:i:s', $this->normalizeTime($settings->start_time), $tz)
            ->setDate($now->year, $now->month, $now->day);
        $end = Carbon::createFromFormat('H:i:s', $this->normalizeTime($settings->end_time), $tz)
            ->setDate($now->year, $now->month, $now->day);

        if ($start->equalTo($end)) {
            // A zero-length window means "always quiet" is nonsensical — treat as disabled.
            return false;
        }

        if ($start->lessThan($end)) {
            // Same-day range, e.g. 13:00 → 17:00.
            return $now->betweenIncluded($start, $end) && $now->lessThan($end);
        }

        // Overnight range, e.g. 22:00 → 07:00: quiet from start to midnight, and from midnight to end.
        return $now->greaterThanOrEqualTo($start) || $now->lessThan($end);
    }

    /**
     * The next local moment quiet hours end, as an absolute UTC Carbon instant
     * suitable for `->delay()`. Correctly crosses midnight and DST
     * transitions because it is computed by constructing real local
     * calendar dates in the user's timezone (via Carbon, which is
     * DST-aware) rather than adding a fixed offset in seconds.
     */
    public function nextAllowedTime(User $user, ?Carbon $at = null): Carbon
    {
        $settings = NotificationQuietHours::where('user_id', $user->id)->first();
        $tz = $settings?->timezone ?: 'UTC';
        $now = ($at ?? now())->clone()->setTimezone($tz);

        if (! $settings || ! $settings->enabled || ! $settings->end_time) {
            return $now->clone();
        }

        $end = Carbon::createFromFormat('H:i:s', $this->normalizeTime($settings->end_time), $tz)
            ->setDate($now->year, $now->month, $now->day);

        if ($end->lessThanOrEqualTo($now)) {
            $end->addDay();
        }

        return $end;
    }

    private function normalizeTime(string $time): string
    {
        // DB TIME columns round-trip as "H:i:s"; accept "H:i" defensively too.
        return strlen($time) === 5 ? $time . ':00' : $time;
    }
}
