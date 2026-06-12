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
use App\Services\PlacementProgressService;
use App\Services\PlacementScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstructorPlacementController extends Controller
{
    public function __construct(private readonly PlacementProgressService $placement) {}

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

        if (! $course->requires_placement_test) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الدورة لا تتطلب اختبار تحديد مستوى.',
                'data'    => [],
            ], 422);
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
            $attempt  = $reg->user_id ? $attempts->get((string) $reg->user_id) : null;
            $booking  = $reg->user_id ? $bookings->get((string) $reg->user_id) : null;
            $progress = $this->placement->progress($attempt, $booking);

            return [
                'student_id'          => $reg->user_id,
                'student_name'        => $reg->user?->name ?? $reg->full_name,
                'student_email'       => $reg->user?->email ?? $reg->email,
                'enrollment_status'   => strtolower($reg->status),
                'status_label'        => $this->placement->statusLabel($progress['status']),
                // Flat IDs — normalizer uses these directly before falling back to nested progress
                'attempt_id'          => $attempt?->id,
                'booking_id'          => $booking?->id,
                // Flat fields so frontend normalizers don't need placement_progress nesting
                'placement_status'    => $progress['status'],
                'written_score'       => $progress['written_test']['score'] ?? null,
                'total_questions'     => $progress['written_test']['total_questions'] ?? null,
                'percentage'          => $progress['written_test']['percentage'] ?? null,
                'written_level'       => $progress['written_test']['estimated_level'] ?? null,
                'oral_booking_at'     => $progress['oral_assessment']['starts_at'] ?? null,
                'oral_score'          => $progress['oral_assessment']['oral_score'] ?? null,
                'instructor_notes'    => $progress['oral_assessment']['instructor_notes'] ?? null,
                'notes'               => $progress['oral_assessment']['instructor_notes'] ?? null,
                'final_level'         => $progress['oral_assessment']['final_level'] ?? null,
                'placement_progress'  => $progress,
            ];
        });

        $statusCounts = array_fill_keys([
            PlacementProgressService::STATUS_NOT_STARTED,
            PlacementProgressService::STATUS_IN_PROGRESS,
            PlacementProgressService::STATUS_WRITTEN_COMPLETED,
            PlacementProgressService::STATUS_ORAL_BOOKED,
            PlacementProgressService::STATUS_ORAL_COMPLETED,
            PlacementProgressService::STATUS_COMPLETED,
        ], 0);
        foreach ($students as $s) {
            $key = $s['placement_progress']['status'] ?? PlacementProgressService::STATUS_NOT_STARTED;
            if (isset($statusCounts[$key])) {
                $statusCounts[$key]++;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $students,
            'meta'    => ['total' => $students->count()] + $statusCounts,
        ]);
    }

    // ─── GET /api/instructor/oral-assessments ────────────────────────────────
    //
    // All oral assessment bookings assigned to this instructor.

    public function assessments(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();

        $query = OralAssessmentBooking::with(['slot', 'instructor', 'user', 'attempt', 'course'])
            ->when($instructor, fn ($q) => $q->where('instructor_id', $instructor->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', (int) $request->course_id))
            ->latest();

        $bookings = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $bookings->map(function ($b) {
                $slot = $b->slot;
                $prog = $this->placement->progress($b->attempt, $b);
                return [
                    'id'                   => $b->id,
                    'course_id'            => $b->course_id,
                    'course_title'         => $b->course?->title,
                    'student_id'           => $b->user_id,
                    'student_name'         => $b->user?->name,
                    'student_email'        => $b->user?->email,
                    'formatted_date'       => $slot?->starts_at->format('d/m/Y'),
                    'formatted_time'       => $slot
                        ? $slot->starts_at->format('H:i') . ' - ' . $slot->ends_at->format('H:i')
                        : null,
                    'created_at'           => $b->created_at->toIso8601String(),
                    // Flat fields for frontend normalizer compatibility
                    'placement_status'     => $prog['status'],
                    'status'               => $b->status,
                    'written_score'        => $prog['written_test']['score'] ?? null,
                    'total_questions'      => $prog['written_test']['total_questions'] ?? null,
                    'percentage'           => $prog['written_test']['percentage'] ?? null,
                    'estimated_level'      => $prog['written_test']['estimated_level'] ?? null,
                    'oral_booking_at'      => $prog['oral_assessment']['starts_at'] ?? null,
                    'oral_booking_ends_at' => $prog['oral_assessment']['ends_at'] ?? null,
                    'final_level'          => $prog['oral_assessment']['final_level'] ?? null,
                    'oral_score'           => $prog['oral_assessment']['oral_score'] ?? null,
                    'placement_progress'   => $prog,
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

        // Load oral bookings in bulk so canonical placement_status is accurate
        $attemptUserIds  = $attempts->pluck('user_id')->filter()->unique()->all();
        $bookingsByCourseUser = OralAssessmentBooking::whereIn('course_id', $courseIds)
            ->whereIn('user_id', $attemptUserIds)
            ->get()
            ->keyBy(fn ($b) => (string) $b->course_id . '_' . (string) $b->user_id);

        return response()->json([
            'success' => true,
            'data'    => $attempts->map(function ($a) use ($bookingsByCourseUser) {
                $levelDetails = ($a->isCompleted() && $a->estimated_level)
                    ? PlacementScoringService::levelFromCode($a->estimated_level)
                    : null;

                $booking = $bookingsByCourseUser->get((string) $a->course_id . '_' . (string) $a->user_id);

                return [
                    'id'                   => $a->id,
                    'course_id'            => $a->course_id,
                    'course_title'         => $a->course?->title,
                    'student_id'           => $a->user_id,
                    'student_name'         => $a->user?->name,
                    'student_email'        => $a->user?->email,
                    'attempt_status'       => $a->status,
                    'placement_status'     => $this->placement->deriveStatus($a, $booking),
                    'written_score'        => $a->score,
                    'score'                => $a->score,
                    'total_questions'      => $a->total_questions,
                    'percentage'           => $a->percentage,
                    'estimated_level'      => $a->estimated_level,
                    'estimated_level_code' => $levelDetails ? $levelDetails['code'] : null,
                    'estimated_level_en'   => $levelDetails ? $levelDetails['en']   : null,
                    'estimated_level_ar'   => $levelDetails ? $levelDetails['ar']   : null,
                    'submitted_at'         => $a->submitted_at?->toIso8601String(),
                    'oral_booking_at'      => $booking?->slot?->starts_at?->toIso8601String(),
                    'oral_score'           => $booking?->oral_score,
                    'final_level'          => $booking?->final_level,
                    'instructor_notes'     => $booking?->instructor_notes,
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

    // ─── GET /api/instructor/placement-tests/{attempt}/answers ──────────────
    //
    // Return all written test questions with student's answers and correct answers.

    public function answers(Request $request, PlacementTestAttempt $attempt): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();

        if ($instructor) {
            $courseIds = Course::where('instructor_id', $instructor->id)->pluck('id')->all();
            if (! in_array($attempt->course_id, $courseIds, true)) {
                abort(403, 'ليس لديك صلاحية الوصول إلى هذا الاختبار.');
            }
        }

        $attempt->load(['user', 'course', 'placementTest.questions']);

        $test = $attempt->placementTest;

        // answers stored as { "question_id": "a" } keyed map
        $savedAnswers = is_array($attempt->answers) ? $attempt->answers : (json_decode($attempt->answers ?? '{}', true) ?? []);

        $questions = $test?->questions ?? collect();

        $answers = $questions->map(function ($q) use ($savedAnswers) {
            $optsMap = [
                'a' => (string) ($q->option_a ?? ''),
                'b' => (string) ($q->option_b ?? ''),
                'c' => (string) ($q->option_c ?? ''),
                'd' => (string) ($q->option_d ?? ''),
            ];

            $correctOption  = strtolower((string) ($q->correct_option ?? ''));
            $correctAnswer  = $optsMap[$correctOption] ?? $correctOption;
            $selectedOption = isset($savedAnswers[$q->id]) ? strtolower((string) $savedAnswers[$q->id]) : null;
            $selectedAnswer = $selectedOption ? ($optsMap[$selectedOption] ?? $selectedOption) : null;
            $isCorrect      = $selectedOption !== null && $selectedOption === $correctOption;

            return [
                'question_id'     => $q->id,
                'question_text'   => $q->question_text,
                'options'         => $optsMap,
                'selected_option' => $selectedOption,
                'selected_answer' => $selectedAnswer,
                'correct_option'  => $correctOption,
                'correct_answer'  => $correctAnswer,
                'is_correct'      => $isCorrect,
                'points'          => $isCorrect ? 1 : 0,
            ];
        });

        $correctCount = $answers->where('is_correct', true)->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'attempt' => [
                    'id'              => $attempt->id,
                    'student_id'      => $attempt->user_id,
                    'student_name'    => $attempt->user?->name,
                    'course_id'       => $attempt->course_id,
                    'course_title'    => $attempt->course?->title,
                    'score'           => $attempt->score,
                    'total_questions' => $attempt->total_questions,
                    'percentage'      => $attempt->percentage,
                    'estimated_level' => $attempt->estimated_level,
                    'submitted_at'    => $attempt->submitted_at?->toIso8601String(),
                ],
                'correct_count' => $correctCount,
                'answers'       => $answers->values(),
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

        $request->validate([
            'oral_score'       => ['nullable', 'integer', 'min:0', 'max:100'],
            'final_level'      => ['required', 'string', 'in:Starter,A1,A2,B1,B2,C1'],
            'instructor_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $booking) {
            $updateData = [
                'final_level'      => $request->final_level,
                'instructor_notes' => $request->instructor_notes,
                'status'           => 'completed',
            ];
            // Only overwrite oral_score when explicitly provided — preserves existing value on re-edit
            if ($request->has('oral_score')) {
                $updateData['oral_score'] = $request->oral_score;
            }

            $booking->update($updateData);
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

}
