<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\Course;
use App\Models\FinanceAccount;
use App\Models\ManualPayment;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\User;
use App\Services\CourseAccessEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue 4 — public course-detail page must never show "متابعة التعلم" for a
 * paid course whose payment isn't genuinely confirmed. The canonical
 * eligibility decision (CourseAccessEligibilityService, already reused by
 * StudentLearnController/PlacementTestRequired/StudentRegistrationsController
 * per the prior payment-gate hotfix) is the single source of truth; this
 * suite proves its output for every documented state and that the existing
 * route protections (learn/modules/placement-test) remain intact.
 */
class CourseAccessEligibilityPublicDetailTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function paidCourse(): Course
    {
        return Course::factory()->paid()->create(['status' => 'published', 'requires_placement_test' => false]);
    }

    private function registration(User $student, Course $course, string $status): Registration
    {
        return Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email, 'phone' => '0500000000',
            'status' => $status,
        ]);
    }

    private function eligibility(): CourseAccessEligibilityService
    {
        return app(CourseAccessEligibilityService::class);
    }

    // ── 1. Pending payment ───────────────────────────────────────────────────

    public function test_pending_payment_denies_learning_and_returns_a_reusable_payment_url(): void
    {
        $student = $this->student();
        $course = $this->paidCourse();
        $registration = $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);
        Payment::create([
            'registration_id' => $registration->id, 'status' => 'pending_payment',
            'amount' => 99.99, 'currency' => 'EUR', 'checkout_url' => 'https://checkout.stripe.com/pay/cs_test_pending',
        ]);

        $result = $this->eligibility()->forUserAndCourse($student, $course);

        $this->assertFalse($result['can_access_learning']);
        $this->assertTrue($result['payment_required']);
        $this->assertSame('pending_payment', $result['payment_status']);
        $this->assertSame('https://checkout.stripe.com/pay/cs_test_pending', $result['payment_url']);
        $this->assertSame('payment_pending', $result['block_reason']);
    }

    // ── 2. Successful payment ────────────────────────────────────────────────

    public function test_confirmed_payment_grants_learning_access(): void
    {
        $student = $this->student();
        $course = $this->paidCourse();
        $this->registration($student, $course, RegistrationStatus::REGISTERED->value);

        $result = $this->eligibility()->forUserAndCourse($student, $course);

        $this->assertTrue($result['can_access_learning']);
        $this->assertTrue($result['payment_completed']);
        $this->assertSame('access_allowed', $result['block_reason']);
    }

    // ── 3. Failed payment ─────────────────────────────────────────────────────

    public function test_failed_payment_denies_learning_and_returns_retry_information(): void
    {
        $student = $this->student();
        $course = $this->paidCourse();
        $registration = $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);
        Payment::create([
            'registration_id' => $registration->id, 'status' => 'payment_failed',
            'amount' => 99.99, 'currency' => 'EUR', 'checkout_url' => 'https://checkout.stripe.com/pay/cs_test_failed',
        ]);

        $result = $this->eligibility()->forUserAndCourse($student, $course);

        $this->assertFalse($result['can_access_learning']);
        $this->assertSame('payment_failed', $result['payment_status']);
        $this->assertSame('payment_failed', $result['block_reason']);
        $this->assertSame('https://checkout.stripe.com/pay/cs_test_failed', $result['payment_url']);
    }

    // ── 4. Manual payment under review ───────────────────────────────────────

    private function manualPaymentUnderReview(User $student, Course $course): ManualPayment
    {
        $account = FinanceAccount::create(['name' => 'Bank', 'type' => 'bank', 'currency' => 'EUR']);

        return ManualPayment::create([
            'student_id' => $student->id,
            'purchasable_type' => Course::class,
            'purchasable_id' => $course->id,
            'finance_account_id' => $account->id,
            'expected_amount' => 99.99,
            'paid_amount' => 99.99,
            'difference_amount' => 0,
            'currency' => 'EUR',
            'status' => 'pending_review',
            'payment_date' => now()->toDateString(),
        ]);
    }

    public function test_manual_payment_under_review_denies_learning_and_reports_review_status(): void
    {
        $student = $this->student();
        $course = $this->paidCourse();
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);
        $this->manualPaymentUnderReview($student, $course);

        $result = $this->eligibility()->forUserAndCourse($student, $course);

        $this->assertFalse($result['can_access_learning']);
        $this->assertSame('pending_review', $result['payment_status']);
        $this->assertSame('payment_pending', $result['block_reason']);
    }

    public function test_manual_payment_under_review_takes_precedence_over_a_generic_pending_payment_row(): void
    {
        $student = $this->student();
        $course = $this->paidCourse();
        $registration = $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);
        Payment::create([
            'registration_id' => $registration->id, 'status' => 'pending_payment',
            'amount' => 99.99, 'currency' => 'EUR', 'checkout_url' => 'https://checkout.stripe.com/pay/cs_test_stale',
        ]);
        $this->manualPaymentUnderReview($student, $course);

        $result = $this->eligibility()->forUserAndCourse($student, $course);

        $this->assertSame('pending_review', $result['payment_status']);
    }

    public function test_a_confirmed_or_rejected_manual_payment_does_not_report_pending_review(): void
    {
        $student = $this->student();
        $course = $this->paidCourse();
        $registration = $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);
        $account = FinanceAccount::create(['name' => 'Bank', 'type' => 'bank', 'currency' => 'EUR']);
        ManualPayment::create([
            'student_id' => $student->id, 'purchasable_type' => Course::class, 'purchasable_id' => $course->id,
            'finance_account_id' => $account->id, 'expected_amount' => 99.99, 'paid_amount' => 99.99,
            'difference_amount' => 0, 'currency' => 'EUR', 'status' => 'rejected', 'payment_date' => now()->toDateString(),
        ]);
        Payment::create([
            'registration_id' => $registration->id, 'status' => 'payment_failed',
            'amount' => 99.99, 'currency' => 'EUR',
        ]);

        $result = $this->eligibility()->forUserAndCourse($student, $course);

        $this->assertSame('payment_failed', $result['payment_status']);
    }

    // ── 5. Free course unaffected ────────────────────────────────────────────

    public function test_free_course_is_never_payment_blocked(): void
    {
        $student = $this->student();
        $course = Course::factory()->free()->create(['status' => 'published', 'requires_placement_test' => false]);
        $this->registration($student, $course, RegistrationStatus::REGISTERED->value);

        $result = $this->eligibility()->forUserAndCourse($student, $course);

        $this->assertFalse($result['is_paid_course']);
        $this->assertFalse($result['payment_required']);
        $this->assertTrue($result['can_access_learning']);
    }

    // ── 6/7. Route protection still blocks direct URL access ────────────────

    public function test_direct_learn_route_is_still_blocked_for_a_pending_payment_student(): void
    {
        $student = $this->student();
        $course = $this->paidCourse();
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/learn")
            ->assertStatus(403);
    }

    public function test_placement_test_route_is_still_blocked_for_a_pending_payment_student(): void
    {
        $student = $this->student();
        $course = Course::factory()->paid()->create([
            'status' => 'published', 'requires_placement_test' => true, 'placement_test_type' => 'english_cefr',
        ]);
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertStatus(403);
    }

    // ── 8. No duplicate registration/checkout created by resuming payment ───

    public function test_resuming_an_existing_pending_registration_does_not_create_a_second_registration(): void
    {
        $student = $this->student();
        $course = $this->paidCourse();
        $this->registration($student, $course, RegistrationStatus::PENDING_PAYMENT->value);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/courses/{$course->id}/checkout")
            ->assertStatus(422);

        $this->assertSame(1, Registration::where('course_id', $course->id)->where('user_id', $student->id)->count());
    }
}
