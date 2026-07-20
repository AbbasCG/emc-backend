<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlacementTestAttemptResource;
use App\Http\Resources\PlacementTestResource;
use App\Models\Course;
use App\Models\ExamViolation;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Services\NotificationService;
use App\Services\PlacementProgressService;
use App\Services\PlacementScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentPlacementTestController extends Controller
{
    public function __construct(
        private readonly PlacementScoringService $scorer,
        private readonly PlacementProgressService $placement,
    ) {}

    // ─── GET /api/student/courses/{course}/placement-test/status ─────────────

    public function status(Request $request, Course $course): JsonResponse
    {
        $this->requireEnrollment($request, $course);

        $attempt = PlacementTestAttempt::with('oralBooking.slot', 'oralBooking.instructor')
            ->where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->latest()
            ->first();

        $booking = $attempt?->oralBooking;

        $status   = $this->placement->deriveStatus($attempt, $booking);
        $progress = $this->placement->progress($attempt, $booking);

        return response()->json([
            'success' => true,
            'data'    => [
                'requires_placement'    => $course->requires_placement_test,
                'placement_progress'    => $progress,
                'can_take_written_test' => in_array($status, [
                    PlacementProgressService::STATUS_NOT_STARTED,
                    PlacementProgressService::STATUS_IN_PROGRESS,
                ]),
                'can_book_oral' => $status === PlacementProgressService::STATUS_WRITTEN_COMPLETED,
            ],
        ]);
    }

    // ─── POST /api/student/courses/{course}/placement-test/start ─────────────

    public function start(Request $request, Course $course): JsonResponse
    {
        $this->requireEnrollment($request, $course);
        $this->requireCoursePlacement($course);

        $user = $request->user();

        $existing = PlacementTestAttempt::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing?->isCompleted()) {
            $existingBooking = OralAssessmentBooking::with('slot', 'instructor')
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            $status = $this->placement->deriveStatus($existing, $existingBooking);

            return response()->json([
                'success' => false,
                'message' => 'لقد أكملت اختبار تحديد المستوى لهذه الدورة مسبقاً.',
                'data'    => [
                    'placement_progress'    => $this->placement->progress($existing, $existingBooking),
                    'can_take_written_test' => false,
                    'can_book_oral'         => $status === PlacementProgressService::STATUS_WRITTEN_COMPLETED,
                ],
            ], 409);
        }

        if ($existing) {
            // Resume in-progress attempt
            $test = PlacementTest::active()->with('questions')->findOrFail($existing->placement_test_id);
            return $this->testResponse($test, $existing);
        }

        $test = PlacementTest::active()->with('questions')->first();

        if (! $test) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد اختبار تحديد مستوى نشط حالياً. يرجى التواصل مع الإدارة.',
            ], 503);
        }

        $attempt = PlacementTestAttempt::create([
            'user_id'           => $user->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'total_questions'   => $test->total_questions,
            'started_at'        => now(),
            'status'            => 'in_progress',
        ]);

        return $this->testResponse($test, $attempt, 201);
    }

    // ─── GET /api/student/courses/{course}/placement-test ────────────────────
    // Requires in_progress attempt (called to re-fetch questions on page refresh)

    public function show(Request $request, Course $course): JsonResponse
    {
        $this->requireEnrollment($request, $course);
        $this->requireCoursePlacement($course);

        $attempt = PlacementTestAttempt::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->where('status', 'in_progress')
            ->first();

        if (! $attempt) {
            return response()->json([
                'success' => false,
                'message' => 'يجب بدء الاختبار أولاً عبر نقطة نهاية /start.',
            ], 422);
        }

        $test = PlacementTest::with('questions')->findOrFail($attempt->placement_test_id);

        return $this->testResponse($test, $attempt);
    }

    // ─── GET /api/student/courses/{course}/placement-test/result ─────────────
    // Returns the saved result after submission (does NOT require in_progress)

    public function result(Request $request, Course $course): JsonResponse
    {
        $this->requireEnrollment($request, $course);
        $this->requireCoursePlacement($course);

        $attempt = PlacementTestAttempt::with('oralBooking.slot', 'oralBooking.instructor')
            ->where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        if (! $attempt) {
            return response()->json([
                'success' => false,
                'message' => 'لم تُكمل اختبار تحديد المستوى لهذه الدورة بعد.',
            ], 404);
        }

        $booking = $attempt->oralBooking;
        $status  = $this->placement->deriveStatus($attempt, $booking);

        return response()->json([
            'success' => true,
            'data'    => [
                'placement_progress' => $this->placement->progress($attempt, $booking),
                'can_book_oral'      => $status === PlacementProgressService::STATUS_WRITTEN_COMPLETED,
            ],
        ]);
    }

    // ─── POST /api/student/courses/{course}/placement-test/submit ────────────

    public function submit(Request $request, Course $course): JsonResponse
    {
        $this->requireEnrollment($request, $course);
        $this->requireCoursePlacement($course);

        $user = $request->user();

        $attempt = PlacementTestAttempt::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if (! $attempt) {
            return response()->json([
                'success' => false,
                'message' => 'لم تبدأ الاختبار بعد. استخدم نقطة نهاية /start أولاً.',
            ], 422);
        }

        if ($attempt->isCompleted()) {
            $existingBooking = OralAssessmentBooking::with('slot', 'instructor')
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            $status = $this->placement->deriveStatus($attempt, $existingBooking);

            return response()->json([
                'success' => false,
                'message' => 'لقد قدّمت هذا الاختبار مسبقاً. لا يمكن إعادة تقديمه.',
                'data'    => [
                    'placement_progress'    => $this->placement->progress($attempt, $existingBooking),
                    'can_take_written_test' => false,
                    'can_book_oral'         => $status === PlacementProgressService::STATUS_WRITTEN_COMPLETED,
                ],
            ], 409);
        }

        $request->validate([
            'answers'   => ['required', 'array', 'min:1'],
            'answers.*' => ['required', 'string', \Illuminate\Validation\Rule::in(['a','b','c','d','A','B','C','D'])],
        ]);

        $test   = PlacementTest::with('questions')->findOrFail($attempt->placement_test_id);
        $result = $this->scorer->grade($test, $request->answers);

        $attempt->update([
            'score'           => $result['score'],
            'total_questions' => $result['total_questions'],
            'percentage'      => $result['percentage'],
            'estimated_level' => $result['estimated_level'],
            'answers'         => $request->answers,
            'submitted_at'    => now(),
            'status'          => 'completed',
        ]);

        $fresh = $attempt->fresh();

        // Notify the course instructor
        $course->loadMissing('instructor');
        $instructorUserId = $course->instructor?->user_id;
        if ($instructorUserId) {
            NotificationService::placementTestCompleted(
                $instructorUserId,
                $course->id,
                $course->title,
                $user->name,
                $fresh->score,
                $fresh->total_questions,
                $fresh->estimated_level,
            );
        }

        // Include score fields at the top level so the frontend result screen
        // can read them immediately without needing a second /result request.
        return response()->json([
            'success' => true,
            'message' => 'تم تسليم الاختبار بنجاح.',
            'data'    => [
                'score'            => $fresh->score,
                'total_questions'  => $fresh->total_questions,
                'percentage'       => $fresh->percentage,
                'estimated_level'  => $fresh->estimated_level,
                'placement_progress' => $this->placement->progress($fresh, null),
                'can_book_oral'      => true,
                'next_step'          => 'يرجى حجز موعد المقابلة الشفهية لإكمال عملية تحديد المستوى.',
            ],
        ]);
    }

    // ─── POST /api/student/courses/{course}/placement-test/violations ────────
    //
    // Logs a single client-detected exam-integrity violation (copy attempt, tab
    // switch, fullscreen exit, etc.) against the student's current attempt.
    // Fire-and-forget from the frontend — failures here must never block the exam.

    public function logViolation(Request $request, Course $course): JsonResponse
    {
        $this->requireEnrollment($request, $course);

        $user = $request->user();

        $attempt = PlacementTestAttempt::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'in_progress')
            ->first();

        // No active attempt (already submitted, or race on page unload) — accept
        // silently rather than erroring; there's nothing meaningful left to log against.
        if (! $attempt) {
            return response()->json(['success' => true]);
        }

        $validated = $request->validate([
            'violation_type' => ['required', 'string', Rule::in(ExamViolation::TYPES)],
            'severity'       => ['nullable', 'string', Rule::in(ExamViolation::SEVERITIES)],
            'question_id'    => ['nullable', 'integer', 'exists:placement_test_questions,id'],
            'meta'           => ['nullable', 'array'],
        ]);

        ExamViolation::create([
            'user_id'                   => $user->id,
            'placement_test_attempt_id' => $attempt->id,
            'question_id'               => $validated['question_id'] ?? null,
            'violation_type'            => $validated['violation_type'],
            'severity'                  => $validated['severity'] ?? 'low',
            'meta'                      => $validated['meta'] ?? null,
            'ip_address'                => $request->ip(),
            'user_agent'                => mb_substr((string) $request->userAgent(), 0, 500),
            'occurred_at'               => now(),
        ]);

        return response()->json(['success' => true]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function requireEnrollment(Request $request, Course $course): void
    {
        $enrolled = Registration::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->whereNotIn('status', ['Rejected', 'Cancelled'])
            ->exists();

        abort_unless($enrolled, 403, 'يجب التسجيل في الدورة أولاً للوصول إلى هذه الميزة.');
    }

    private function requireCoursePlacement(Course $course): void
    {
        abort_unless(
            $course->requires_placement_test,
            422,
            'هذه الدورة لا تتطلب اختبار تحديد مستوى.'
        );
    }

    private function testResponse(PlacementTest $test, PlacementTestAttempt $attempt, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'test'    => new PlacementTestResource($test),
                'attempt' => new PlacementTestAttemptResource($attempt),
            ],
        ], $status);
    }

}
