<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\Invoice;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\PlatformNotification;
use App\Models\Registration;
use App\Models\Role;
use App\Models\StudentProgress;
use App\Models\User;
use App\Support\PaymentStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\FakesStripe;
use Tests\TestCase;

/**
 * A payer who is ASSIGNED to teach the program after they started paying, before the payment webhook arrives.
 *
 * The rule: the money is real and stays recorded as received (payment confirmed, order paid, invoice), but the person never gets
 * learner access to what they teach, and nothing is refunded or cancelled automatically. A PaymentReconciliation item is opened for
 * finance to settle by hand. A webhook delivered twice, or a failure arriving late, changes nothing more.
 */
class PaymentReconciliationTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        $this->fakeStripe();
    }

    protected function tearDown(): void
    {
        $this->restoreStripe();
        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /** @return array{0: User, 1: Course, 2: Order, 3: Registration, 4: Payment} a payer in the middle of checkout */
    private function payerAtCheckout(string $role = 'volunteer'): array
    {
        $payer = $this->userWithRole($role);
        $course = Course::factory()->paid(60)->create(['registration_open' => true, 'status' => 'published']);
        $this->actingAs($payer, 'sanctum')->postJson("/api/courses/{$course->id}/checkout", [])->assertOk();

        $order = Order::where('user_id', $payer->id)->firstOrFail();
        $registration = Registration::where('user_id', $payer->id)->firstOrFail();
        $payment = Payment::where('order_id', $order->id)->firstOrFail();

        return [$payer, $course, $order, $registration, $payment];
    }

    private function assignAsInstructor(User $user, Course $course): void
    {
        $profile = Instructor::firstOrCreate(['user_id' => $user->id], ['name' => $user->name]);
        $course->update(['instructor_id' => $profile->id]);
    }

    private function confirm(User $payer, Course $course, Order $order, Registration $registration, Payment $payment)
    {
        return $this->stripeWebhook('checkout.session.completed', $payment->provider_payment_id, [
            'order_id' => (string) $order->id, 'registration_id' => (string) $registration->id, 'user_id' => (string) $payer->id, 'course_id' => (string) $course->id,
        ], $this->paidObject());
    }

    // ── the race ─────────────────────────────────────────────────────────────

    public function test_a_payment_confirmed_after_the_payer_became_the_instructor_keeps_the_money_and_withholds_the_enrollment(): void
    {
        [$payer, $course, $order, $registration, $payment] = $this->payerAtCheckout();
        $this->assignAsInstructor($payer, $course);
        $finance = $this->userWithRole('finance_manager');
        $callsBefore = count($this->stripeCalls);

        $this->confirm($payer, $course, $order, $registration, $payment)->assertOk();

        // The money is recorded exactly as received.
        $this->assertSame(PaymentStatuses::CONFIRMED, $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        // The enrollment is not granted: no access, no progress.
        $this->assertSame('pending_payment', $registration->fresh()->status);
        $this->assertSame(0, StudentProgress::where('user_id', $payer->id)->where('course_id', $course->id)->count());
        $this->assertSame([], $this->actingAs($payer, 'sanctum')->getJson('/api/student/courses')->assertOk()->json('data'));
        // A clearly recorded open item, and nothing was refunded or cancelled automatically.
        $item = PaymentReconciliation::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('open', $item->status);
        $this->assertSame('instructor_conflict', $item->reason);
        $this->assertSame($payer->id, $item->user_id);
        $this->assertSame($course->id, $item->course_id);
        $this->assertGreaterThanOrEqual($callsBefore, count($this->stripeCalls));
        foreach ($this->stripeCalls as $call) {
            $this->assertDoesNotMatchRegularExpression('#refund|cancel|/expire#i', $call['url'], 'the platform never refunds or cancels a real payment by itself');
        }
        // The people who settle it, and the payer, are told.
        $this->assertSame(1, PlatformNotification::where('type', 'payment_under_review')->where('user_id', $payer->id)->count());
        $this->assertSame(1, PlatformNotification::where('type', 'payment_reconciliation_required')->where('user_id', $finance->id)->count());
    }

    public function test_a_duplicate_or_late_webhook_after_a_withheld_enrollment_changes_nothing_more(): void
    {
        [$payer, $course, $order, $registration, $payment] = $this->payerAtCheckout('partner');
        $this->assignAsInstructor($payer, $course);
        $finance = $this->userWithRole('finance_manager');

        $this->confirm($payer, $course, $order, $registration, $payment)->assertOk();
        $this->confirm($payer, $course, $order, $registration, $payment)->assertOk();
        $this->confirm($payer, $course, $order, $registration, $payment)->assertOk();
        // A late "expired" for the same session must not downgrade a confirmed payment either.
        $this->stripeWebhook('checkout.session.expired', $payment->provider_payment_id, ['order_id' => (string) $order->id, 'registration_id' => (string) $registration->id], ['payment_status' => 'unpaid', 'status' => 'expired'])->assertOk();

        $this->assertSame(1, PaymentReconciliation::count());
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame(PaymentStatuses::CONFIRMED, $payment->fresh()->status);
        $this->assertSame('pending_payment', $registration->fresh()->status);
        $this->assertSame(1, PlatformNotification::where('type', 'payment_under_review')->where('user_id', $payer->id)->count(), 'the payer is told once');
        $this->assertSame(1, PlatformNotification::where('type', 'payment_reconciliation_required')->where('user_id', $finance->id)->count(), 'finance is told once');
    }

    public function test_the_payer_cannot_pay_again_while_the_item_is_open(): void
    {
        [$payer, $course, $order, $registration, $payment] = $this->payerAtCheckout();
        $this->assignAsInstructor($payer, $course);
        $this->confirm($payer, $course, $order, $registration, $payment)->assertOk();

        // The payer already paid: the answer is "under review", not an instructor-conflict refusal, and never a second payment.
        $this->actingAs($payer, 'sanctum')->postJson("/api/courses/{$course->id}/checkout", [])->assertStatus(409)->assertJsonPath('code', 'payment_under_review');
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Order::count());
    }

    public function test_a_payment_for_a_course_the_payer_does_not_teach_is_unaffected(): void
    {
        [$payer, $course, $order, $registration, $payment] = $this->payerAtCheckout();
        // They teach a DIFFERENT course.
        $this->assignAsInstructor($payer, Course::factory()->free()->create());

        $this->confirm($payer, $course, $order, $registration, $payment)->assertOk();

        $this->assertSame('registered', $registration->fresh()->status);
        $this->assertSame(0, PaymentReconciliation::count());
    }

    public function test_the_normal_payment_still_enrolls_exactly_as_before(): void
    {
        [$payer, $course, $order, $registration, $payment] = $this->payerAtCheckout('student');

        $this->confirm($payer, $course, $order, $registration, $payment)->assertOk();

        $this->assertSame('registered', $registration->fresh()->status);
        $this->assertSame(1, StudentProgress::where('user_id', $payer->id)->count());
        $this->assertSame(0, PaymentReconciliation::count());
    }

    // ── assignment-time protection ───────────────────────────────────────────

    public function test_nobody_is_made_the_instructor_of_a_program_they_are_enrolled_in(): void
    {
        $learner = $this->userWithRole('volunteer');
        $course = Course::factory()->free()->create(['registration_open' => true, 'status' => 'published']);
        $this->actingAs($learner, 'sanctum')->postJson("/api/courses/{$course->id}/register", [])->assertCreated();
        $profile = Instructor::create(['user_id' => $learner->id, 'name' => $learner->name]);

        // Through the model (every code path) ...
        try {
            $course->update(['instructor_id' => $profile->id]);
            $this->fail('the assignment should have been refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('instructor_id', $e->errors());
        }
        $this->assertNull($course->fresh()->instructor_id);

        // ... and through the admin API.
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/courses/{$course->id}", ['instructor_id' => $profile->id])->assertStatus(422)->assertJsonValidationErrors('instructor_id');
        $this->assertNull($course->fresh()->instructor_id);
    }

    public function test_a_pending_payment_does_not_stop_the_assignment_that_the_webhook_then_reconciles(): void
    {
        [$payer, $course] = $this->payerAtCheckout();

        $this->assignAsInstructor($payer, $course);

        $this->assertNotNull($course->fresh()->instructor_id);
    }

    // ── settling the item ────────────────────────────────────────────────────

    private function openItem(): array
    {
        [$payer, $course, $order, $registration, $payment] = $this->payerAtCheckout();
        $this->assignAsInstructor($payer, $course);
        $this->confirm($payer, $course, $order, $registration, $payment)->assertOk();

        return [$payer, $course, $registration, $payment, PaymentReconciliation::firstOrFail()];
    }

    public function test_finance_sees_the_open_items_and_other_roles_do_not(): void
    {
        $this->openItem();
        $finance = $this->userWithRole('finance_manager');

        $rows = $this->actingAs($finance, 'sanctum')->getJson('/api/finance/payment-reconciliations?status=open')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        foreach (['volunteer', 'student', 'instructor', 'marketing_manager'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')->getJson('/api/finance/payment-reconciliations')->assertForbidden();
        }
    }

    public function test_enrollment_cannot_be_activated_while_the_conflict_stands_and_can_once_it_is_gone(): void
    {
        [$payer, $course, $registration, , $item] = $this->openItem();
        $finance = $this->userWithRole('finance_manager');
        $url = "/api/finance/payment-reconciliations/{$item->id}/resolve";

        $this->actingAs($finance, 'sanctum')->postJson($url, ['resolution' => 'enrollment_activated', 'note' => 'تفعيل بعد المراجعة'])->assertStatus(422)->assertJsonPath('code', 'instructor_teaches_program');
        $this->assertSame('open', $item->fresh()->status);
        $this->assertSame('pending_payment', $registration->fresh()->status);

        $course->forceFill(['instructor_id' => null])->save(); // the person was taken off the program
        $this->actingAs($finance, 'sanctum')->postJson($url, ['resolution' => 'enrollment_activated', 'note' => 'أُزيل الإسناد ثم فُعّل'])->assertOk();

        $this->assertSame('registered', $registration->fresh()->status);
        $this->assertSame(1, StudentProgress::where('user_id', $payer->id)->where('course_id', $course->id)->count());
        $this->assertSame('resolved', $item->fresh()->status);
        $this->assertSame($finance->id, $item->fresh()->resolved_by);
        $this->assertSame([$course->id], array_column($this->actingAs($payer, 'sanctum')->getJson('/api/student/courses')->assertOk()->json('data'), 'course_id'));
    }

    public function test_closing_without_enrollment_cancels_the_registration_but_keeps_the_payment_record(): void
    {
        [, , $registration, $payment, $item] = $this->openItem();
        $finance = $this->userWithRole('finance_manager');

        $this->actingAs($finance, 'sanctum')->postJson("/api/finance/payment-reconciliations/{$item->id}/resolve", ['resolution' => 'closed_without_enrollment', 'note' => 'استُرد المبلغ يدوياً من لوحة Stripe'])->assertOk();

        $this->assertSame('cancelled', $registration->fresh()->status);
        $this->assertSame(PaymentStatuses::CONFIRMED, $payment->fresh()->status, 'the record of what was received is never erased');
        $this->assertSame('closed_without_enrollment', $item->fresh()->resolution);
        foreach ($this->stripeCalls as $call) {
            $this->assertDoesNotMatchRegularExpression('#refund|cancel|/expire#i', $call['url'], 'nothing was refunded by the platform');
        }
    }

    public function test_an_item_is_settled_once_and_a_note_is_required(): void
    {
        [, , , , $item] = $this->openItem();
        $finance = $this->userWithRole('finance_manager');
        $url = "/api/finance/payment-reconciliations/{$item->id}/resolve";

        $this->actingAs($finance, 'sanctum')->postJson($url, ['resolution' => 'closed_without_enrollment'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->actingAs($finance, 'sanctum')->postJson($url, ['resolution' => 'refund_everything', 'note' => 'ملاحظة طويلة كفاية'])->assertStatus(422);
        $this->actingAs($finance, 'sanctum')->postJson($url, ['resolution' => 'closed_without_enrollment', 'note' => 'ملاحظة طويلة كفاية'])->assertOk();
        $this->actingAs($finance, 'sanctum')->postJson($url, ['resolution' => 'closed_without_enrollment', 'note' => 'ملاحظة طويلة كفاية'])->assertStatus(409);
    }

    // ── learning paths ───────────────────────────────────────────────────────

    public function test_a_learning_path_payment_confirmed_after_the_payer_became_its_instructor_is_withheld_the_same_way(): void
    {
        $payer = $this->userWithRole('hr_manager');
        $profile = Instructor::create(['user_id' => $payer->id, 'name' => $payer->name]);
        $course = Course::factory()->free()->create();
        $path = LearningPath::create([
            'title' => 'مسار', 'slug' => 'path-'.uniqid(), 'status' => 'published', 'enrollment_open' => true, 'price' => 50, 'currency' => 'EUR',
            'duration' => 1, 'duration_unit' => 'weeks', 'language' => 'ar', 'level' => 'beginner', 'short_description' => 'x', 'full_description' => 'x',
            'instructor_id' => $profile->id,
        ]);
        $path->courses()->attach($course->id, ['sort_order' => 1]);
        $enrollment = LearningPathEnrollment::create(['learning_path_id' => $path->id, 'user_id' => $payer->id, 'status' => 'pending_payment']);
        $order = Order::create(['user_id' => $payer->id, 'order_number' => Order::generateOrderNumber(), 'type' => 'learning_path', 'subtotal' => 50, 'tax_amount' => 0, 'total' => 50, 'currency' => 'eur', 'status' => 'pending', 'payment_provider' => 'stripe']);
        $payment = Payment::create(['order_id' => $order->id, 'learning_path_enrollment_id' => $enrollment->id, 'amount' => 50, 'provider' => 'stripe', 'provider_payment_id' => 'cs_test_path_'.uniqid(), 'status' => PaymentStatuses::PENDING]);

        $this->stripeWebhook('checkout.session.completed', $payment->provider_payment_id, ['order_id' => (string) $order->id, 'learning_path_enrollment_id' => (string) $enrollment->id, 'user_id' => (string) $payer->id], $this->paidObject())->assertOk();

        $this->assertSame(PaymentStatuses::CONFIRMED, $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('pending_payment', $enrollment->fresh()->status);
        $this->assertSame(0, Registration::where('user_id', $payer->id)->count(), 'no access to the courses of the path');
        $item = PaymentReconciliation::firstOrFail();
        $this->assertSame($path->id, $item->learning_path_id);
        $this->assertSame($enrollment->id, $item->learning_path_enrollment_id);
    }
}
