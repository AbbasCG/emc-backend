<?php

namespace App\Support;

/**
 * Canonical status vocabulary for CourseModule and Lesson.
 *
 * Ticket 7 audit evidence: both models are validated with the identical
 * set `in:active,inactive,draft` (CourseModuleController, LessonController)
 * and are hidden from students under the identical rule (module: status
 * not in [inactive, draft]; lesson: status must equal active). This is a
 * real, already-shared vocabulary — not the ticket's assumed
 * draft/published/scheduled/archived set, which does not match live code.
 *
 * CourseSession's `status` column is a separate, already-inconsistent
 * surface (AdminLmsSessionController validates a mixed set of
 * scheduled/live/completed/cancelled/published/draft, and student-facing
 * visibility is actually gated by `is_visible`, not `status`) — it is
 * deliberately NOT unified into this class, to avoid papering over a
 * pre-existing inconsistency by inventing a shared meaning that isn't
 * really there yet.
 */
class CourseContentStatus
{
    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';
    public const DRAFT    = 'draft';

    public const ALL = [self::ACTIVE, self::INACTIVE, self::DRAFT];

    /** Statuses that hide the content from students. */
    public const HIDDEN_FROM_STUDENTS = [self::INACTIVE, self::DRAFT];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isVisibleToStudents(?string $status): bool
    {
        return $status !== null && ! in_array($status, self::HIDDEN_FROM_STUDENTS, true);
    }
}
