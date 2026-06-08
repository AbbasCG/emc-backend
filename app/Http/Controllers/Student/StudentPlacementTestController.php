<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlacementTestAttemptResource;
use App\Http\Resources\PlacementTestResource;
use App\Models\Course;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Services\NotificationService;
use App\Services\PlacementScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentPlacementTestController extends Controller
{
    public function __construct(private PlacementScoringService $scorer) {}

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

        $placementStatus = $this->deriveStatus($attempt, $booking);

        return response()->json([
            'success' => true,
            'data'    => [
                'requires_placement'   => $course->requires_placement_test,
                'placement_status'     => $placementStatus,
                'can_start_learning'   => $placementStatus === 'oral_completed',
                'can_take_written_test'=> in_array($placementStatus, ['not_started', 'in_progress']),
                'can_book_oral'        => $placementStatus === 'written_completed',
                'final_level'          => ($booking && $booking->isCompleted()) ? $booking->final_level : null,
                'attempt'              => $attempt ? $this->attemptShape($attempt) : null,
                'oral_booking'         => $booking  ? $this->bookingShape($booking)  : null,
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

            $derivedStatus = $this->deriveStatus($existing, $existingBooking);

            return response()->json([
                'success'              => false,
                'message'              => 'لقد أكملت اختبار تحديد المستوى لهذه الدورة مسبقاً.',
                'data'                 => [
                    'placement_status'     => $derivedStatus,
                    'can_take_written_test'=> false,
                    'can_book_oral'        => $existingBooking === null,
                    'can_start_learning'   => $derivedStatus === 'oral_completed',
                    'final_level'          => ($existingBooking && $existingBooking->isCompleted()) ? $existingBooking->final_level : null,
                    'attempt'              => $this->attemptShape($existing),
                    'oral_booking'         => $existingBooking ? $this->bookingShape($existingBooking) : null,
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

        return response()->json([
            'success' => true,
            'data'    => [
                'attempt'      => $this->attemptShape($attempt),
                'oral_booking' => $booking ? $this->bookingShape($booking) : null,
                'can_book_oral'=> $booking === null,
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

            $derivedStatus = $this->deriveStatus($attempt, $existingBooking);

            return response()->json([
                'success'      => false,
                'message'      => 'لقد قدّمت هذا الاختبار مسبقاً. لا يمكن إعادة تقديمه.',
                'data'         => [
                    'placement_status'   => $derivedStatus,
                    'can_start_learning' => $derivedStatus === 'oral_completed',
                    'can_book_oral'      => $existingBooking === null,
                    'final_level'        => ($existingBooking && $existingBooking->isCompleted()) ? $existingBooking->final_level : null,
                    'attempt'            => $this->attemptShape($attempt),
                    'oral_booking'       => $existingBooking ? $this->bookingShape($existingBooking) : null,
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

        return response()->json([
            'success' => true,
            'message' => 'تم تسليم الاختبار بنجاح.',
            'data'    => [
                'attempt'      => $this->attemptShape($fresh),
                'can_book_oral'=> true,
                'next_step'    => 'يرجى حجز موعد المقابلة الشفهية لإكمال عملية تحديد المستوى.',
            ],
        ]);
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

    private function deriveStatus(?PlacementTestAttempt $attempt, ?OralAssessmentBooking $booking): string
    {
        if (! $attempt) {
            return 'not_started';
        }
        if (! $attempt->isCompleted()) {
            return 'in_progress';
        }
        if (! $booking) {
            return 'written_completed';
        }
        if ($booking->isCompleted()) {
            return 'oral_completed';
        }
        return 'oral_booked';
    }

    /** Consistent attempt shape used across status, result, start-blocked, and submit-blocked. */
    private function attemptShape(PlacementTestAttempt $attempt): array
    {
        $levelDetails = ($attempt->isCompleted() && $attempt->estimated_level)
            ? \App\Services\PlacementScoringService::levelFromCode($attempt->estimated_level)
            : null;

        return [
            'id'                   => $attempt->id,
            'status'               => $attempt->status,
            'score'                => $attempt->isCompleted() ? $attempt->score       : null,
            'total_questions'      => $attempt->total_questions,
            'percentage'           => $attempt->isCompleted() ? $attempt->percentage  : null,
            'estimated_level'      => $attempt->isCompleted() ? $attempt->estimated_level : null,
            'estimated_level_code' => $levelDetails ? $levelDetails['code'] : null,
            'estimated_level_en'   => $levelDetails ? $levelDetails['en']   : null,
            'estimated_level_ar'   => $levelDetails ? $levelDetails['ar']   : null,
            'submitted_at'         => $attempt->submitted_at?->toIso8601String(),
        ];
    }

    /** Consistent oral booking shape used across status and result endpoints. */
    private function bookingShape(OralAssessmentBooking $booking): array
    {
        $slot       = $booking->relationLoaded('slot')       ? $booking->slot       : null;
        $instructor = $booking->relationLoaded('instructor') ? $booking->instructor : null;

        return [
            'id'               => $booking->id,
            'status'           => $booking->status,
            'starts_at'        => $slot?->starts_at->toIso8601String(),
            'ends_at'          => $slot?->ends_at->toIso8601String(),
            'meeting_link'     => $slot?->meeting_link,
            'instructor_name'  => $instructor?->name,
            'final_level'      => $booking->final_level,
            'oral_score'       => $booking->oral_score,
            'instructor_notes' => $booking->instructor_notes,
        ];
    }
}
