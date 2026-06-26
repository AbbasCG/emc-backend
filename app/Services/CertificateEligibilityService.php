<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CertificateEligibilityService
{
    /**
     * Same enrollment scope as AdminCourseStudentsController (registrations table).
     */
    private function courseRegistrations(int $courseId): Collection
    {
        return Registration::with('user')
            ->where('course_id', $courseId)
            ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
            ->latest()
            ->get();
    }

    /**
     * Get all students linked to a course (eligible + ineligible + already issued).
     */
    public function forCourse(int $courseId, array $options = []): array
    {
        $minProgress   = $options['min_progress'] ?? 80;
        $minAttendance = $options['min_attendance'] ?? 70;
        $certificateType = $options['certificate_type'] ?? 'course_completion';

        $registrations = $this->courseRegistrations($courseId);
        $userIds       = $registrations->pluck('user_id')->filter()->values()->all();

        $progressMap = StudentProgress::where('course_id', $courseId)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $assignmentIds = Assignment::query()
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->pluck('id');

        $assignmentsTotal = $assignmentIds->count();
        $submittedByUser  = $assignmentsTotal > 0 && $userIds !== []
            ? AssignmentSubmission::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('assignment_id', $assignmentIds)
                ->whereNotNull('submitted_at')
                ->get(['user_id', 'assignment_id'])
                ->groupBy('user_id')
                ->map(fn ($rows) => $rows->pluck('assignment_id')->unique()->count())
            : collect();

        $existingCerts = Certificate::query()
            ->whereIn('user_id', $userIds)
            ->where('certificate_type', $certificateType)
            ->where('status', '!=', 'revoked')
            ->where(function ($q) use ($courseId) {
                $q->where('course_id', $courseId)
                    ->orWhere(fn ($q2) => $q2->where('related_type', 'App\\Models\\Course')->where('related_id', $courseId))
                    ->orWhere(fn ($q2) => $q2->where('related_type', 'course')->where('related_id', $courseId));
            })
            ->get()
            ->keyBy('user_id');

        $results = [];
        foreach ($registrations as $reg) {
            $user = $reg->user ?? ($reg->email ? User::where('email', $reg->email)->first() : null);

            if (! $user) {
                $results[] = [
                    'user'                   => null,
                    'user_id'                => null,
                    'name'                   => $reg->full_name ?? $reg->email ?? '—',
                    'email'                  => $reg->email,
                    'progress'               => 0,
                    'attendance'             => 0,
                    'assignments'            => 0,
                    'assignments_completed'  => 0,
                    'assignments_total'      => $assignmentsTotal,
                    'is_eligible'            => false,
                    'reason'                 => 'لا يوجد حساب مستخدم مرتبط — لا يمكن إصدار شهادة',
                    'existing_certificate'   => null,
                    'already_issued'         => false,
                    'certificate_status'     => null,
                    'registration_id'        => $reg->id,
                    'registration_status'    => $reg->status,
                ];
                continue;
            }

            $progress        = $progressMap->get($user->id);
            $progressPct     = (int) ($progress?->progress_percentage ?? 0);
            $attendancePct   = (int) ($progress?->attendance_percentage ?? 0);
            $assignmentPct   = (int) ($progress?->assignment_completion_percentage ?? 0);
            $assignCompleted = (int) ($submittedByUser->get($user->id) ?? 0);

            $existingCert = $existingCerts->get($user->id);
            $alreadyIssued = $existingCert !== null && in_array($existingCert->status, ['issued', 'approved', 'pending', 'draft'], true);

            $isEligible = ! $alreadyIssued
                && $progressPct >= $minProgress
                && $attendancePct >= $minAttendance;

            if ($alreadyIssued) {
                $reason = 'سبق إصدار شهادة لهذا الطالب';
            } elseif ($isEligible) {
                $reason = 'مؤهل للحصول على الشهادة';
            } else {
                $reason = "لم يكمل جميع المتطلبات — التقدم: {$progressPct}% (المطلوب {$minProgress}%)، الحضور: {$attendancePct}% (المطلوب {$minAttendance}%)";
            }

            $results[] = [
                'user'                   => $user,
                'user_id'                => $user->id,
                'name'                   => $user->name,
                'email'                  => $user->email,
                'progress'               => $progressPct,
                'attendance'             => $attendancePct,
                'assignments'            => $assignmentPct,
                'assignments_completed'  => $assignCompleted,
                'assignments_total'      => $assignmentsTotal,
                'is_eligible'            => $isEligible,
                'reason'                 => $reason,
                'existing_certificate'   => $existingCert,
                'already_issued'         => $alreadyIssued,
                'certificate_status'     => $existingCert?->status,
                'registration_id'        => $reg->id,
                'registration_status'    => $reg->status,
            ];
        }

        return $results;
    }

    /**
     * Get eligible students for a workshop.
     */
    public function forWorkshop(int $workshopId, array $options = []): array
    {
        $minAttendance   = $options['min_attendance'] ?? 70;
        $certificateType = $options['certificate_type'] ?? 'workshop_attendance';

        $registrations = Registration::with('user')
            ->where('workshop_id', $workshopId)
            ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
            ->get();

        $results = [];
        foreach ($registrations as $reg) {
            $user = $reg->user ?? ($reg->email ? User::where('email', $reg->email)->first() : null);

            if (! $user) {
                $results[] = [
                    'user'                 => null,
                    'user_id'              => null,
                    'name'                 => $reg->full_name ?? $reg->email ?? '—',
                    'email'                => $reg->email,
                    'progress'             => 0,
                    'attendance'           => 0,
                    'assignments'          => 0,
                    'assignments_completed'=> 0,
                    'assignments_total'    => 0,
                    'is_eligible'          => false,
                    'reason'               => 'لا يوجد حساب مستخدم مرتبط — لا يمكن إصدار شهادة',
                    'existing_certificate' => null,
                    'already_issued'       => false,
                    'certificate_status'   => null,
                ];
                continue;
            }

            $sessionIds = DB::table('course_sessions')
                ->where('workshop_id', $workshopId)
                ->pluck('id');

            $attendanceCount = DB::table('attendances')
                ->where('user_id', $user->id)
                ->where('status', 'present')
                ->where(function ($q) use ($sessionIds, $workshopId) {
                    $q->whereIn('course_session_id', $sessionIds)
                        ->orWhereIn('session_id', $sessionIds)
                        ->orWhere('workshop_id', $workshopId);
                })
                ->count();

            $totalSessions = $sessionIds->count();
            $attendancePct = $totalSessions > 0
                ? (int) round(($attendanceCount / $totalSessions) * 100)
                : 0;

            $existingCert = Certificate::where('user_id', $user->id)
                ->where('certificate_type', $certificateType)
                ->where('status', '!=', 'revoked')
                ->where(function ($q) use ($workshopId) {
                    $q->where('workshop_id', $workshopId)
                        ->orWhere(fn ($q2) => $q2->where('related_type', 'App\\Models\\Workshop')->where('related_id', $workshopId))
                        ->orWhere(fn ($q2) => $q2->where('related_type', 'workshop')->where('related_id', $workshopId));
                })
                ->first();

            $alreadyIssued = $existingCert !== null && in_array($existingCert->status, ['issued', 'approved', 'pending', 'draft'], true);
            $isEligible    = ! $alreadyIssued && $attendancePct >= $minAttendance;

            $reason = $alreadyIssued
                ? 'سبق إصدار شهادة لهذا الطالب'
                : ($isEligible
                    ? 'مؤهل للحصول على الشهادة'
                    : "نسبة الحضور: {$attendancePct}% (المطلوب {$minAttendance}%)");

            $results[] = [
                'user'                   => $user,
                'user_id'                => $user->id,
                'name'                   => $user->name,
                'email'                  => $user->email,
                'progress'               => $attendancePct,
                'attendance'             => $attendancePct,
                'assignments'            => 0,
                'assignments_completed'  => 0,
                'assignments_total'      => 0,
                'is_eligible'            => $isEligible,
                'reason'                 => $reason,
                'existing_certificate'   => $existingCert,
                'already_issued'         => $alreadyIssued,
                'certificate_status'     => $existingCert?->status,
            ];
        }

        return $results;
    }

    /**
     * Get eligible students for a learning path.
     */
    public function forLearningPath(int $pathId, array $options = []): array
    {
        $minProgress     = $options['min_progress'] ?? 80;
        $certificateType = $options['certificate_type'] ?? 'learning_track';

        $progresses = StudentProgress::with('user')
            ->where('track_id', $pathId)
            ->get();

        $results = [];
        foreach ($progresses as $progress) {
            if (! $progress->user) {
                continue;
            }

            $progressPct   = (int) ($progress->progress_percentage ?? 0);
            $attendancePct = (int) ($progress->attendance_percentage ?? 0);
            $assignmentPct = (int) ($progress->assignment_completion_percentage ?? 0);

            $existingCert = Certificate::where('user_id', $progress->user_id)
                ->where('certificate_type', $certificateType)
                ->where('status', '!=', 'revoked')
                ->where(function ($q) use ($pathId) {
                    $q->where('track_id', $pathId)
                        ->orWhere(fn ($q2) => $q2->whereIn('related_type', ['App\\Models\\Track', 'App\\Models\\LearningPath', 'learning_path', 'track'])->where('related_id', $pathId));
                })
                ->first();

            $alreadyIssued = $existingCert !== null && in_array($existingCert->status, ['issued', 'approved', 'pending', 'draft'], true);
            $isEligible    = ! $alreadyIssued && $progressPct >= $minProgress;

            $reason = $alreadyIssued
                ? 'سبق إصدار شهادة لهذا الطالب'
                : ($isEligible
                    ? 'مؤهل للحصول على الشهادة'
                    : "التقدم: {$progressPct}% (المطلوب {$minProgress}%)");

            $results[] = [
                'user'                   => $progress->user,
                'user_id'                => $progress->user_id,
                'name'                   => $progress->user->name,
                'email'                  => $progress->user->email,
                'progress'               => $progressPct,
                'attendance'             => $attendancePct,
                'assignments'            => $assignmentPct,
                'assignments_completed'  => 0,
                'assignments_total'      => 0,
                'is_eligible'            => $isEligible,
                'reason'                 => $reason,
                'existing_certificate'   => $existingCert,
                'already_issued'         => $alreadyIssued,
                'certificate_status'     => $existingCert?->status,
            ];
        }

        return $results;
    }

    /**
     * Check single student eligibility.
     */
    public function checkStudent(User $user, string $relatedType, int $relatedId): array
    {
        $modelMap = [
            'App\\Models\\Course'        => 'course',
            'App\\Models\\Workshop'      => 'workshop',
            'App\\Models\\LearningPath'  => 'learning_path',
            'App\\Models\\Track'         => 'track',
            'course'                     => 'course',
            'workshop'                   => 'workshop',
            'learning_path'              => 'learning_path',
        ];

        $entityType = $modelMap[$relatedType] ?? null;

        $progress     = null;
        $isEligible   = false;
        $reason       = 'الكيان غير معروف';
        $existingCert = null;

        if ($entityType === 'course') {
            $progress = StudentProgress::where('user_id', $user->id)
                ->where('course_id', $relatedId)
                ->first();

            $reg = Registration::where('user_id', $user->id)
                ->where('course_id', $relatedId)
                ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
                ->first();

            $progressPct = (int) ($progress?->progress_percentage ?? 0);

            $isEligible = $progressPct >= 80 && $reg !== null;
            $reason = $isEligible
                ? 'مؤهل'
                : "التقدم {$progressPct}% (المطلوب 80%)";

            $existingCert = Certificate::where('user_id', $user->id)
                ->where('course_id', $relatedId)
                ->where('status', '!=', 'revoked')
                ->first();

        } elseif ($entityType === 'workshop') {
            $reg = Registration::where('user_id', $user->id)
                ->where('workshop_id', $relatedId)
                ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
                ->first();

            $isEligible = $reg !== null;
            $reason = $isEligible ? 'مسجل في ورشة العمل' : 'غير مسجل';

            $existingCert = Certificate::where('user_id', $user->id)
                ->where('workshop_id', $relatedId)
                ->where('status', '!=', 'revoked')
                ->first();
        }

        return [
            'user'                 => $user,
            'progress'             => $progress?->progress_percentage ?? 0,
            'attendance'           => $progress?->attendance_percentage ?? 0,
            'assignments'          => $progress?->assignment_completion_percentage ?? 0,
            'is_eligible'          => $isEligible,
            'reason'               => $reason,
            'existing_certificate' => $existingCert,
        ];
    }
}
