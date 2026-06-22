<?php

namespace App\Http\Controllers\ProgramsManager;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\LearningPath;
use App\Models\LmsSession;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;

class ProgramsManagerDashboardController extends Controller
{
    public function dashboard(): JsonResponse
    {
        // ── Counts ──────────────────────────────────────────────────────────────
        $totalCourses        = Course::count();
        $publishedCourses    = Course::where('is_published', true)->count();
        $draftCourses        = Course::where('is_published', false)->count();

        $totalPaths          = LearningPath::count();
        $publishedPaths      = LearningPath::where('status', 'published')->count();

        $totalRegistrations  = Registration::count();
        $pendingRegistrations = Registration::where('status', 'pending')->count();
        $activeStudents      = Registration::where('status', 'approved')
            ->distinct('user_id')->count('user_id');

        $upcomingSessionCount = LmsSession::where('session_date', '>=', now()->toDateString())
            ->where('status', '!=', 'cancelled')
            ->count();

        $pendingAssignments  = CourseAssignment::whereNull('due_date')
            ->orWhere('due_date', '>=', now())
            ->count();

        // ── Recent Courses ───────────────────────────────────────────────────────
        $recentCourses = Course::with(['instructor.user'])
            ->withCount('registrations')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Course $c) => [
                'id'                  => $c->id,
                'title'               => $c->title,
                'slug'                => $c->slug,
                'status'              => $c->is_published ? 'published' : 'draft',
                'image_url'           => $c->course_image_url ?? null,
                'instructor_name'     => $c->instructor?->user?->name,
                'registrations_count' => $c->registrations_count,
            ]);

        // ── Recent Learning Paths ────────────────────────────────────────────────
        $recentPaths = LearningPath::withCount('courses')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (LearningPath $p) => [
                'id'             => $p->id,
                'title'          => $p->title,
                'slug'           => $p->slug,
                'status'         => $p->status,
                'courses_count'  => $p->courses_count,
                'featured_image' => $p->featured_image,
            ]);

        // ── Pending Registrations ────────────────────────────────────────────────
        $pendingRegs = Registration::with(['user', 'course'])
            ->where('status', 'pending')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Registration $r) => [
                'id'          => $r->id,
                'student'     => $r->user?->name,
                'email'       => $r->user?->email ?? $r->email,
                'course'      => $r->course?->title ?? $r->course_name,
                'submitted_at' => $r->created_at?->toDateTimeString(),
                'status'      => $r->status,
            ]);

        // ── Upcoming Sessions ────────────────────────────────────────────────────
        $upcoming = LmsSession::with(['course'])
            ->where('session_date', '>=', now()->toDateString())
            ->where('status', '!=', 'cancelled')
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->limit(5)
            ->get()
            ->map(fn (LmsSession $s) => [
                'id'           => $s->id,
                'course_title' => $s->course?->title,
                'session_date' => $s->session_date,
                'start_time'   => $s->start_time,
                'end_time'     => $s->end_time,
                'status'       => $s->status,
                'location'     => $s->location,
                'meeting_url'  => $s->meeting_url,
            ]);

        // ── Program Alerts ────────────────────────────────────────────────────────
        $alerts = [];

        $coursesWithoutInstructor = Course::whereNull('instructor_id')->count();
        if ($coursesWithoutInstructor > 0) {
            $alerts[] = [
                'type'    => 'missing_instructor',
                'message' => "هناك {$coursesWithoutInstructor} دورة بدون مدرب.",
                'count'   => $coursesWithoutInstructor,
                'severity' => 'warning',
            ];
        }

        $pathsWithoutCourses = LearningPath::doesntHave('courses')->count();
        if ($pathsWithoutCourses > 0) {
            $alerts[] = [
                'type'    => 'empty_learning_path',
                'message' => "هناك {$pathsWithoutCourses} مسار تعليمي بدون دورات.",
                'count'   => $pathsWithoutCourses,
                'severity' => 'warning',
            ];
        }

        if ($draftCourses > 0) {
            $alerts[] = [
                'type'    => 'draft_courses',
                'message' => "هناك {$draftCourses} دورة في وضع المسودة.",
                'count'   => $draftCourses,
                'severity' => 'info',
            ];
        }

        if ($pendingRegistrations > 0) {
            $alerts[] = [
                'type'    => 'pending_registrations',
                'message' => "هناك {$pendingRegistrations} طلب تسجيل بانتظار المراجعة.",
                'count'   => $pendingRegistrations,
                'severity' => 'action',
            ];
        }

        return response()->json([
            'success' => true,
            'summary' => [
                'courses'              => $totalCourses,
                'published_courses'    => $publishedCourses,
                'draft_courses'        => $draftCourses,
                'learning_paths'       => $totalPaths,
                'published_paths'      => $publishedPaths,
                'registrations'        => $totalRegistrations,
                'pending_registrations' => $pendingRegistrations,
                'active_students'      => $activeStudents,
                'upcoming_sessions'    => $upcomingSessionCount,
                'pending_assignments'  => $pendingAssignments,
            ],
            'courses'               => $recentCourses,
            'learning_paths'        => $recentPaths,
            'pending_registrations' => $pendingRegs,
            'upcoming_sessions'     => $upcoming,
            'program_alerts'        => $alerts,
            'recent_activity'       => [],
        ]);
    }
}
