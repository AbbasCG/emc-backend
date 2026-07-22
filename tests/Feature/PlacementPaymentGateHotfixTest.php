<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\Payment;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\PlacementTestQuestion;
use App\Models\Registration;
use App\Models\User;
use App\Services\PlacementScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Production hotfix regression suite.
 *
 * Root cause: StudentPlacementTestController::requireEnrollment() and
 * StudentOralAssessmentController::requireEnrollment() used a hand-rolled
 * `whereNotIn('status', ['Rejected', 'Cancelled'])` check instead of the
 * canonical RegistrationStatus::activeEnrollment() allowlist every other
 * student-facing controller uses. 'Rejected' isn't even a real
 * RegistrationStatus value, so in practice almost any status — including
 * `pending_payment`, which RegistrationController::store() sets for every
 * paid-course registration until payment is confirmed — passed the check.
 * A student who registered for a paid course but never completed payment
 * could therefore start, take, and submit the placement test, and book an
 * oral assessment slot, before paying.
 *
 * Fix: both controllers now use RegistrationStatus::activeEnrollment() —
 * the same backend single source of truth for "this registration grants
 * access" already used by StudentLearningController, StudentNoteController,
 * StudentEvaluationController, etc. This is a backend-only fix; the
 * eligibility decision now lives in exactly one place per registration
 * status, not duplicated/approximated per-controller.
 */
class PlacementPaymentGateHotfixTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function paidPlacementCourse(): Course
    {
        return Course::factory()->paid()->create([
            'requires_placement_test' => true,
            'placement_test_type'     => 'english_cefr',
            'status'                  => 'published',
        ]);
    }

    private function freePlacementCourse(): Course
    {
        return Course::factory()->free()->create([
            'requires_placement_test' => true,
            'placement_test_type'     => 'english_cefr',
            'status'                  => 'published',
        ]);
    }

    private function registration(User $student, Course $course, string $status): Registration
    {
        return Registration::create([
            'user_id'   => $student->id,
            'course_id' => $course->id,
            'full_name' => $student->name,
            'email'     => $student->email,
            'phone'     => '0500000000',
            'status'    => $status,
        ]);
    }

    private function placementTest(int $count = 5): PlacementTest
    {
        $test = PlacementTest::create([
            'title'            => 'English Placement',
            'language'         => 'en',
            'duration_minutes' => 30,
            'total_questions'  => $count,
            'is_active'        => true,
        ]);
        for ($i = 1; $i <= $count; $i++) {
            PlacementTestQuestion::create([
                'placement_test_id' => $test->id,
                'question_text'     => "Q{$i}",
                'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D',
                'correct_option' => 'a',
                'order' => $i,
            ]);
        }

        return $test;
    }

    // ── The actual bug: unpaid registration must be blocked ─────────────────

    public function test_student_with_pending_payment_registration_cannot_start_the_placement_test(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);
        $this->placementTest();

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start");

        $response->assertStatus(403);
        $this->assertDatabaseMissing('placement_test_attempts', ['user_id' => $student->id, 'course_id' => $course->id]);
    }

    public function test_student_with_pending_payment_registration_cannot_view_placement_status(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertStatus(403);
    }

    public function test_student_with_pending_payment_registration_cannot_submit_the_placement_test(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $test    = $this->placementTest();
        // Simulate an in-progress attempt that predates the fix / was created
        // out-of-band — submit must still be blocked at the gate.
        PlacementTestAttempt::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'placement_test_id' => $test->id,
            'total_questions' => $test->total_questions, 'started_at' => now(), 'status' => 'in_progress',
        ]);
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/submit", ['answers' => ['a', 'a', 'a', 'a', 'a']])
            ->assertStatus(403);
    }

    public function test_student_with_pending_payment_registration_cannot_view_the_in_progress_test(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test")
            ->assertStatus(403);
    }

    public function test_student_with_pending_payment_registration_cannot_view_the_result(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/result")
            ->assertStatus(403);
    }

    public function test_student_with_pending_payment_registration_cannot_book_an_oral_assessment_slot(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $instructor = Instructor::create(['user_id' => User::factory()->create(['role' => 'instructor'])->id, 'name' => 'I', 'title' => 'Instructor']);
        $slot = OralAssessmentSlot::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(30),
            'booking_status' => 'available', 'is_booked' => false,
        ]);
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/oral-assessment/book", ['slot_id' => $slot->id])
            ->assertStatus(403);
    }

    public function test_student_with_a_cancelled_registration_cannot_start_the_placement_test(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->registration($student, $course, RegistrationStatus::CANCELLED->value);
        $this->placementTest();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertStatus(403);
    }

    // ── Once payment is confirmed, access is granted (proves the fix isn't overly strict) ──

    public function test_student_whose_payment_is_confirmed_can_start_the_placement_test(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->registration($student, $course, RegistrationStatus::PAYMENT_CONFIRMED->value);
        $this->placementTest();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertCreated();

        $this->assertDatabaseHas('placement_test_attempts', ['user_id' => $student->id, 'course_id' => $course->id]);
    }

    public function test_a_registration_transitioning_from_pending_payment_to_confirmed_unblocks_access(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $registration = $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);
        $this->placementTest();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertStatus(403);

        $registration->update(['status' => RegistrationStatus::PAYMENT_CONFIRMED->value]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertCreated();
    }

    // ── Free courses remain unaffected (never go through pending_payment) ──

    public function test_free_course_registration_grants_immediate_placement_test_access(): void
    {
        $student = $this->student();
        $course  = $this->freePlacementCourse();
        // Free courses register directly as REGISTERED — never PENDING_PAYMENT
        // (see RegistrationController::store()'s $courseIsPaid branch).
        $this->registration($student, $course, RegistrationStatus::REGISTERED->value);
        $this->placementTest();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertCreated();
    }

    // ── Legacy status values already granted by the prior pass remain granted ──

    public function test_legacy_capitalized_registered_status_still_grants_access(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->registration($student, $course, 'Registered');
        $this->placementTest();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertCreated();
    }

    // ── No registration at all is still blocked (baseline, unchanged) ──

    public function test_student_with_no_registration_at_all_cannot_start_the_placement_test(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->placementTest();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertStatus(403);
    }

    // ── Learning-content gate (PlacementTestRequired middleware + StudentLearnController) ──

    public function test_pending_payment_student_cannot_access_learning_content_and_gets_a_payment_block_reason(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/learn");

        $response->assertStatus(403);
        $this->assertNotSame('placement_required', $response->json('placement_status'));
        $this->assertContains($response->json('placement_status'), ['payment_pending', 'payment_required']);
        $this->assertFalse($response->json('access.payment_completed'));
    }

    public function test_pending_payment_student_cannot_access_the_modules_endpoint(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/modules")
            ->assertStatus(403);
    }

    public function test_paid_student_with_completed_placement_can_access_learning_content(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $this->registration($student, $course, RegistrationStatus::PAYMENT_CONFIRMED->value);

        $test = $this->placementTest();
        $attempt = PlacementTestAttempt::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'placement_test_id' => $test->id,
            'score' => 35, 'total_questions' => $test->total_questions, 'percentage' => 70,
            'estimated_level' => (new PlacementScoringService())->mapLevel(35),
            'started_at' => now()->subHour(), 'submitted_at' => now(), 'answers' => [], 'status' => 'completed',
        ]);
        $instructor = Instructor::create(['user_id' => User::factory()->create(['role' => 'instructor'])->id, 'name' => 'I', 'title' => 'Instructor']);
        $slot = OralAssessmentSlot::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addMinutes(30),
            'booking_status' => 'booked', 'is_booked' => true,
        ]);
        OralAssessmentBooking::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'placement_test_attempt_id' => $attempt->id,
            'slot_id' => $slot->id, 'instructor_id' => $instructor->id,
            'status' => 'completed', 'final_level' => 'B1',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/learn")
            ->assertOk();
    }

    // ── Access block surfaced on the registrations list (feeds the "pending" tab) ──

    public function test_registrations_endpoint_marks_a_pending_payment_registration_as_payment_incomplete(): void
    {
        $student = $this->student();
        $course  = $this->paidPlacementCourse();
        $registration = $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);
        Payment::create([
            'registration_id' => $registration->id, 'amount' => $course->price, 'provider' => 'stripe',
            'checkout_url' => 'https://checkout.stripe.com/pay/cs_test_abc', 'status' => 'pending_payment',
        ]);

        $response = $this->actingAs($student, 'sanctum')->getJson('/api/student/registrations');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('course_id', $course->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['access']['payment_completed']);
        $this->assertTrue($row['access']['payment_required']);
        $this->assertSame('https://checkout.stripe.com/pay/cs_test_abc', $row['access']['payment_url']);
    }

    public function test_registrations_endpoint_marks_a_confirmed_payment_registration_as_access_allowed_when_no_placement_required(): void
    {
        $student = $this->student();
        $course  = Course::factory()->paid()->create(['requires_placement_test' => false, 'status' => 'published']);
        $this->registration($student, $course, RegistrationStatus::PAYMENT_CONFIRMED->value);

        $response = $this->actingAs($student, 'sanctum')->getJson('/api/student/registrations');

        $row = collect($response->json('data'))->firstWhere('course_id', $course->id);
        $this->assertTrue($row['access']['payment_completed']);
        $this->assertTrue($row['access']['can_access_learning']);
        $this->assertSame('access_allowed', $row['access']['block_reason']);
    }

    public function test_registrations_endpoint_marks_a_free_course_as_never_payment_required(): void
    {
        $student = $this->student();
        $course  = $this->freePlacementCourse();
        $this->registration($student, $course, RegistrationStatus::REGISTERED->value);

        $response = $this->actingAs($student, 'sanctum')->getJson('/api/student/registrations');

        $row = collect($response->json('data'))->firstWhere('course_id', $course->id);
        $this->assertFalse($row['access']['is_paid_course']);
        $this->assertFalse($row['access']['payment_required']);
    }

    // ── Payment for a different course/another user never grants access ────

    public function test_a_confirmed_payment_for_a_different_course_does_not_grant_access_to_this_course(): void
    {
        $student = $this->student();
        $paidForCourse = $this->paidPlacementCourse();
        $thisCourse = $this->paidPlacementCourse();
        $this->registration($student, $paidForCourse, RegistrationStatus::PAYMENT_CONFIRMED->value);
        // No registration at all for $thisCourse.

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$thisCourse->id}/learn")
            ->assertStatus(403);
    }

    public function test_another_users_confirmed_payment_does_not_grant_this_student_access(): void
    {
        $student = $this->student();
        $otherStudent = $this->student();
        $course = $this->paidPlacementCourse();
        $this->registration($otherStudent, $course, RegistrationStatus::PAYMENT_CONFIRMED->value);
        // $student has no registration for this course at all.

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/learn")
            ->assertStatus(403);
    }
}
