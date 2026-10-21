<?php

namespace App\Http\Middleware;

use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use App\Services\CourseAccessEligibilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block access to course content until:
 *  1. The student's enrollment is active — for paid courses this means
 *     payment is actually confirmed, never merely "a registration row
 *     exists" (production hotfix — see CourseAccessEligibilityService).
 *  2. The written placement test is completed.
 *  3. The oral assessment is booked or completed.
 *
 * Apply to routes that use route model binding on {course}.
 * No-op when the course does not require a placement test.
 */
class PlacementTestRequired
{
    public function __construct(private readonly CourseAccessEligibilityService $eligibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Routes use {course} (model binding) or {courseId} (raw ID)
        $course = $request->route('course') ?? $request->route('courseId');

        if (is_string($course) || is_int($course)) {
            $course = \App\Models\Course::find($course);
        }

        if (! $course || ! $course->requires_placement_test) {
            return $next($request);
        }

        $user = $request->user();

        // Payment/enrollment is decided BEFORE placement state — a student
        // whose paid-course registration exists but is still pending_payment
        // (or failed/cancelled) must never see a "placement test required"
        // message; that previously leaked a live start-test path to unpaid
        // students, since this middleware ran before any controller-level
        // enrollment check.
        $access = $this->eligibility->forUserAndCourse($user, $course);

        if (! $access['enrollment_active']) {
            return response()->json([
                'success'          => false,
                'message'          => $this->messageForBlockReason($access['block_reason']),
                'placement_status' => $access['block_reason'],
                'access'           => $access,
            ], 403);
        }

        $attempt = PlacementTestAttempt::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'completed')
            ->exists();

        if (! $attempt) {
            return response()->json([
                'success'          => false,
                'message'          => 'يجب إكمال اختبار تحديد المستوى الكتابي قبل الوصول إلى محتوى هذه الدورة.',
                'placement_status' => 'placement_required',
                'access'           => $access,
            ], 403);
        }

        $booking = OralAssessmentBooking::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', ['booked', 'completed'])
            ->exists();

        if (! $booking) {
            return response()->json([
                'success'          => false,
                'message'          => 'يجب حجز موعد المقابلة الشفهية قبل الوصول إلى محتوى هذه الدورة.',
                'placement_status' => 'written_completed',
                'access'           => $access,
            ], 403);
        }

        return $next($request);
    }

    private function messageForBlockReason(string $reason): string
    {
        return match ($reason) {
            'payment_under_review' => 'تم استلام دفعتك وهي قيد المراجعة المالية. لا حاجة لدفع مرة أخرى، وسنتواصل معك.',
            'payment_pending' => 'يلزم إكمال الدفع أولاً. عملية الدفع الخاصة بك قيد المراجعة.',
            'payment_failed' => 'تعذر تأكيد عملية الدفع. يرجى إعادة المحاولة للوصول إلى محتوى الدورة.',
            'registration_cancelled' => 'تسجيلك في هذه الدورة ملغى.',
            'no_registration' => 'يجب التسجيل والدفع في هذه الدورة أولاً.',
            default => 'يلزم إكمال الدفع أولاً قبل الوصول إلى محتوى هذه الدورة.',
        };
    }
}
