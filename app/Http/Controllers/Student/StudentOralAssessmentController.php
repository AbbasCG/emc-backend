<?php

namespace App\Http\Controllers\Student;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OralAssessmentBookingResource;
use App\Models\Course;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentOralAssessmentController extends Controller
{
    // ─── GET /api/student/courses/{course}/oral-assessment/slots ─────────────
    //
    // Returns future available slots scoped to the course's assigned instructor.

    public function slots(Request $request, Course $course): JsonResponse
    {
        $this->requireEnrollment($request, $course);
        $this->requireWrittenComplete($request, $course);

        // Only show slots from the instructor assigned to this course
        $course->loadMissing('instructor');
        $instructorId = $course->instructor?->id;

        $slots = OralAssessmentSlot::with('instructor')
            ->forCourse($course->id)
            ->when($instructorId, fn ($q) => $q->where('instructor_id', $instructorId))
            ->available()
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'slots' => $slots->map(fn ($slot) => [
                    'id'              => $slot->id,
                    'instructor_id'   => $slot->instructor_id,
                    'instructor_name' => $slot->instructor?->name,
                    'starts_at'       => $slot->starts_at->toIso8601String(),
                    'ends_at'         => $slot->ends_at->toIso8601String(),
                    'is_booked'       => $slot->is_booked,
                    'meeting_link'    => $slot->meeting_link,
                ])->values(),
            ],
        ]);
    }

    // ─── POST /api/student/courses/{course}/oral-assessment/book ─────────────

    public function book(Request $request, Course $course): JsonResponse
    {
        $this->requireEnrollment($request, $course);

        $user    = $request->user();
        $attempt = $this->requireWrittenComplete($request, $course);

        // Prevent duplicate booking
        $alreadyBooked = OralAssessmentBooking::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', ['booked', 'completed'])
            ->exists();

        if ($alreadyBooked) {
            return response()->json([
                'success' => false,
                'message' => 'لديك موعد مقابلة شفهية محجوز بالفعل لهذه الدورة.',
            ], 422);
        }

        $request->validate([
            'slot_id' => ['required', 'integer', 'exists:oral_assessment_slots,id'],
        ]);

        $booking = DB::transaction(function () use ($request, $user, $course, $attempt) {
            $slot = OralAssessmentSlot::where('id', $request->slot_id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();

            if (! $slot || ! $slot->isAvailable()) {
                abort(422, 'الموعد المحدد غير متاح. يرجى اختيار موعد آخر.');
            }

            $slot->update([
                'is_booked'      => true,
                'booked_by'      => $user->id,
                'booking_status' => 'booked',
            ]);

            return OralAssessmentBooking::create([
                'user_id'                  => $user->id,
                'course_id'                => $course->id,
                'instructor_id'            => $slot->instructor_id,
                'placement_test_attempt_id'=> $attempt->id,
                'slot_id'                  => $slot->id,
                'status'                   => 'booked',
            ]);
        });

        $booking->load('slot', 'instructor.user', 'attempt');

        // Render notification text in Amsterdam local time — not raw UTC storage.
        $slotDatetime = $booking->slot->starts_at
            ->copy()
            ->setTimezone(config('app.booking_timezone', 'Europe/Amsterdam'))
            ->format('Y-m-d H:i');

        NotificationService::oralAssessmentBooked(
            $user->id,
            $course->title,
            $slotDatetime,
        );

        // Notify the instructor
        $instructorUserId = $booking->instructor?->user_id;
        if ($instructorUserId) {
            NotificationService::oralAssessmentBookedInstructor(
                $instructorUserId,
                $user->name,
                $course->title,
                $slotDatetime,
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حجز موعد المقابلة الشفهية بنجاح.',
            'data'    => new OralAssessmentBookingResource($booking),
        ], 201);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Production hotfix — same root cause and fix as
     * StudentPlacementTestController::requireEnrollment(): the previous
     * hand-rolled Rejected/Cancelled check let a payment-pending
     * registration count as enrolled. Now uses the canonical
     * RegistrationStatus::activeEnrollment() allowlist.
     */
    private function requireEnrollment(Request $request, Course $course): void
    {
        $enrolled = Registration::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->exists();

        abort_unless($enrolled, 403, 'يجب إكمال التسجيل والدفع للدورة أولاً.');
    }

    /** Returns the completed attempt or aborts 422. */
    private function requireWrittenComplete(Request $request, Course $course): PlacementTestAttempt
    {
        $attempt = PlacementTestAttempt::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->where('status', 'completed')
            ->first();

        abort_unless($attempt, 422, 'يجب إكمال اختبار تحديد المستوى الكتابي أولاً.');

        return $attempt;
    }
}
