<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\OralAssessmentBookingResource;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Services\NotificationService;
use App\Services\PlacementScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstructorPlacementController extends Controller
{
    // ─── GET /api/instructor/courses/{course}/placement-students ─────────────
    //
    // All enrolled students for this course with placement data.
    //
    // Auth rule: admins see any course; instructors only their own.
    // Bug fix: use (int) cast for instructor_id comparison — MySQL may return
    // the foreign key as a string which causes strict `!==` to incorrectly fail.

    public function students(Request $request, Course $course): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();

        if ($instructor && (int) $course->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذه الدورة.');
        }

        // Load registrations (includes those without a linked user account)
        $registrations = Registration::with('user')
            ->where('course_id', $course->id)
            ->whereNotIn('status', ['Rejected', 'Cancelled'])
            ->get();

        // Collect user IDs that can have placement attempts (API-based test requires auth)
        $registeredUserIds = $registrations->pluck('user_id')->filter()->unique()->values()->all();

        // Use explicit string casting for keyBy to prevent int/string key mismatch
        // between Registration.user_id and PlacementTestAttempt.user_id.
        $attempts = PlacementTestAttempt::where('course_id', $course->id)
            ->whereIn('user_id', $registeredUserIds)
            ->get()
            ->keyBy(fn ($a) => (string) $a->user_id);

        $bookings = OralAssessmentBooking::with('slot')
            ->where('course_id', $course->id)
            ->whereIn('user_id', $registeredUserIds)
            ->get()
            ->keyBy(fn ($b) => (string) $b->user_id);

        $students = $registrations->map(function ($reg) use ($attempts, $bookings) {
            $attempt = $reg->user_id ? $attempts->get((string) $reg->user_id) : null;
            $booking = $reg->user_id ? $bookings->get((string) $reg->user_id) : null;

            $status = $this->deriveStatus($attempt, $booking);

            $levelDetails = ($attempt && $attempt->isCompleted() && $attempt->estimated_level)
                ? PlacementScoringService::levelFromCode($attempt->estimated_level)
                : null;

            return [
                'student_id'          => $reg->user_id,
                'student_name'        => $reg->user?->name ?? $reg->full_name,
                'student_email'       => $reg->user?->email ?? $reg->email,
                'enrollment_status'   => strtolower($reg->status),
                'status'              => $status,
                'written_status_label'=> $this->statusLabel($status),
                'placement_attempt'   => ($attempt && $attempt->isCompleted()) ? [
                    'id'                   => $attempt->id,
                    'score'                => $attempt->score,
                    'total_questions'      => $attempt->total_questions,
                    'percentage'           => $attempt->percentage,
                    'estimated_level'      => $attempt->estimated_level,
                    'estimated_level_code' => $levelDetails ? $levelDetails['code'] : null,
                    'estimated_level_en'   => $levelDetails ? $levelDetails['en']   : null,
                    'estimated_level_ar'   => $levelDetails ? $levelDetails['ar']   : null,
                    'submitted_at'         => $attempt->submitted_at?->toIso8601String(),
                ] : null,
                'oral_booking'        => $booking ? [
                    'id'               => $booking->id,
                    'status'           => $booking->status,
                    'starts_at'        => $booking->slot?->starts_at->toIso8601String(),
                    'ends_at'          => $booking->slot?->ends_at->toIso8601String(),
                    'final_level'      => $booking->final_level,
                    'oral_score'       => $booking->oral_score,
                    'instructor_notes' => $booking->instructor_notes,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $students,
            'meta'    => [
                'total'           => $students->count(),
                'not_started'     => $students->where('status', 'not_started')->count(),
                'in_progress'     => $students->where('status', 'in_progress')->count(),
                'waiting_oral'    => $students->where('status', 'waiting_oral')->count(),
                'oral_scheduled'  => $students->where('status', 'oral_scheduled')->count(),
                'completed'       => $students->where('status', 'completed')->count(),
            ],
        ]);
    }

    // ─── GET /api/instructor/oral-assessments ────────────────────────────────
    //
    // All oral assessment bookings assigned to this instructor.

    public function assessments(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();

        $query = OralAssessmentBooking::with(['slot', 'user', 'attempt', 'course'])
            ->when($instructor, fn ($q) => $q->where('instructor_id', $instructor->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', (int) $request->course_id))
            ->latest();

        $bookings = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $bookings->map(function ($b) {
                $levelDetails = ($b->attempt && $b->attempt->estimated_level)
                    ? PlacementScoringService::levelFromCode($b->attempt->estimated_level)
                    : null;

                $slot = $b->slot;

                return [
                    'id'                   => $b->id,
                    'course_id'            => $b->course_id,
                    'course_title'         => $b->course?->title,
                    'student_id'           => $b->user_id,
                    'student_name'         => $b->user?->name,
                    'student_email'        => $b->user?->email,
                    'starts_at'            => $slot?->starts_at->toIso8601String(),
                    'ends_at'              => $slot?->ends_at->toIso8601String(),
                    'formatted_date'       => $slot?->starts_at->format('d/m/Y'),
                    'formatted_time'       => $slot
                        ? $slot->starts_at->format('H:i') . ' - ' . $slot->ends_at->format('H:i')
                        : null,
                    'meeting_link'         => $slot?->meeting_link,
                    'status'               => $b->status,
                    'oral_score'           => $b->oral_score,
                    'final_level'          => $b->final_level,
                    'instructor_notes'     => $b->instructor_notes,
                    'written_score'        => $b->attempt?->score,
                    'written_total'        => $b->attempt?->total_questions,
                    'estimated_level_code' => $levelDetails ? $levelDetails['code'] : null,
                    'estimated_level_ar'   => $levelDetails ? $levelDetails['ar']   : null,
                    'created_at'           => $b->created_at->toIso8601String(),
                ];
            }),
            'meta'    => [
                'total'        => $bookings->total(),
                'current_page' => $bookings->currentPage(),
                'last_page'    => $bookings->lastPage(),
            ],
        ]);
    }

    // ─── GET /api/instructor/placement-tests ─────────────────────────────────
    //
    // All placement test attempts across courses assigned to this instructor.
    // Query params: course_id, status, per_page (default 20)

    public function placementTests(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();

        $courseIds = $instructor
            ? Course::where('instructor_id', $instructor->id)->pluck('id')->all()
            : [];

        if (empty($courseIds)) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['total' => 0, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        $attempts = PlacementTestAttempt::with(['user', 'course'])
            ->whereIn('course_id', $courseIds)
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', (int) $request->course_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $attempts->map(function ($a) {
                $levelDetails = ($a->isCompleted() && $a->estimated_level)
                    ? PlacementScoringService::levelFromCode($a->estimated_level)
                    : null;

                return [
                    'id'                   => $a->id,
                    'course_id'            => $a->course_id,
                    'course_title'         => $a->course?->title,
                    'student_id'           => $a->user_id,
                    'student_name'         => $a->user?->name,
                    'student_email'        => $a->user?->email,
                    'status'               => $a->status,
                    'score'                => $a->score,
                    'total_questions'      => $a->total_questions,
                    'percentage'           => $a->percentage,
                    'estimated_level_code' => $levelDetails ? $levelDetails['code'] : null,
                    'estimated_level_en'   => $levelDetails ? $levelDetails['en']   : null,
                    'estimated_level_ar'   => $levelDetails ? $levelDetails['ar']   : null,
                    'submitted_at'         => $a->submitted_at?->toIso8601String(),
                ];
            }),
            'meta' => [
                'total'        => $attempts->total(),
                'current_page' => $attempts->currentPage(),
                'last_page'    => $attempts->lastPage(),
                'per_page'     => $attempts->perPage(),
            ],
        ]);
    }

    // ─── PATCH /api/instructor/oral-assessments/{booking}/complete ───────────
    //
    // Mark a booking as completed and record the final level.

    public function complete(Request $request, OralAssessmentBooking $booking): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();

        if ($instructor && (int) $booking->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية تقييم هذا الطالب.');
        }

        if ($booking->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'تم اكتمال هذا التقييم مسبقاً.',
            ], 422);
        }

        $request->validate([
            'oral_score'       => ['required', 'integer', 'min:0', 'max:100'],
            'final_level'      => ['required', 'string', 'in:Starter,A1,A2,B1,B2,C1'],
            'instructor_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $booking) {
            $booking->update([
                'oral_score'       => $request->oral_score,
                'final_level'      => $request->final_level,
                'instructor_notes' => $request->instructor_notes,
                'status'           => 'completed',
            ]);

            $booking->slot?->update(['booking_status' => 'completed']);
        });

        $booking->load('slot', 'user', 'attempt', 'course');

        NotificationService::oralAssessmentCompleted(
            $booking->user_id,
            $booking->course?->title ?? '',
            $booking->final_level,
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل نتيجة المقابلة الشفهية بنجاح.',
            'data'    => new OralAssessmentBookingResource($booking),
        ]);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

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

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'not_started'    => 'لم يبدأ الاختبار بعد',
            'in_progress'    => 'الاختبار جارٍ',
            'waiting_oral'   => 'بانتظار المقابلة الشفوية',
            'oral_scheduled' => 'المقابلة الشفوية محجوزة',
            'completed'      => 'اكتمل التقييم',
            default          => $status,
        };
    }
}
