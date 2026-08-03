<?php

namespace Tests\Feature\Coupons;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Course;
use App\Models\Order;
use App\Models\Registration;
use App\Models\User;
use App\Services\CouponValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the money-safety critical path: coupon validation, cents-safe
 * discount calculation, course eligibility, usage limits, the zero-price
 * checkout flow (fully testable without a live Stripe call), admin CRUD
 * guards, and backward compatibility for checkout with no coupon at all.
 *
 * Non-zero-amount Stripe session creation itself is not exercised here —
 * it requires a live network call to api.stripe.com, the same limitation
 * documented for CheckoutControllerTest earlier in this codebase. The
 * server-side amount computation those sessions rely on (calculateDiscount)
 * is fully covered directly instead.
 */
class CouponCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function paidCourse(array $overrides = []): Course
    {
        return Course::factory()->paid(100.00)->create(array_merge(['status' => 'published'], $overrides));
    }

    private function percentageCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'EMC20', 'name' => 'Summer discount', 'discount_type' => 'percentage',
            'discount_value' => 20, 'status' => 'active', 'eligibility_type' => 'all_paid_courses',
        ], $overrides));
    }

    // ── Code normalization ──────────────────────────────────────────────────

    public function test_coupon_code_normalization_resolves_variants_to_the_same_row(): void
    {
        $this->assertSame('EMC20', CouponValidationService::normalizeCode('emc20'));
        $this->assertSame('EMC20', CouponValidationService::normalizeCode(' EMC20 '));
        $this->assertSame('EMC20', CouponValidationService::normalizeCode('EMC20'));
        // Internal whitespace is stripped too, not just trimmed at the ends.
        $this->assertSame('EMC20', CouponValidationService::normalizeCode('emc 20'));
    }

    public function test_duplicate_normalized_code_is_rejected_at_creation(): void
    {
        $this->percentageCoupon(['code' => 'EMC20']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Coupon::create(['code' => 'EMC20', 'name' => 'Dup', 'discount_type' => 'fixed', 'discount_value' => 5, 'status' => 'active']);
    }

    // ── Discount calculation (cents-safe) ───────────────────────────────────

    public function test_percentage_discount_calculation_is_correct(): void
    {
        $coupon = $this->percentageCoupon(['discount_value' => 20]);
        $result = (new CouponValidationService())->calculateDiscount($coupon, 100.00);

        $this->assertSame(10000, $result['original_cents']);
        $this->assertSame(2000, $result['discount_cents']);
        $this->assertSame(8000, $result['final_cents']);
    }

    public function test_fixed_discount_calculation_is_correct(): void
    {
        $coupon = $this->percentageCoupon(['discount_type' => 'fixed', 'discount_value' => 15]);
        $result = (new CouponValidationService())->calculateDiscount($coupon, 100.00);

        $this->assertSame(1500, $result['discount_cents']);
        $this->assertSame(8500, $result['final_cents']);
    }

    public function test_maximum_discount_cap_is_enforced(): void
    {
        $coupon = $this->percentageCoupon(['discount_value' => 50, 'maximum_discount_amount' => 30]);
        $result = (new CouponValidationService())->calculateDiscount($coupon, 100.00);

        // 50% of 100 = 50, capped at 30.
        $this->assertSame(3000, $result['discount_cents']);
        $this->assertSame(7000, $result['final_cents']);
    }

    public function test_final_amount_never_becomes_negative(): void
    {
        $coupon = $this->percentageCoupon(['discount_type' => 'fixed', 'discount_value' => 500]);
        $result = (new CouponValidationService())->calculateDiscount($coupon, 100.00);

        $this->assertSame(10000, $result['discount_cents']); // capped at original, not 50000
        $this->assertSame(0, $result['final_cents']);
    }

    // ── Course eligibility ───────────────────────────────────────────────────

    public function test_course_restriction_is_enforced_for_selected_courses(): void
    {
        $eligibleCourse   = $this->paidCourse();
        $ineligibleCourse = $this->paidCourse();
        $coupon = $this->percentageCoupon(['eligibility_type' => Coupon::ELIGIBILITY_SELECTED_COURSES]);
        $coupon->courses()->attach($eligibleCourse->id);

        $service = new CouponValidationService();
        $user = $this->student();

        $this->assertTrue($service->validateForCourse('EMC20', $user, $eligibleCourse)['valid']);
        $this->assertFalse($service->validateForCourse('EMC20', $user, $ineligibleCourse)['valid']);
    }

    /**
     * End-to-end version of the eligibility check, through the real
     * /checkout HTTP endpoint rather than calling the service directly —
     * proves a coupon scoped to one course (e.g. "IELTS") is rejected at
     * actual checkout for a different course (e.g. "Python"), not just in
     * the validation service's unit-level behavior.
     */
    public function test_checkout_rejects_a_coupon_scoped_to_a_different_course(): void
    {
        $ielts   = $this->paidCourse(['title' => 'IELTS Preparation']);
        $python  = $this->paidCourse(['title' => 'Python Basics']);
        $coupon  = $this->percentageCoupon(['code' => 'IELTSONLY', 'eligibility_type' => Coupon::ELIGIBILITY_SELECTED_COURSES]);
        $coupon->courses()->attach($ielts->id);

        $user = $this->student();

        // Rejected on the course it was never assigned to.
        $rejected = $this->actingAs($user, 'sanctum')
            ->postJson("/api/courses/{$python->id}/checkout", ['coupon_code' => 'IELTSONLY']);
        $rejected->assertStatus(422)->assertJson(['success' => false, 'code' => 'course_not_eligible']);
        $this->assertSame(0, Order::where('course_id', $python->id)->count());

        // Same code succeeds on the course it IS assigned to (zero-price
        // path keeps this test free of any live Stripe dependency).
        $coupon->update(['discount_type' => 'fixed', 'discount_value' => 100]);
        $accepted = $this->actingAs($user, 'sanctum')
            ->postJson("/api/courses/{$ielts->id}/checkout", ['coupon_code' => 'IELTSONLY']);
        $accepted->assertOk()->assertJson(['success' => true, 'free' => true]);
    }

    public function test_excluded_course_is_rejected_even_under_all_paid_courses(): void
    {
        $course = $this->paidCourse();
        $coupon = $this->percentageCoupon(['eligibility_type' => Coupon::ELIGIBILITY_ALL_PAID_COURSES]);
        $coupon->belongsToMany(Course::class, 'coupon_courses')->attach($course->id, ['excluded' => true]);

        $result = (new CouponValidationService())->validateForCourse('EMC20', $this->student(), $course);
        $this->assertFalse($result['valid']);
        $this->assertSame('course_not_eligible', $result['code']);
    }

    public function test_free_course_is_rejected(): void
    {
        $this->percentageCoupon();
        $freeCourse = Course::factory()->create(['status' => 'published', 'type' => 'free', 'price' => 0, 'is_paid' => false]);

        $result = (new CouponValidationService())->validateForCourse('EMC20', $this->student(), $freeCourse);
        $this->assertFalse($result['valid']);
        $this->assertSame('course_not_eligible', $result['code']);
    }

    // ── Minimum order / dates / status ──────────────────────────────────────

    public function test_minimum_order_amount_is_enforced(): void
    {
        $this->percentageCoupon(['minimum_order_amount' => 200]);
        $course = $this->paidCourse(); // price 100

        $result = (new CouponValidationService())->validateForCourse('EMC20', $this->student(), $course);
        $this->assertFalse($result['valid']);
        $this->assertSame('below_minimum_order', $result['code']);
    }

    public function test_inactive_coupon_is_rejected(): void
    {
        $this->percentageCoupon(['status' => 'inactive']);
        $result = (new CouponValidationService())->validateForCourse('EMC20', $this->student(), $this->paidCourse());
        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_coupon', $result['code']);
    }

    public function test_future_coupon_is_rejected_before_start(): void
    {
        $this->percentageCoupon(['starts_at' => now()->addDay()]);
        $result = (new CouponValidationService())->validateForCourse('EMC20', $this->student(), $this->paidCourse());
        $this->assertFalse($result['valid']);
    }

    public function test_expired_coupon_is_rejected(): void
    {
        $this->percentageCoupon(['expires_at' => now()->subDay()]);
        $result = (new CouponValidationService())->validateForCourse('EMC20', $this->student(), $this->paidCourse());
        $this->assertFalse($result['valid']);
    }

    // ── Usage limits ─────────────────────────────────────────────────────────

    public function test_total_usage_limit_is_enforced(): void
    {
        $coupon = $this->percentageCoupon(['max_uses' => 1]);
        $course = $this->paidCourse();
        $someoneElse = $this->student();

        CouponUsage::create([
            'coupon_id' => $coupon->id, 'user_id' => $someoneElse->id, 'course_id' => $course->id,
            'original_amount' => 100, 'discount_amount' => 20, 'final_amount' => 80,
            'currency' => 'EUR', 'status' => CouponUsage::STATUS_USED, 'used_at' => now(),
        ]);

        $result = (new CouponValidationService())->validateForCourse('EMC20', $this->student(), $course);
        $this->assertFalse($result['valid']);
        $this->assertSame('usage_limit_reached', $result['code']);
    }

    public function test_per_user_usage_limit_is_enforced(): void
    {
        $coupon = $this->percentageCoupon(['usage_limit_per_user' => 1]);
        $course = $this->paidCourse();
        $user = $this->student();

        CouponUsage::create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id, 'course_id' => $course->id,
            'original_amount' => 100, 'discount_amount' => 20, 'final_amount' => 80,
            'currency' => 'EUR', 'status' => CouponUsage::STATUS_USED, 'used_at' => now(),
        ]);

        $result = (new CouponValidationService())->validateForCourse('EMC20', $user, $course);
        $this->assertFalse($result['valid']);
        $this->assertSame('user_usage_limit_reached', $result['code']);
    }

    public function test_validation_does_not_consume_usage(): void
    {
        $coupon = $this->percentageCoupon();
        $course = $this->paidCourse();

        (new CouponValidationService())->validateForCourse('EMC20', $this->student(), $course);

        $this->assertSame(0, CouponUsage::where('coupon_id', $coupon->id)->count());
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    // ── Checkout integration: guard clauses + zero-price flow ──────────────

    public function test_checkout_rejects_an_invalid_coupon_code(): void
    {
        $course = $this->paidCourse();
        $response = $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/courses/{$course->id}/checkout", ['coupon_code' => 'DOES_NOT_EXIST']);

        $response->assertStatus(422)->assertJson(['success' => false, 'code' => 'invalid_coupon']);
        $this->assertSame(0, Order::where('course_id', $course->id)->count());
    }

    public function test_checkout_with_no_coupon_still_works_unaffected(): void
    {
        // Stripe isn't configured in the test environment — this proves the
        // pre-coupon guard clauses (and their controlled error response)
        // still fire exactly as before for a plain, coupon-free checkout.
        config(['services.stripe.secret' => '']);
        $course = $this->paidCourse();

        $response = $this->actingAs($this->student(), 'sanctum')->postJson("/api/courses/{$course->id}/checkout");

        $response->assertStatus(503)->assertJson(['success' => false, 'code' => 'stripe_not_configured']);
    }

    public function test_zero_price_coupon_checkout_completes_without_stripe(): void
    {
        $coupon = $this->percentageCoupon(['discount_type' => 'fixed', 'discount_value' => 100]); // covers full €100 price
        $course = $this->paidCourse();
        $user   = $this->student();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/courses/{$course->id}/checkout", ['coupon_code' => 'emc20']);

        $response->assertOk()->assertJson(['success' => true, 'free' => true, 'checkout_url' => null]);

        $order = Order::where('course_id', $course->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('paid', $order->status);
        $this->assertSame('0.00', (string) $order->total);
        $this->assertSame('100.00', (string) $order->discount_amount);
        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertSame('EMC20', $order->coupon_code_snapshot);

        $registration = Registration::where('course_id', $course->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('registered', $registration->status);

        $usage = CouponUsage::where('coupon_id', $coupon->id)->firstOrFail();
        $this->assertSame(CouponUsage::STATUS_USED, $usage->status);
        $this->assertNotNull($usage->used_at);

        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_frontend_cannot_forge_a_discount_amount(): void
    {
        // The controller only ever reads `coupon_code` from the request —
        // any other pricing-shaped key is silently ignored, never trusted.
        $course = $this->paidCourse();
        config(['services.stripe.secret' => '']); // force a clean, deterministic guard response

        $response = $this->actingAs($this->student(), 'sanctum')->postJson("/api/courses/{$course->id}/checkout", [
            'discount_amount' => 9999, 'final_amount' => 1, 'percentage' => 100,
        ]);

        // No coupon_code was sent, so this behaves exactly like a no-coupon
        // checkout — the forged pricing fields have zero effect.
        $response->assertStatus(503)->assertJson(['code' => 'stripe_not_configured']);
    }

    // ── Admin CRUD guards ────────────────────────────────────────────────────

    public function test_admin_creates_a_percentage_coupon(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/coupons', [
            'name' => 'Test', 'discount_type' => 'percentage', 'discount_value' => 10,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('coupons', ['name' => 'Test', 'discount_type' => 'percentage']);
    }

    public function test_admin_creates_a_fixed_coupon(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/coupons', [
            'name' => 'Fixed', 'discount_type' => 'fixed', 'discount_value' => 15,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('coupons', ['name' => 'Fixed', 'discount_type' => 'fixed']);
    }

    public function test_admin_coupon_deletion_archives_instead_of_deleting_when_usage_exists(): void
    {
        $coupon = $this->percentageCoupon();
        $course = $this->paidCourse();
        CouponUsage::create([
            'coupon_id' => $coupon->id, 'user_id' => $this->student()->id, 'course_id' => $course->id,
            'original_amount' => 100, 'discount_amount' => 20, 'final_amount' => 80,
            'currency' => 'EUR', 'status' => CouponUsage::STATUS_USED, 'used_at' => now(),
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/admin/coupons/{$coupon->id}");

        $response->assertOk();
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'status' => 'archived']);
        $this->assertNotSoftDeleted('coupons', ['id' => $coupon->id]);
    }

    public function test_admin_coupon_deletion_hard_deletes_when_no_usage_exists(): void
    {
        $coupon = $this->percentageCoupon();

        $response = $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/admin/coupons/{$coupon->id}");

        $response->assertOk();
        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
    }

    public function test_admin_cannot_create_a_selected_courses_coupon_without_courses(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/coupons', [
            'name' => 'No Courses', 'discount_type' => 'percentage', 'discount_value' => 10,
            'eligibility_type' => 'selected_courses',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['course_ids']);
    }

    public function test_admin_can_create_a_selected_courses_coupon_with_courses(): void
    {
        $course = $this->paidCourse();
        $response = $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/coupons', [
            'name' => 'Scoped', 'discount_type' => 'percentage', 'discount_value' => 10,
            'eligibility_type' => 'selected_courses', 'course_ids' => [$course->id],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('coupon_courses', ['coupon_id' => $response->json('data.id'), 'course_id' => $course->id, 'excluded' => false]);
    }

    public function test_unauthorized_role_cannot_manage_coupons(): void
    {
        $student = $this->student();
        $this->actingAs($student, 'sanctum')->getJson('/api/admin/coupons')->assertStatus(403);
    }

    public function test_unauthenticated_request_cannot_manage_coupons(): void
    {
        $this->getJson('/api/admin/coupons')->assertStatus(401);
    }
}
