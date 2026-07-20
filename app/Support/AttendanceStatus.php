<?php

namespace App\Support;

/**
 * Ticket 6: single source of truth for attendance status strings — no more
 * scattered inline literals ('present'/'absent'/'late'/'excused') across
 * SessionAttendanceController/CertificateEligibilityService/StudentProgressService.
 */
class AttendanceStatus
{
    public const PRESENT = 'present';
    public const ABSENT  = 'absent';
    public const LATE    = 'late';
    public const EXCUSED = 'excused';

    public const ALL = [self::PRESENT, self::ABSENT, self::LATE, self::EXCUSED];

    /**
     * NOTE: two different "what counts as attended" definitions already
     * coexist in this codebase — StudentProgressService counts
     * present+late, CertificateEligibilityService counts present only.
     * Deliberately not collapsed into one shared constant here: that would
     * silently change one of the two existing, working behaviors, which is
     * out of this ticket's scope. Each caller's own definition is left as-is.
     */

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
