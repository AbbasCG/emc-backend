<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\StudentProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentRegistrationsController extends Controller
{
    /**
     * GET /api/student/registrations
     *
     * All registrations (all statuses), matched by user_id OR email.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $registrations = Registration::query()
            ->with(['course.instructor.user', 'course.track', 'payments'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('email', $user->email);
            })
            ->latest()
            ->get();

        $courseIds       = $registrations->pluck('course_id')->unique()->filter()->values();
        $nextSessionsMap = $this->nextSessionsForCourses($courseIds->all());

        $data = $registrations->map(fn ($reg) => [
            'id'             => $reg->id,
            'user_id'        => $reg->user_id,
            'course_id'      => $reg->course_id,
            'full_name'      => $reg->full_name,
            'email'          => $reg->email,
            'status'         => $reg->status,
            'payment_status' => $reg->payments->last()?->status
                ?? ($reg->course?->type === 'free' ? 'free' : null),
            'registered_at'  => $reg->created_at,
            'next_session'   => $nextSessionsMap[$reg->course_id] ?? null,
            'course'         => $reg->course ? [
                'id'           => $reg->course->id,
                'title'        => $reg->course->title,
                'slug'         => $reg->course->slug,
                'image_url'    => $this->storageUrl($reg->course->course_image),
                'type'         => $reg->course->type,
                'price'        => (float) $reg->course->price,
                'start_date'   => $reg->course->start_date?->format('Y-m-d'),
                'start_time'   => $reg->course->start_time,
                'location_type'=> $reg->course->location_type,
                'meeting_link' => $reg->course->meeting_link,
                'track'        => $reg->course->track
                    ? ['id' => $reg->course->track->id, 'name' => $reg->course->track->title]
                    : null,
                'instructor'   => $reg->course->instructor ? [
                    'id'   => $reg->course->instructor->id,
                    'name' => $reg->course->instructor->user?->name
                           ?? $reg->course->instructor->name,
                ] : null,
            ] : null,
        ])->values()->all();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    private function storageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        return asset('storage/' . ltrim($path, '/'));
    }

    /**
     * GET /api/student/courses
     *
     * Active enrolled courses (status=Registered) with full detail.
     */
    public function courses(Request $request): JsonResponse
    {
        $user = $request->user();

        $registrations = Registration::query()
            ->with(['course.track', 'course.instructor.user', 'payments'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('email', $user->email);
            })
            ->where('status', 'Registered')
            ->latest()
            ->get();

        $courseIds       = $registrations->pluck('course_id')->unique()->filter()->values();
        $nextSessionsMap = $this->nextSessionsForCourses($courseIds->all());

        // Pre-load progress and certificates for enrolled courses in bulk
        $progressMap = StudentProgress::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds->all())
            ->get()
            ->keyBy('course_id');

        $certificateMap = Certificate::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds->all())
            ->whereIn('status', ['issued', 'approved'])
            ->get()
            ->keyBy('course_id');

        $data = $registrations->map(fn ($reg) => [
            'course_id'           => $reg->course_id,
            'title'               => $reg->course?->title,
            'slug'                => $reg->course?->slug,
            'description'         => $reg->course?->description,
            'type'                => $reg->course?->type,
            'level'               => $reg->course?->level,
            'thumbnail'           => $reg->course?->course_image,
            'location_type'       => $reg->course?->location_type,
            'meeting_link'        => $reg->course?->meeting_link,
            'start_date'          => $reg->course?->start_date,
            'end_date'            => $reg->course?->end_date,
            'next_cohort_pending' => $reg->course?->start_date === null,
            'track'               => $reg->course?->track ? [
                'id'   => $reg->course->track->id,
                'name' => $reg->course->track->title,
            ] : null,
            'instructor_name'     => $reg->course?->instructor?->user?->name
                ?? $reg->course?->instructor?->name,
            'registration_id'     => $reg->id,
            'registration_status' => $reg->status,
            'payment_status'      => $reg->payments->last()?->status ?? ($reg->course?->type === 'free' ? 'free' : null),
            'registered_at'       => $reg->created_at,
            'next_session'        => $nextSessionsMap[$reg->course_id] ?? null,
            'progress'            => $progressMap[$reg->course_id] ? [
                'percentage'                     => $progressMap[$reg->course_id]->progress_percentage,
                'completed_sessions'             => $progressMap[$reg->course_id]->completed_sessions,
                'total_sessions'                 => $progressMap[$reg->course_id]->total_sessions,
                'attendance_percentage'          => $progressMap[$reg->course_id]->attendance_percentage,
                'assignment_completion_percentage' => $progressMap[$reg->course_id]->assignment_completion_percentage,
                'status'                         => $progressMap[$reg->course_id]->status,
            ] : null,
            'certificate_status'  => $certificateMap[$reg->course_id]?->status ?? null,
        ])->values()->all();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/student/available-courses
     *
     * Publicly registered courses open for enrollment.
     */
    public function availableCourses(Request $request): JsonResponse
    {
        $user = $request->user();

        // Courses the student already registered for (to exclude)
        $enrolledCourseIds = Registration::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('email', $user->email);
        })->whereNotIn('status', ['Cancelled'])->pluck('course_id')->all();

        $query = \App\Models\Course::with(['instructor.user', 'track'])
            ->whereIn('status', ['published', 'active', 'scheduled'])
            ->where('registration_open', true)
            ->whereNotIn('id', $enrolledCourseIds);

        if ($request->filled('track_id')) {
            $query->where('track_id', $request->query('track_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('search')) {
            $q = (string) $request->query('search');
            $query->where(fn ($s) => $s->where('title', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"));
        }

        $perPage = min((int) $request->query('per_page', 15), 50);
        $courses = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $courses->map(fn ($c) => [
                'id'                  => $c->id,
                'title'               => $c->title,
                'slug'                => $c->slug,
                'description'         => $c->description,
                'short_description'   => $c->short_description,
                'type'                => $c->type,
                'price'               => (float) $c->price,
                'level'               => $c->level,
                'language'            => $c->language,
                'course_image'        => $c->course_image,
                'location_type'       => $c->location_type,
                'start_date'          => $c->start_date,
                'next_cohort_pending' => $c->start_date === null,
                'instructor_name'     => $c->instructor?->user?->name ?? $c->instructor?->name,
                'track'               => $c->track ? ['id' => $c->track->id, 'title' => $c->track->title] : null,
            ]),
            'meta' => [
                'total'        => $courses->total(),
                'current_page' => $courses->currentPage(),
                'last_page'    => $courses->lastPage(),
            ],
        ]);
    }

    /** Returns a map of course_id => next scheduled session for the given course IDs. */
    private function nextSessionsForCourses(array $courseIds): array
    {
        if (empty($courseIds)) {
            return [];
        }

        $sessions = LmsSession::query()
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                  ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->whereIn('status', ['scheduled', 'live'])
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->with('workshop:id,course_id')
            ->get(['id', 'course_id', 'workshop_id', 'title', 'session_date', 'start_time', 'end_time', 'status', 'location']);

        $map = [];
        foreach ($sessions as $session) {
            $cid = $session->course_id ?? $session->workshop?->course_id;
            if ($cid && ! isset($map[$cid])) {
                $map[$cid] = [
                    'id'           => $session->id,
                    'title'        => $session->title,
                    'session_date' => $session->session_date,
                    'start_time'   => $session->start_time,
                    'end_time'     => $session->end_time,
                    'status'       => $session->status,
                    'location'     => $session->location,
                ];
            }
        }

        return $map;
    }
}
