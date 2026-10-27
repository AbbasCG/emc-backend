<?php

namespace App\Support;

use App\Models\Course;
use Carbon\Carbon;

/**
 * Computed course lifecycle — does not mutate DB status so ended courses stay publicly visible.
 */
final class CourseComputedStatus
{
    public const LABEL_AR_ENDED = 'انتهت';

    /** Wall-clock timezone courses' start/end times are entered and displayed in. */
    private const TZ = 'Europe/Amsterdam';

    /**
     * Resolves a Course's `{date}_date` + `{date}_time` pair into one Amsterdam-anchored
     * instant. `end_date`/`start_date` are date-only casts (no time-of-day), so we take
     * their Y-m-d string and re-parse it explicitly in Europe/Amsterdam rather than
     * mutating the date-cast Carbon instance in place — that instance carries the app's
     * default timezone (UTC), and setTime()/startOfDay() on it would silently produce a
     * UTC-anchored instant instead of an Amsterdam one, shifting the boundary by the DST
     * offset (1-2 hours) versus what staff actually entered.
     */
    private static function resolveDateTime(?Carbon $date, ?string $time, bool $endOfDayFallback): ?Carbon
    {
        if (! $date) {
            return null;
        }

        $dateStr = $date->format('Y-m-d');
        $result  = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $dateStr, self::TZ)->startOfDay();

        $time = trim((string) $time);
        if ($time !== '' && preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            $result = $result->setTime((int) $m[1], (int) $m[2], $endOfDayFallback ? 59 : 0);
        } elseif ($endOfDayFallback) {
            $result = $result->endOfDay();
        }

        return Carbon::instance($result);
    }

    public static function resolveStartDateTime(Course $course): ?Carbon
    {
        return self::resolveDateTime($course->start_date, $course->start_time, endOfDayFallback: false);
    }

    public static function resolveEndDateTime(Course $course): ?Carbon
    {
        return self::resolveDateTime($course->end_date, $course->end_time, endOfDayFallback: true);
    }

    public static function isEnded(Course $course): bool
    {
        $endsAt = self::resolveEndDateTime($course);
        if (! $endsAt) {
            return false;
        }

        $status = strtolower((string) ($course->status ?? ''));
        if (! in_array($status, ['published', 'active', ''], true)) {
            return false;
        }

        return $endsAt->isPast();
    }

    public static function isUpcoming(Course $course): bool
    {
        $startsAt = self::resolveStartDateTime($course);

        return $startsAt !== null && $startsAt->isFuture();
    }

    /**
     * Full lifecycle status distinguishing upcoming/active/completed timing states —
     * separate from computedStatus()'s publication-oriented draft/published/archived/ended,
     * which existing callers (CourseResource, AdminCourseController, ...) already depend on.
     */
    public static function lifecycleStatus(Course $course): string
    {
        $status = strtolower((string) ($course->status ?? ''));

        if ($status === 'cancelled') {
            return 'cancelled';
        }
        if ($status === 'archived') {
            return 'archived';
        }
        if (self::isEnded($course)) {
            return 'completed';
        }
        if (self::isUpcoming($course)) {
            return 'upcoming';
        }

        return 'active';
    }

    public static function computedStatus(Course $course): string
    {
        if (self::isEnded($course)) {
            return 'ended';
        }

        $status = strtolower((string) ($course->status ?? 'draft'));

        return match ($status) {
            'active', 'published' => 'published',
            'archived'            => 'archived',
            default               => 'draft',
        };
    }

    public static function statusLabelAr(Course $course): string
    {
        return match (self::computedStatus($course)) {
            'ended'     => self::LABEL_AR_ENDED,
            'published' => 'منشورة',
            'archived'  => 'مؤرشفة',
            default     => 'مسودة',
        };
    }

    /** Public enrollment is not allowed on an ended course or workshop. */
    public static function blocksPublicEnrollment(Course $course): bool
    {
        return self::isEnded($course);
    }
}
