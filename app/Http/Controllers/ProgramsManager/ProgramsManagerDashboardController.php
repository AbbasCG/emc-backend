<?php

namespace App\Http\Controllers\ProgramsManager;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\LearningPath;
use App\Models\LmsSession;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProgramsManagerDashboardController extends Controller
{
    public function dashboard(): JsonResponse
    {
        // ── Counts ──────────────────────────────────────────────────────────────
        $totalCourses      = Course::count();
        $publishedCourses  = Course::where('is_published', true)->count();
        $draftCourses      = Course::where('is_published', false)->count();

        $totalPaths        = LearningPath::count();
        $activePaths       = LearningPath::where('status', 'published')->count();

        $totalRegistrations = Registration::count();
        $totalStudents      = Registration::distinct('user_id')->count('user_id');

        $upcomingSessions   = LmsSession::where('session_date', '>=', now()->toDateString())
            ->where('status', '!=', 'cancelled')
            ->count();

        $activeAssignments  = CourseAssignment::whereNull('due_date')
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
        $recentPaths = LearningPath::with(['instructor.user'])
            ->withCount('courses')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (LearningPath $p) => [
                'id'            => $p->id,
                'title'         => $p->title,
                'slug'          => $p->slug,
                'status'        => $p->status,
                'courses_count' => $p->courses_count,
                'featured_image' => $p->featured_image,
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

        // ── Warnings ────────────────────────────────────────────────────────────
        $warnings = [];

        $coursesWithoutInstructor = Course::whereNull('instructor_id')->count();
        if ($coursesWithoutInstructor > 0) {
            $warnings[] = [
                'type'    => 'missing_instructor',
                'message' => "هناك {$coursesWithoutInstructor} دورة بدون مدرب.",
                'count'   => $coursesWithoutInstructor,
            ];
        }

        $pathsWithoutCourses = LearningPath::doesntHave('courses')->count();
        if ($pathsWithoutCourses > 0) {
            $warnings[] = [
                'type'    => 'empty_learning_path',
                'message' => "هناك {$pathsWithoutCourses} مسار تعليمي بدون دورات.",
                'count'   => $pathsWithoutCourses,
            ];
        }

        if ($draftCourses > 0) {
            $warnings[] = [
                'type'    => 'draft_courses',
                'message' => "هناك {$draftCourses} دورة في وضع المسودة.",
                'count'   => $draftCourses,
            ];
        }

        return response()->json([
            'success' => true,
            'counts'  => [
                'courses'              => $totalCourses,
                'published_courses'    => $publishedCourses,
                'draft_courses'        => $draftCourses,
                'learning_paths'       => $totalPaths,
                'active_learning_paths' => $activePaths,
                'registrations'        => $totalRegistrations,
                'students'             => $totalStudents,
                'sessions'             => $upcomingSessions,
                'assignments'          => $activeAssignments,
            ],
            'recent_courses'        => $recentCourses,
            'recent_learning_paths' => $recentPaths,
            'upcoming_sessions'     => $upcoming,
            'warnings'              => $warnings,
        ]);
    }
}
