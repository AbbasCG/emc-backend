<?php

namespace Tests\Feature;

use App\Jobs\SendPlatformNotificationJob;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\User;
use App\Support\PaymentStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesStripe;
use Tests\TestCase;

/**
 * "Payment received — under review" end to end for LEARNING PATHS (the course flow is covered by PaymentReconciliationTest), and the
 * delivery guarantees of the two reconciliation notifications: Arabic, no payment secrets, only the right people, sent once however
 * many times Stripe delivers the event, and recoverable when the first delivery died before the notifications were stored.
 */
class PaymentReviewIntegrationTest extends TestCase
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

    private function user(string $role, bool $active = true): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    /** @return array{0: User, 1: LearningPath, 2: LearningPathEnrollment, 3: Order, 4: Payment} a paid-path payer, assigned to teach the path while paying */
    private function paidPathPayer(bool $assigned): array
    {
        $payer = $this->user('hr_manager');
        $course = Course::factory()->free()->create();
        $path = LearningPath::create([
            'title' => 'مسار', 'slug' => 'path-'.uniqid(), 'status' => 'published', 'enrollment_open' => true, 'price' => 50, 'currency' => 'EUR',
            'duration' => 1, 'duration_unit' => 'weeks', 'language' => 'ar', 'level' => 'beginner', 'short_description' => 'x', 'full_description' => 'x',
            'finance_approval_status' => 'approved',
        ]);
        $path->courses()->attach($course->id, ['sort_order' => 1]);
        $enrollment = LearningPathEnrollment::create(['learning_path_id' => $path->id, 'user_id' => $payer->id, 'status' => 'pending_payment']);
        $order = Order::create(['user_id' => $payer->id, 'learning_path_id' => $path->id, 'order_number' => Order::generateOrderNumber(), 'type' => 'learning_path', 'subtotal' => 50, 'tax_amount' => 0, 'total' => 50, 'currency' => 'eur', 'status' => 'pending', 'payment_provider' => 'stripe']);
        $enrollment->update(['order_id' => $order->id]);
        $payment = Payment::create(['order_id' => $order->id, 'learning_path_enrollment_id' => $enrollment->id, 'amount' => 50, 'provider' => 'stripe', 'provider_payment_id' => 'cs_test_path_'.uniqid(), 'status' => PaymentStatuses::PENDING, 'checkout_url' => 'https://checkout.stripe.test/pay/x']);
        if ($assigned) {
            $path->update(['instructor_id' => Instructor::create(['user_id' => $payer->id, 'name' => $payer->name])->id]);
        }

        return [$payer, $path, $enrollment, $order, $payment];
    }

    private function deliver(User $payer, LearningPathEnrollment $enrollment, Order $order, Payment $payment)
    {
        return $this->stripeWebhook('checkout.session.completed', $payment->provider_payment_id, [
            'order_id' => (string) $order->id, 'learning_path_enrollment_id' => (string) $enrollment->id, 'user_id' => (string) $payer->id,
        ], $this->paidObject());
    }

    // ── learning path endpoints ──────────────────────────────────────────────

    public function test_a_path_payer_under_review_is_told_so_and_never_asked_to_pay_again(): void
    {
        [$payer, $path, $enrollment, $order, $payment] = $this->paidPathPayer(true);
        $this->deliver($payer, $enrollment, $order, $payment)->assertOk();
        $this->assertSame(1, PaymentReconciliation::where('learning_path_id', $path->id)->where('status', 'open')->count());

        $status = $this->actingAs($payer, 'sanctum')->getJson("/api/learning-paths/{$path->slug}/enrollment-status")->assertOk();
        $status->assertJsonPath('enrolled', false)->assertJsonPath('payment_under_review', true)->assertJsonPath('requires_checkout', false)->assertJsonPath('checkout_url', null);

        $this->actingAs($payer, 'sanctum')->postJson("/api/learning-paths/{$path->slug}/enroll")->assertStatus(409)->assertJsonPath('code', 'payment_under_review');
        $this->actingAs($payer, 'sanctum')->postJson("/api/learning-paths/{$path->slug}/checkout")->assertStatus(409)->assertJsonPath('code', 'payment_under_review');
        $this->actingAs($payer, 'sanctum')->getJson("/api/student/learning-paths/{$path->id}")->assertStatus(409)->assertJsonPath('code', 'payment_under_review');

        $row = collect($this->actingAs($payer, 'sanctum')->getJson('/api/student/learning-paths')->assertOk()->json('data'))->firstWhere('enrollment_id', $enrollment->id);
        $this->assertSame('pending_payment', $row['enrollment_status']);
        $this->assertTrue($row['payment_under_review']);

        $this->assertSame(1, Payment::where('order_id', $order->id)->count(), 'no second payment / checkout session was created');
    }

    public function test_an_unpaid_pending_path_enrollment_still_asks_for_payment(): void
    {
        [$payer, $path, $enrollment] = $this->paidPathPayer(false);

        $status = $this->actingAs($payer, 'sanctum')->getJson("/api/learning-paths/{$path->slug}/enrollment-status")->assertOk();
        $status->assertJsonPath('payment_under_review', false)->assertJsonPath('requires_checkout', true);
        $this->actingAs($payer, 'sanctum')->postJson("/api/learning-paths/{$path->slug}/enroll")->assertStatus(402);
        $row = collect($this->actingAs($payer, 'sanctum')->getJson('/api/student/learning-paths')->json('data'))->firstWhere('enrollment_id', $enrollment->id);
        $this->assertFalse($row['payment_under_review']);
    }

    // ── notification delivery ────────────────────────────────────────────────

    public function test_the_two_notifications_are_arabic_carry_no_payment_secrets_and_reach_only_the_right_people(): void
    {
        [$payer, , $enrollment, $order, $payment] = $this->paidPathPayer(true);
        $finance = $this->user('finance_manager');
        $admin = $this->user('admin');
        $superAdmin = $this->user('super_admin');
        $inactiveFinance = $this->user('finance_manager', false);
        $bystanders = [$this->user('volunteer'), $this->user('hr_manager'), $this->user('instructor'), $this->user('student')];

        $this->deliver($payer, $enrollment, $order, $payment)->assertOk();

        foreach ([$finance, $admin, $superAdmin] as $staff) {
            $this->assertSame(1, PlatformNotification::where('type', 'payment_reconciliation_required')->where('user_id', $staff->id)->count(), "{$staff->role} is told once");
        }
        $this->assertSame(0, PlatformNotification::where('type', 'payment_reconciliation_required')->where('user_id', $inactiveFinance->id)->count(), 'an inactive account is not');
        foreach ($bystanders as $other) {
            $this->assertSame(0, PlatformNotification::whereIn('type', ['payment_reconciliation_required', 'payment_under_review'])->where('user_id', $other->id)->count(), "{$other->role} is not told");
        }
        $this->assertSame(1, PlatformNotification::where('type', 'payment_under_review')->where('user_id', $payer->id)->count());

        foreach (PlatformNotification::whereIn('type', ['payment_reconciliation_required', 'payment_under_review'])->get() as $n) {
            $text = $n->title.' '.$n->message.' '.json_encode($n->getAttributes(), JSON_UNESCAPED_UNICODE);
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $n->message, 'Arabic content');
            $this->assertStringNotContainsString($payment->provider_payment_id, $text, 'no Stripe session id');
            $this->assertDoesNotMatchRegularExpression('/\b(pi_|cs_|sk_|whsec_|card|4242)/i', $text, 'no payment secrets');
            $this->assertStringNotContainsString('50', $n->message, 'no amount in the notification text');
        }
    }

    public function test_notifications_are_queued_after_commit_exactly_once_per_recipient_group(): void
    {
        Queue::fake();
        [$payer, , $enrollment, $order, $payment] = $this->paidPathPayer(true);
        $this->user('finance_manager');

        $this->deliver($payer, $enrollment, $order, $payment)->assertOk();

        Queue::assertPushed(SendPlatformNotificationJob::class, fn ($job) => $job->type === 'payment_under_review' && $job->userIds === [$payer->id] && $job->afterCommit === true);
        Queue::assertPushed(SendPlatformNotificationJob::class, fn ($job) => $job->type === 'payment_reconciliation_required' && $job->afterCommit === true);
        Queue::assertPushed(SendPlatformNotificationJob::class, 2);
    }

    public function test_a_redelivered_webhook_never_duplicates_the_case_the_audit_entry_or_the_notifications(): void
    {
        [$payer, , $enrollment, $order, $payment] = $this->paidPathPayer(true);
        $this->user('finance_manager');

        for ($i = 0; $i < 4; $i++) {
            $this->deliver($payer, $enrollment, $order, $payment)->assertOk();
        }

        $this->assertSame(1, PaymentReconciliation::count());
        $this->assertSame(1, PlatformNotification::where('type', 'payment_under_review')->count());
        $this->assertSame(1, PlatformNotification::where('type', 'payment_reconciliation_required')->count());
        $this->assertSame(1, AuditLog::where('action', 'payment_held_for_reconciliation')->count());
    }

    public function test_a_redelivery_recovers_notifications_lost_after_the_first_delivery_committed(): void
    {
        [$payer, , $enrollment, $order, $payment] = $this->paidPathPayer(true);
        $this->user('finance_manager');

        $this->deliver($payer, $enrollment, $order, $payment)->assertOk();
        // The first delivery committed the money and the case, then died before the notifications were stored (queue push failed).
        PlatformNotification::whereIn('type', ['payment_under_review', 'payment_reconciliation_required'])->delete();

        // A delivery arriving right behind the first one never re-announces (concurrent deliveries would race to insert the same rows)...
        $this->deliver($payer, $enrollment, $order, $payment)->assertOk();
        $this->assertSame(0, PlatformNotification::whereIn('type', ['payment_under_review', 'payment_reconciliation_required'])->count());

        // ...Stripe's later retry does, once the first delivery has long since finished.
        PaymentReconciliation::query()->update(['created_at' => now()->subMinutes(10)]);
        $this->deliver($payer, $enrollment, $order, $payment)->assertOk();

        $this->assertSame(1, PlatformNotification::where('type', 'payment_under_review')->where('user_id', $payer->id)->count());
        $this->assertSame(1, PlatformNotification::where('type', 'payment_reconciliation_required')->count());
        $this->assertSame(1, PaymentReconciliation::count());
        $this->assertSame(PaymentStatuses::CONFIRMED, $payment->fresh()->status);
        $this->assertSame(1, \App\Models\Invoice::where('order_id', $order->id)->count());
    }

    public function test_a_redelivery_for_a_payment_with_no_open_case_announces_nothing(): void
    {
        [$payer, , $enrollment, $order, $payment] = $this->paidPathPayer(false); // nobody teaches it: a normal enrollment
        $this->user('finance_manager');

        $this->deliver($payer, $enrollment, $order, $payment)->assertOk();
        $this->deliver($payer, $enrollment, $order, $payment)->assertOk();

        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertSame(0, PaymentReconciliation::count());
        $this->assertSame(0, PlatformNotification::whereIn('type', ['payment_under_review', 'payment_reconciliation_required'])->count());
    }
}
