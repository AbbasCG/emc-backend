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

    public static function resolveEndDateTime(Course $course): ?Carbon
    {
        if (! $course->end_date) {
            return null;
        }

        $ends = $course->end_date->copy()->startOfDay();

        if (! empty($course->end_time)) {
            $time = trim((string) $course->end_time);
            if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
                $ends->setTime((int) $m[1], (int) $m[2], 59);
            } else {
                $ends->endOfDay();
            }
        } else {
            $ends->endOfDay();
        }

        return $ends;
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

    /** Public enrollment allowed on ended course only when admin keeps registration_open. */
    public static function blocksPublicEnrollment(Course $course): bool
    {
        if (! self::isEnded($course)) {
            return false;
        }

        return ! (bool) ($course->registration_open ?? false);
    }
}
