<?php

namespace App\Http\Middleware;

use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block access to course content until the student has completed:
 *  1. The written placement test (status = completed)
 *  2. Booked or completed the oral assessment
 *
 * Apply to routes that use route model binding on {course}.
 * No-op when the course does not require a placement test.
 */
class PlacementTestRequired
{
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

        $attempt = PlacementTestAttempt::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'completed')
            ->exists();

        if (! $attempt) {
            return response()->json([
                'success'          => false,
                'message'          => 'يجب إكمال اختبار تحديد المستوى الكتابي قبل الوصول إلى محتوى هذه الدورة.',
                'placement_status' => 'placement_required',
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
            ], 403);
        }

        return $next($request);
    }
}
