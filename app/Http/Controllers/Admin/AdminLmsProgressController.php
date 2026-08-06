<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentProgressResource;
use App\Models\Attendance;
use App\Models\AssignmentSubmission;
use App\Models\Certificate;
use App\Models\LearningPathEnrollment;
use App\Models\StudentProgress;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminLmsProgressController extends Controller
{
    public function student(Request $request, User $user): JsonResponse
    {
        // Course progress
        $progressRecords = StudentProgress::query()
            ->with(['course'])
            ->where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->get();

        // Attendance records
        $attendanceRecords = Attendance::query()
            ->with(['session', 'courseSession'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        // Assignment submissions
        $submissionRecords = AssignmentSubmission::query()
            ->with(['assignment'])
            ->where('user_id', $user->id)
            ->orderBy('submitted_at', 'desc')
            ->limit(50)
            ->get();

        // Learning path enrollments
        $pathEnrollments = LearningPathEnrollment::query()
            ->with(['learningPath'])
            ->where('user_id', $user->id)
            ->get();

        // Certificates
        $certificates = Certificate::query()
            ->with(['course'])
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->get();

        // Summary aggregation
        $totalCourses     = $progressRecords->count();
        $completedCourses = $progressRecords->where('status', 'completed')->count();
        $inProgress       = $progressRecords->where('status', 'in_progress')->count();
        $avgProgress      = $totalCourses
            ? (int) round($progressRecords->avg('progress_percentage') ?? 0)
            : 0;

        $attendedSessions = $attendanceRecords->whereIn('status', ['present', 'attended'])->count();
        $missedSessions   = $attendanceRecords->where('status', 'absent')->count();
        $totalSessions    = $attendanceRecords->count();
        $attendancePct    = $totalSessions
            ? (int) round($attendedSessions / $totalSessions * 100)
            : 0;

        $submittedCount = $submissionRecords->whereNotNull('submitted_at')->count();
        $pendingCount   = $submissionRecords->whereNull('submitted_at')->count();
        $avgScore       = $submissionRecords->whereNotNull('score')->avg('score');
        $avgScore       = $avgScore !== null ? round((float) $avgScore, 1) : null;

        $lastActivity = $progressRecords->max('updated_at');

        // Risk level: at-risk if avg progress < 30% and in-progress courses exist
        $riskLevel = ($inProgress > 0 && $avgProgress < 30) ? 'at_risk'
            : ($inProgress > 0 && $avgProgress < 60 ? 'watch' : 'on_track');

        return response()->json([
            'student' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'avatar_url' => $user->avatar_url ?? null,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'summary' => [
                'total_courses'        => $totalCourses,
                'completed_courses'    => $completedCourses,
                'in_progress_courses'  => $inProgress,
                'avg_progress'         => $avgProgress,
                'total_sessions'       => $totalSessions,
                'attended_sessions'    => $attendedSessions,
                'missed_sessions'      => $missedSessions,
                'attendance_pct'       => $attendancePct,
                'assignments_submitted'=> $submittedCount,
                'assignments_pending'  => $pendingCount,
                'avg_assignment_score' => $avgScore,
                'certificates_count'   => $certificates->count(),
                'last_activity_at'     => $lastActivity?->toIso8601String() ?? null,
                'risk_level'           => $riskLevel,
            ],
            'courses' => $progressRecords->map(fn ($p) => [
                'course_id'        => $p->course_id,
                'title'            => $p->course?->title ?? '—',
                'progress'         => $p->progress_percentage ?? 0,
                'status'           => $p->status ?? 'in_progress',
                'completed_at'     => $p->completed_at?->toIso8601String(),
                'last_activity_at' => $p->updated_at?->toIso8601String(),
                'completed_sessions' => $p->completed_sessions,
                'total_sessions'     => $p->total_sessions,
            ])->values(),
            'learning_paths' => $pathEnrollments->map(fn ($e) => [
                'path_id'      => $e->learning_path_id,
                'title'        => $e->learningPath?->title ?? '—',
                'status'       => $e->status ?? 'enrolled',
                'enrolled_at'  => $e->enrolled_at?->toIso8601String(),
                'completed_at' => $e->completed_at?->toIso8601String(),
            ])->values(),
            'attendance' => $attendanceRecords->map(fn ($a) => [
                'session_title' => $a->session?->title ?? $a->courseSession?->title ?? '—',
                'date'          => ($a->checked_in_at ?? $a->created_at)?->toIso8601String(),
                'status'        => $a->status ?? 'unknown',
            ])->values(),
            'assignments' => $submissionRecords->map(fn ($s) => [
                'title'        => $s->assignment?->title ?? '—',
                'status'       => $s->status ?? 'pending',
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'score'        => $s->score !== null ? (float) $s->score : null,
                'feedback'     => $s->feedback,
            ])->values(),
            'evaluations' => $certificates->map(fn ($c) => [
                'id'           => $c->id,
                'title'        => $c->title ?? ($c->course?->title ?? 'شهادة'),
                'type'         => $c->certificate_type ?? 'completion',
                'issued_at'    => $c->issued_at?->toIso8601String(),
                'status'       => $c->status ?? 'issued',
            ])->values(),
            'activity' => $progressRecords->take(10)->map(fn ($p) => [
                'date'        => $p->updated_at?->toIso8601String(),
                'type'        => 'progress',
                'description' => sprintf(
                    'تقدم %d%% في دورة "%s"',
                    $p->progress_percentage ?? 0,
                    $p->course?->title ?? '—',
                ),
            ])->values(),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StudentProgress::query()->with(['course', 'user'])->orderBy('updated_at', 'desc');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->query('course_id'));
        }

        if ($request->filled('track_id')) {
            $query->where('track_id', $request->query('track_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 30), 100);

        return StudentProgressResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Student progress retrieved successfully']);
    }
}
