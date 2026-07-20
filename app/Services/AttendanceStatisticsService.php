<?php

namespace App\Services;

use App\Models\Attendance;
use App\Support\AttendanceStatus;
use Illuminate\Support\Collection;

/**
 * Ticket 6: single canonical place for attendance statistics/streaks —
 * counts, percentage, current/longest streaks, risk level. Deliberately
 * does NOT redefine "counts as attended" for percentage purposes: it
 * preserves StudentProgressService's existing present+late definition
 * (the one used everywhere the platform already surfaces "attendance
 * percentage" to students/instructors) rather than introducing a third
 * competing definition alongside StudentProgressService's and
 * CertificateEligibilityService's already-different ones. Certificate
 * eligibility itself is untouched — this service is never called from
 * CertificateEligibilityService.
 */
class AttendanceStatisticsService
{
    public function __construct(private readonly AttendanceSettingsService $settings)
    {
    }

    /**
     * @param int $userId
     * @param int|null $courseId Optional — omit for all-courses statistics.
     * @param int|null $classGroupId Optional — further restricts to one class.
     */
    public function forStudent(int $userId, ?int $courseId = null, ?int $classGroupId = null): array
    {
        $rows = $this->orderedRows($userId, $courseId, $classGroupId);

        return $this->computeFromRows($rows);
    }

    /** @return Collection<int, Attendance> deterministically ordered by session date then id — never DB row order. */
    private function orderedRows(int $userId, ?int $courseId, ?int $classGroupId): Collection
    {
        $query = Attendance::where('user_id', $userId)
            ->with(['session:id,session_date,course_id,class_group_id', 'courseSession:id,start_at,course_id,class_group_id']);

        if ($courseId) {
            $query->where(function ($q) use ($courseId) {
                $q->whereHas('session', fn ($q2) => $q2->where('course_id', $courseId))
                    ->orWhereHas('courseSession', fn ($q2) => $q2->where('course_id', $courseId));
            });
        }

        if ($classGroupId) {
            $query->where('class_group_id', $classGroupId);
        }

        return $query->get()
            ->sortBy(function (Attendance $a) {
                $date = $a->session?->session_date?->toDateString() ?? $a->courseSession?->start_at?->toDateString() ?? $a->created_at?->toDateString();
                // Deterministic tie-break: same-date rows ordered by id.
                return $date . '-' . str_pad((string) $a->id, 10, '0', STR_PAD_LEFT);
            })
            ->values();
    }

    /** @param Collection<int, Attendance> $rows */
    public function computeFromRows(Collection $rows): array
    {
        $total = $rows->count();
        $present = $rows->where('status', AttendanceStatus::PRESENT)->count();
        $absent = $rows->where('status', AttendanceStatus::ABSENT)->count();
        $late = $rows->where('status', AttendanceStatus::LATE)->count();
        $excused = $rows->where('status', AttendanceStatus::EXCUSED)->count();

        // Matches StudentProgressService's existing present+late definition.
        $attendedCount = $present + $late;
        $percentage = $total > 0 ? (int) round(($attendedCount / $total) * 100) : 0;

        [$currentAttendanceStreak, $currentAbsenceStreak, $currentLateStreak] = $this->currentStreaks($rows);
        [$longestAttendanceStreak, $longestAbsenceStreak] = $this->longestStreaks($rows);

        return [
            'total'                     => $total,
            'present_count'             => $present,
            'absent_count'              => $absent,
            'late_count'                => $late,
            'excused_count'             => $excused,
            'attendance_percentage'     => $percentage,
            'current_attendance_streak' => $currentAttendanceStreak,
            'current_absence_streak'    => $currentAbsenceStreak,
            'current_late_streak'       => $currentLateStreak,
            'longest_attendance_streak' => $longestAttendanceStreak,
            'longest_absence_streak'    => $longestAbsenceStreak,
            'risk_level'                => $this->riskLevel($percentage, $currentAbsenceStreak, $total),
        ];
    }

    /** Counts consecutive matching statuses from the most recent row backwards. @return array{0:int,1:int,2:int} [attendance, absence, late] */
    private function currentStreaks(Collection $rows): array
    {
        $reversed = $rows->reverse()->values();

        $attendanceStreak = 0;
        foreach ($reversed as $row) {
            if (in_array($row->status, [AttendanceStatus::PRESENT, AttendanceStatus::LATE], true)) $attendanceStreak++;
            else break;
        }
        $absenceStreak = 0;
        foreach ($reversed as $row) {
            if ($row->status === AttendanceStatus::ABSENT) $absenceStreak++;
            else break;
        }
        $lateStreak = 0;
        foreach ($reversed as $row) {
            if ($row->status === AttendanceStatus::LATE) $lateStreak++;
            else break;
        }

        return [$attendanceStreak, $absenceStreak, $lateStreak];
    }

    /** @return array{0:int,1:int} [longest attendance streak, longest absence streak] */
    private function longestStreaks(Collection $rows): array
    {
        $longestAttendance = 0;
        $longestAbsence = 0;
        $curAttendance = 0;
        $curAbsence = 0;

        foreach ($rows as $row) {
            if (in_array($row->status, [AttendanceStatus::PRESENT, AttendanceStatus::LATE], true)) {
                $curAttendance++;
                $longestAttendance = max($longestAttendance, $curAttendance);
            } else {
                $curAttendance = 0;
            }

            if ($row->status === AttendanceStatus::ABSENT) {
                $curAbsence++;
                $longestAbsence = max($longestAbsence, $curAbsence);
            } else {
                $curAbsence = 0;
            }
        }

        return [$longestAttendance, $longestAbsence];
    }

    private function riskLevel(int $percentage, int $currentAbsenceStreak, int $total): string
    {
        // No attendance history yet (e.g. newly enrolled) is not "at risk" —
        // there is simply no data to judge, distinct from a genuinely low
        // percentage after several sessions.
        if ($total === 0) return 'low';

        $atRisk = $this->settings->get('at_risk_percentage');
        $repeatedAbsence = $this->settings->get('repeated_absence_threshold');

        if ($percentage < ($atRisk - 20) || $currentAbsenceStreak >= $repeatedAbsence) return 'high';
        if ($percentage < $atRisk) return 'medium';
        return 'low';
    }
}
