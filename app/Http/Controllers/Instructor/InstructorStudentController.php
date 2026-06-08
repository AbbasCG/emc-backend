<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Services\PlacementScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorStudentController extends Controller
{
    // ─── GET /api/instructor/students ────────────────────────────────────────
    //
    // All students from all courses assigned to this instructor.
    // Includes placement status, written score/level, and oral booking.
    //
    // Query params:
    //   q         — search by name or email
    //   course_id — filter to a single course

    public function index(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();

        $courseIds = $instructor
            ? Course::where('instructor_id', $instructor->id)->pluck('id')->all()
            : [];

        if (empty($courseIds)) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['total' => 0],
            ]);
        }

        $courses = Course::whereIn('id', $courseIds)->get()->keyBy('id');

        $query = Registration::with('user')
            ->whereIn('course_id', $courseIds)
            ->whereNotIn('status', ['Rejected', 'Cancelled'])
            ->orderBy('course_id')
            ->orderByDesc('created_at');

        if ($request->filled('q')) {
            $term = $request->q;
            $query->where(fn ($q) => $q
                ->where('full_name', 'like', "%{$term}%")
                ->orWhere('email',   'like', "%{$term}%")
            );
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', (int) $request->course_id);
        }

        $registrations = $query->get();

        $userIds = $registrations->pluck('user_id')->filter()->unique()->values()->all();

        // Key by "course_id_user_id" to handle same student in multiple courses correctly
        $attempts = PlacementTestAttempt::whereIn('course_id', $courseIds)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($a) => (string) $a->course_id . '_' . (string) $a->user_id);

        $bookings = OralAssessmentBooking::whereIn('course_id', $courseIds)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($b) => (string) $b->course_id . '_' . (string) $b->user_id);

        $data = $registrations->map(function ($reg) use ($courses, $attempts, $bookings) {
            $course  = $courses->get($reg->course_id);
            $key     = (string) $reg->course_id . '_' . (string) $reg->user_id;
            $attempt = $reg->user_id ? $attempts->get($key) : null;
            $booking = $reg->user_id ? $bookings->get($key) : null;

            $requiresPlacement = (bool) ($course?->requires_placement_test ?? false);
            $placementStatus   = $requiresPlacement ? $this->deriveStatus($attempt, $booking) : null;

            $levelDetails = ($attempt && $attempt->isCompleted() && $attempt->estimated_level)
                ? PlacementScoringService::levelFromCode($attempt->estimated_level)
                : null;

            return [
                'student_id'        => $reg->user_id,
                'student_name'      => $reg->user?->name ?? $reg->full_name,
                'student_email'     => $reg->user?->email ?? $reg->email,
                'student_phone'     => $reg->user?->phone ?? $reg->phone,
                'course_id'         => $reg->course_id,
                'course_title'      => $course?->title,
                'enrollment_status' => strtolower($reg->status),
                'placement_status'  => $placementStatus,
                'placement_attempt' => ($attempt && $attempt->isCompleted()) ? [
                    'score'                => $attempt->score,
                    'total_questions'      => $attempt->total_questions,
                    'percentage'           => $attempt->percentage,
                    'estimated_level'      => $attempt->estimated_level,
                    'estimated_level_code' => $levelDetails ? $levelDetails['code'] : null,
                    'estimated_level_en'   => $levelDetails ? $levelDetails['en']   : null,
                    'estimated_level_ar'   => $levelDetails ? $levelDetails['ar']   : null,
                    'submitted_at'         => $attempt->submitted_at?->toIso8601String(),
                ] : null,
                'oral_booking' => $booking ? [
                    'id'          => $booking->id,
                    'status'      => $booking->status,
                    'final_level' => $booking->final_level,
                ] : null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'total'          => $data->count(),
                'not_started'    => $data->where('placement_status', 'not_started')->count(),
                'in_progress'    => $data->where('placement_status', 'in_progress')->count(),
                'waiting_oral'   => $data->where('placement_status', 'waiting_oral')->count(),
                'oral_scheduled' => $data->where('placement_status', 'oral_scheduled')->count(),
                'completed'      => $data->where('placement_status', 'completed')->count(),
            ],
        ]);
    }

    private function deriveStatus(?PlacementTestAttempt $attempt, ?OralAssessmentBooking $booking): string
    {
        if (! $attempt) {
            return 'not_started';
        }
        if (! $attempt->isCompleted()) {
            return 'in_progress';
        }
        if (! $booking) {
            return 'waiting_oral';
        }
        if ($booking->isCompleted()) {
            return 'completed';
        }
        return 'oral_scheduled';
    }
}
