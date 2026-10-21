<?php

namespace App\Http\Controllers\Institute;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Course;
use App\Models\Registration;
use App\Models\PlacementTestAttempt;
use App\Models\Coupon;
use App\Models\ClassGroupStudent;

class InstituteStudentController extends Controller
{
    // Save Student Preferences (Preferred time, etc.)
    public function savePreferences(Request $request)
    {
        $request->validate([
            'preferred_time_slot' => 'required|string|in:Morning,Afternoon,Evening',
        ]);

        $user = $request->user();
        // Assuming we store preferences in user meta or a related table
        // $user->update(['preferred_time_slot' => $request->preferred_time_slot]);

        return response()->json(['message' => 'Preferences saved successfully']);
    }

    // Get Placement Test details
    public function getTest(Request $request)
    {
        // Fetch the active institute placement test
        return response()->json([
            'test' => [
                'title' => 'English Placement Test',
                'duration_minutes' => 60,
                'total_questions' => 30,
            ]
        ]);
    }

    // Start Placement Test
    public function startTest(Request $request)
    {
        return response()->json([
            'message' => 'Test started',
            'questions' => [
                // Mock questions
            ]
        ]);
    }

    // Submit Placement Test
    public function submitTest(Request $request)
    {
        // Calculate score and determine level
        $score = rand(40, 100);
        $level = 'B1 Intermediate';
        
        // Find a matching institute course for this level
        $course = Course::where('is_institute', true)->where('title_en', 'like', '%B1%')->first();
        if (!$course) {
            $course = Course::where('is_institute', true)->first();
        }

        return response()->json([
            'message' => 'Test submitted successfully',
            'assigned_level' => $course ? $course->title_en : $level,
            'course_id' => $course ? $course->id : null,
            'score' => $score
        ]);
    }

    // Validate Coupon
    public function validateCoupon(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'course_id' => 'required|integer',
        ]);

        return response()->json([
            'is_valid' => true,
            'discount_amount' => 75.00,
            'final_price' => 75.00,
        ]);
    }

    // Checkout
    public function checkout(Request $request)
    {
        $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'pricing_option_index' => 'nullable|integer', // Index of the chosen pricing option in the course
            'coupon_code' => 'nullable|string',
        ]);

        $user = $request->user();
        $course = Course::findOrFail($request->course_id);

        // This module stays student-only (its own flow), but it still creates registrations, so the one enrollment rule applies:
        // nobody enrolls in a course they teach.
        app(\App\Services\LearnerEnrollmentGuard::class)->assertCanEnroll($user, $course);
        // Published and open like every other program; capacity does not apply, because this flow IS the waitlist.
        app(\App\Services\EnrollmentAvailability::class)->assertOpen($course, $user->id, checkCapacity: false);

        // Check if already registered
        $existing = Registration::where('user_id', $user->id)->where('course_id', $request->course_id)->first();
        if ($existing) {
            return response()->json(['message' => 'Already registered', 'order_id' => $existing->id], 200);
        }

        // Determine price and levels purchased
        $price = $course->price;
        $levelsPurchased = 1;

        if ($request->has('pricing_option_index') && !empty($course->pricing_options)) {
            $options = $course->pricing_options;
            $index = $request->pricing_option_index;
            if (isset($options[$index])) {
                $price = $options[$index]['price'] ?? $price;
                $levelsPurchased = $options[$index]['levels'] ?? 1;
            }
        }

        // Apply coupon if provided
        $discountAmount = 0;
        if ($request->filled('coupon_code')) {
            $coupon = Coupon::where('code', $request->coupon_code)->first();
            if ($coupon && $coupon->isValid()) {
                // In a real scenario, you'd check if the coupon applies to this course
                $discountAmount = $coupon->calculateDiscount($price);
            }
        }

        $finalPrice = max(0, $price - $discountAmount);

        $reg = Registration::create([
            'user_id' => $user->id,
            'course_id' => $request->course_id,
            'status' => 'pending', // waitlist status
            'full_name' => $user->name,
            'email' => $user->email,
            'levels_purchased' => $levelsPurchased,
        ]);

        return response()->json([
            'message' => 'Checkout successful, added to waitlist',
            'order_id' => $reg->id,
            'total_paid' => $finalPrice,
            'levels_purchased' => $levelsPurchased,
            'discount_applied' => $discountAmount
        ]);
    }

    // Student Dashboard
    public function dashboard(Request $request)
    {
        $user = $request->user();

        // Check if student has an active institute class
        $activeClass = ClassGroupStudent::where('user_id', $user->id)
            ->whereHas('classGroup', function($q) {
                $q->where('is_institute', true);
            })->with('classGroup.course')->first();

        // Check if they are in the waitlist (registered but no active class)
        $waitlist = Registration::where('user_id', $user->id)
            ->whereHas('course', function($q) {
                $q->where('is_institute', true);
            })->with('course')->first();

        if ($activeClass) {
            return response()->json([
                'status' => 'enrolled',
                'current_level' => $activeClass->classGroup->course->title ?? 'Unknown Level',
                'progress' => 45,
                'next_class' => [
                    'date' => $activeClass->classGroup->schedule_day ?? 'Today',
                    'time' => $activeClass->classGroup->schedule_time ?? 'TBA',
                    'topic' => 'Class Session',
                    'teacher' => 'Instructor'
                ]
            ]);
        }

        if ($waitlist) {
            return response()->json([
                'status' => 'waitlist',
                'current_level' => $waitlist->course->title ?? 'Unknown Level',
                'message' => 'You are on the waitlist. We will assign you a class soon.'
            ]);
        }

        // Not enrolled, fetch available levels
        $levels = Course::where('is_institute', true)->where('status', 'published')->get();

        return response()->json([
            'status' => 'not_enrolled',
            'available_levels' => $levels
        ]);
    }
}
