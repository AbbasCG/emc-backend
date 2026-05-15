<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\LmsSession;
use App\Models\StudentProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StudentProgressService
{
    public function __construct(
        protected LmsEnrollmentService $enrollment
    ) {}

    /**
     * Recompute and persist progress rows for the user's enrolled courses (and optional track).
     */
    public function syncForUser(User $user): void
    {
        $courseIds = $this->enrollment->enrolledCourseIds($user);
        if ($courseIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($user, $courseIds) {
            foreach ($courseIds as $courseId) {
                $this->syncCourseRow($user, (int) $courseId);
            }
        });
    }

    public function syncCourseRow(User $user, int $courseId): StudentProgress
    {
        $course = Course::query()->with('track')->findOrFail($courseId);

        $sessionIds = LmsSession::query()
            ->where(function ($q) use ($courseId) {
                $q->where('course_id', $courseId)
                    ->orWhereHas('workshop', fn ($w) => $w->where('course_id', $courseId));
            })
            ->pluck('id');

        $totalSessions = $sessionIds->count();
        $completedSessions = LmsSession::query()
            ->whereIn('id', $sessionIds)
            ->where('status', 'completed')
            ->count();

        $attendanceRows = Attendance::query()
            ->where('user_id', $user->id)
            ->whereIn('session_id', $sessionIds)
            ->get();

        $attendanceDen = max($sessionIds->count(), 1);
        $attendanceNum = $attendanceRows->whereIn('status', ['present', 'late'])->count();
        $attendancePct = (int) round(($attendanceNum / $attendanceDen) * 100);

        $assignments = Assignment::query()
            ->where(function ($q) use ($courseId) {
                $q->where('course_id', $courseId)
                    ->orWhereHas('workshop', fn ($w) => $w->where('course_id', $courseId));
            })
            ->where('status', 'active')
            ->pluck('id');

        $totalAssignments = $assignments->count();
        $submittedAssignments = $totalAssignments > 0
            ? \App\Models\AssignmentSubmission::query()
                ->where('user_id', $user->id)
                ->whereIn('assignment_id', $assignments)
                ->whereNotNull('submitted_at')
                ->pluck('assignment_id')
                ->unique()
                ->count()
            : 0;

        $assignPct = $totalAssignments > 0
            ? (int) round(($submittedAssignments / $totalAssignments) * 100)
            : 0;

        $progressPct = (int) round(($completedSessions / max($totalSessions, 1)) * 50 + $assignPct * 0.25 + $attendancePct * 0.25);

        $status = 'in_progress';
        if ($totalSessions === 0 && $totalAssignments === 0) {
            $status = 'not_started';
        } elseif ($totalSessions > 0 && $completedSessions >= $totalSessions && ($totalAssignments === 0 || $assignPct >= 100)) {
            $status = 'completed';
        }

        $row = StudentProgress::query()->firstOrNew([
            'user_id' => $user->id,
            'course_id' => $courseId,
        ]);

        $row->fill([
            'track_id' => $course->track_id,
            'progress_percentage' => min(100, $progressPct),
            'completed_sessions' => $completedSessions,
            'total_sessions' => $totalSessions,
            'attendance_percentage' => min(100, $attendancePct),
            'assignment_completion_percentage' => min(100, $assignPct),
            'status' => $status,
        ]);

        if ($status === 'completed' && ! $row->completed_at) {
            $row->completed_at = now();
        }
        if ($status !== 'completed') {
            $row->completed_at = null;
        }

        $row->save();

        return $row;
    }
}
