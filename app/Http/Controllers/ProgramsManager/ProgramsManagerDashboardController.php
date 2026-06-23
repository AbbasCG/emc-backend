<?php

namespace App\Http\Controllers\ProgramsManager;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\Track;
use App\Support\CourseComputedStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProgramsManagerDashboardController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $publishedCourses = Course::where('status', 'published')->count();
        $draftCourses     = Course::where('status', 'draft')->count();
        $totalCourses     = Course::count();
        $endedCourses     = Course::query()
            ->whereIn('status', ['published', 'active'])
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now())
            ->count();

        $totalPaths     = LearningPath::count();
        $publishedPaths = LearningPath::whereIn('status', ['published', 'active'])->count();

        $totalPrograms     = Track::count();
        $publishedPrograms = Track::where('status', 'active')->count();

        $totalRegistrations = Registration::count();
        $pendingStatuses    = ['pending', 'pending_payment', 'pending_review'];
        $pendingRegistrations = Registration::whereIn('status', $pendingStatuses)->count();
        $activeStudents = Registration::whereIn('status', RegistrationStatus::activeEnrollment())
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        $activeInstructors = Instructor::count();

        $upcomingSessionCount = LmsSession::where('session_date', '>=', now()->toDateString())
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->count();

        $pendingReviews = AssignmentSubmission::whereIn('status', ['submitted', 'late'])
            ->whereNull('reviewed_at')
            ->count();
        $needsResubmission = AssignmentSubmission::where('status', 'needs_revision')->count();
        $completedSubmissions = AssignmentSubmission::whereIn('status', ['graded', 'reviewed'])->count();

        $materialsTotal = CourseMaterial::count();

        $attendanceAverage = 0;
        $totalAttendances  = Attendance::count();
        if ($totalAttendances > 0) {
            $presentCount      = Attendance::where('status', 'present')->count();
            $attendanceAverage = round(($presentCount / $totalAttendances) * 100, 1);
        }

        $completionAverage = 0;
        $totalProgress     = StudentProgress::count();
        if ($totalProgress > 0) {
            $completionAverage = round(StudentProgress::avg('progress_percentage') ?? 0, 1);
        }

        $registrationPipeline = $this->registrationPipelineCounts();
        $assignmentStats      = [
            'pending_review'     => $pendingReviews,
            'needs_resubmission' => $needsResubmission,
            'completed'          => $completedSubmissions,
            'total_submissions'  => AssignmentSubmission::count(),
        ];

        $recentCourses = Course::with(['instructor.user'])
            ->withCount('registrations')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Course $c) => [
                'id'                  => $c->id,
                'title'               => $c->title,
                'slug'                => $c->slug,
                'status'              => $c->status ?? 'draft',
                'is_ended'            => CourseComputedStatus::isEnded($c),
                'computed_status'     => CourseComputedStatus::computedStatus($c),
                'status_label_ar'     => CourseComputedStatus::statusLabelAr($c),
                'image_url'           => $c->course_image ? (str_starts_with((string) $c->course_image, 'http') ? $c->course_image : asset('storage/' . ltrim($c->course_image, '/'))) : null,
                'instructor_name'     => $c->instructor?->user?->name ?? $c->instructor?->name,
                'instructor_id'       => $c->instructor_id,
                'registrations_count' => $c->registrations_count,
                'created_at'          => $c->created_at?->toDateTimeString(),
            ]);

        $recentPaths = LearningPath::withCount(['courses', 'enrollments'])
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (LearningPath $p) => [
                'id'             => $p->id,
                'title'          => $p->title,
                'slug'           => $p->slug,
                'status'         => $p->status,
                'courses_count'  => $p->courses_count,
                'students_count' => $p->enrollments_count,
                'featured_image' => $p->featured_image,
            ]);

        $pendingRegs = Registration::with(['user', 'course'])
            ->whereIn('status', $pendingStatuses)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Registration $r) => [
                'id'           => $r->id,
                'student'      => $r->user?->name ?? $r->full_name,
                'email'        => $r->user?->email ?? $r->email,
                'course'       => $r->course?->title,
                'submitted_at' => $r->created_at?->toDateTimeString(),
                'status'       => $r->status,
            ]);

        $upcoming = LmsSession::with(['course', 'instructor.user'])
            ->where('session_date', '>=', now()->toDateString())
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->limit(8)
            ->get()
            ->map(fn (LmsSession $s) => [
                'id'              => $s->id,
                'title'           => $s->title,
                'course_title'    => $s->course?->title,
                'instructor_name' => $s->instructor?->user?->name ?? $s->instructor?->name,
                'session_date'    => $s->session_date?->format('Y-m-d'),
                'start_time'      => $s->start_time,
                'end_time'        => $s->end_time,
                'status'          => $s->status,
                'location'        => $s->location,
                'meeting_url'     => $s->meeting_url,
                'students_count'  => $s->attendances()->count(),
            ]);

        $instructorActivity = Instructor::with(['user'])
            ->withCount('courses')
            ->get()
            ->map(function (Instructor $i) {
                $courseIds = $i->courses()->pluck('id');

                return [
                    'id'                => $i->id,
                    'name'              => $i->user?->name ?? $i->name,
                    'courses_count'     => $i->courses_count,
                    'sessions_count'    => LmsSession::where('instructor_id', $i->id)->count(),
                    'assignments_count' => $courseIds->isEmpty() ? 0 : AssignmentSubmission::whereHas(
                        'assignment',
                        fn ($q) => $q->whereIn('course_id', $courseIds)
                    )->count(),
                    'students_count'    => $courseIds->isEmpty() ? 0 : Registration::whereIn('course_id', $courseIds)
                        ->whereIn('status', RegistrationStatus::activeEnrollment())
                        ->whereNotNull('user_id')
                        ->distinct('user_id')
                        ->count('user_id'),
                ];
            })
            ->sortByDesc('courses_count')
            ->take(6)
            ->values();

        $alerts = $this->buildAlerts(
            $draftCourses,
            $pendingRegistrations,
            $upcomingSessionCount
        );

        $recentActivity = AuditLog::query()
            ->whereIn('entity_type', ['Course', 'LearningPath', 'Registration', 'LmsSession', 'CourseAssignment', 'Track'])
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id'          => $log->id,
                'action'      => $log->action,
                'action_label'=> $log->action_meta['label'] ?? $log->action,
                'entity_type' => $log->entity_type,
                'entity_name' => $log->entity_name,
                'description' => $log->description,
                'user_name'   => $log->user_name,
                'created_at'  => $log->created_at?->toDateTimeString(),
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'summary' => [
                    'programs'               => $totalPrograms,
                    'published_programs'     => $publishedPrograms,
                    'courses'                => $totalCourses,
                    'published_courses'      => $publishedCourses,
                    'ended_courses'          => $endedCourses,
                    'draft_courses'          => $draftCourses,
                    'learning_paths'         => $totalPaths,
                    'published_paths'        => $publishedPaths,
                    'registrations'          => $totalRegistrations,
                    'pending_registrations'  => $pendingRegistrations,
                    'active_students'        => $activeStudents,
                    'active_instructors'     => $activeInstructors,
                    'upcoming_sessions'      => $upcomingSessionCount,
                    'pending_assignments'    => $pendingReviews,
                    'pending_reviews'        => $pendingReviews,
                    'materials_total'        => $materialsTotal,
                    'attendance_average'     => $attendanceAverage,
                    'completion_average'     => $completionAverage,
                ],
                'registration_pipeline' => $registrationPipeline,
                'assignment_stats'        => $assignmentStats,
                'analytics'             => [
                    'registrations_monthly' => $this->monthlyCounts(Registration::query()),
                    'courses_monthly'       => $this->monthlyCounts(Course::query()),
                    'sessions_monthly'      => $this->monthlyCounts(LmsSession::query(), 'session_date'),
                    'paths_monthly'         => $this->monthlyCounts(LearningPath::query()),
                ],
                'courses'               => $recentCourses,
                'learning_paths'        => $recentPaths,
                'pending_registrations' => $pendingRegs,
                'upcoming_sessions'     => $upcoming,
                'instructor_activity'   => $instructorActivity,
                'program_alerts'        => $alerts,
                'recent_activity'       => $recentActivity,
            ],
        ]);
    }

    /** @return array<int, array{status: string, label: string, count: int}> */
    private function registrationPipelineCounts(): array
    {
        $raw = Registration::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = 0;
        $approved = 0;
        $rejected = 0;
        $completed = 0;

        foreach ($raw as $status => $count) {
            $s = strtolower((string) $status);
            if (in_array($s, ['pending', 'pending_payment', 'pending_review'], true)) {
                $pending += (int) $count;
            } elseif (in_array($s, ['registered', 'payment_confirmed', 'approved', 'active'], true)) {
                $approved += (int) $count;
            } elseif (in_array($s, ['cancelled', 'rejected', 'no_show'], true)) {
                $rejected += (int) $count;
            } elseif (in_array($s, ['attended', 'completed'], true)) {
                $completed += (int) $count;
            } else {
                $approved += (int) $count;
            }
        }

        return [
            ['status' => 'pending', 'label' => 'قيد المراجعة', 'count' => $pending],
            ['status' => 'approved', 'label' => 'مقبولة / نشطة', 'count' => $approved],
            ['status' => 'rejected', 'label' => 'مرفوضة / ملغاة', 'count' => $rejected],
            ['status' => 'completed', 'label' => 'مكتملة', 'count' => $completed],
        ];
    }

    /** @return array<int, array{type: string, message: string, count: int, severity: string}> */
    private function buildAlerts(int $draftCourses, int $pendingRegistrations, int $upcomingSessions): array
    {
        $alerts = [];

        $coursesWithoutInstructor = Course::whereNull('instructor_id')->count();
        if ($coursesWithoutInstructor > 0) {
            $alerts[] = [
                'type'     => 'missing_instructor',
                'message'  => "هناك {$coursesWithoutInstructor} دورة بدون مدرب معيّن.",
                'count'    => $coursesWithoutInstructor,
                'severity' => 'warning',
            ];
        }

        $pathsWithoutCourses = LearningPath::doesntHave('courses')->count();
        if ($pathsWithoutCourses > 0) {
            $alerts[] = [
                'type'     => 'empty_learning_path',
                'message'  => "هناك {$pathsWithoutCourses} مسار تعليمي بدون دورات.",
                'count'    => $pathsWithoutCourses,
                'severity' => 'warning',
            ];
        }

        $sessionsMissingLink = LmsSession::where('session_date', '>=', now()->toDateString())
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where(function ($q) {
                $q->whereNull('meeting_url')->orWhere('meeting_url', '');
            })
            ->count();
        if ($sessionsMissingLink > 0) {
            $alerts[] = [
                'type'     => 'missing_meeting_link',
                'message'  => "هناك {$sessionsMissingLink} جلسة قادمة بدون رابط اجتماع.",
                'count'    => $sessionsMissingLink,
                'severity' => 'action',
            ];
        }

        if ($draftCourses > 0) {
            $alerts[] = [
                'type'     => 'draft_courses',
                'message'  => "هناك {$draftCourses} دورة في وضع المسودة.",
                'count'    => $draftCourses,
                'severity' => 'info',
            ];
        }

        if ($pendingRegistrations > 0) {
            $alerts[] = [
                'type'     => 'pending_registrations',
                'message'  => "هناك {$pendingRegistrations} طلب تسجيل بانتظار المراجعة.",
                'count'    => $pendingRegistrations,
                'severity' => 'action',
            ];
        }

        if ($upcomingSessions === 0) {
            $alerts[] = [
                'type'     => 'no_upcoming_sessions',
                'message'  => 'لا توجد جلسات مجدولة قادمة حالياً.',
                'count'    => 0,
                'severity' => 'info',
            ];
        }

        return $alerts;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @return array<int, array{month: string, label: string, count: int}>
     */
    private function monthlyCounts($query, string $dateColumn = 'created_at'): array
    {
        $months = [];
        $labels = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

        for ($i = 5; $i >= 0; $i--) {
            $start = now()->subMonths($i)->startOfMonth();
            $end   = now()->subMonths($i)->endOfMonth();
            $count = (clone $query)->whereBetween($dateColumn, [$start, $end])->count();
            $months[] = [
                'month' => $start->format('Y-m'),
                'label' => $labels[(int) $start->format('n') - 1] . ' ' . $start->format('Y'),
                'count' => $count,
            ];
        }

        return $months;
    }
}
