<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Role;
use App\Models\StudentProgress;
use App\Models\User;
use App\Support\PaymentStatuses;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

/**
 * The paid enrollment lifecycle, in Stripe TEST mode, for every role: checkout, webhook confirmation, a webhook delivered twice,
 * a failed or abandoned payment, and the instructor conflict. Nothing here reaches the network: the Stripe SDK is given a fake
 * HTTP client that answers like Stripe's test API and records every call, and the webhooks are signed with a test secret.
 */
class UniversalLearnerPaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_testsecret1234567890abcdef12';

    /** @var list<array{method: string, url: string, params: mixed}> */
    private array $stripeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        config(['services.stripe.secret' => 'sk_test_'.str_repeat('x', 24), 'services.stripe.webhook_secret' => self::SECRET]);

        $calls = &$this->stripeCalls;
        ApiRequestor::setHttpClient(new class($calls) implements ClientInterface {
            /** @param list<array{method: string, url: string, params: mixed}> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->calls[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];
                $id = 'cs_test_'.substr(md5((string) count($this->calls).microtime()), 0, 16);

                return [json_encode(['id' => $id, 'object' => 'checkout.session', 'url' => "https://checkout.stripe.test/c/pay/{$id}", 'livemode' => false]), 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function everyRole(): array
    {
        $cases = [];
        foreach (array_unique([...array_column(RoleSeeder::ROLES, 'name'), 'teacher']) as $role) {
            $cases[$role] = [$role];
        }

        return $cases;
    }

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function paidCourse(array $attributes = []): Course
    {
        return Course::factory()->paid(60)->create(['registration_open' => true, 'status' => 'published'] + $attributes);
    }

    private function checkout(User $user, Course $course)
    {
        return $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/checkout", []);
    }

    /** A signed Stripe webhook, exactly as Stripe would send it. */
    private function webhook(string $type, string $sessionId, array $meta, array $object = []): \Illuminate\Testing\TestResponse
    {
        $event = ['id' => 'evt_test_'.uniqid(), 'type' => $type, 'data' => ['object' => array_merge([
            'id' => $sessionId, 'object' => 'checkout.session', 'metadata' => ['flow' => 'order'] + $meta,
        ], $object)]];

        return $this->deliver($event);
    }

    private function deliver(array $event): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::SECRET);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"], $payload);
    }

    /** @return array{0: Order, 1: Registration, 2: Payment} */
    private function rowsOf(User $user, Course $course): array
    {
        return [
            Order::where('user_id', $user->id)->where('course_id', $course->id)->firstOrFail(),
            Registration::where('user_id', $user->id)->where('course_id', $course->id)->firstOrFail(),
            Payment::whereHas('order', fn ($q) => $q->where('user_id', $user->id)->where('course_id', $course->id))->latest('id')->firstOrFail(),
        ];
    }

    private function metaOf(Order $order, Registration $registration, User $user, Course $course): array
    {
        return ['order_id' => (string) $order->id, 'registration_id' => (string) $registration->id, 'user_id' => (string) $user->id, 'course_id' => (string) $course->id];
    }

    private function paid(string $sessionId): array
    {
        return ['payment_status' => 'paid', 'status' => 'complete', 'payment_intent' => 'pi_test_'.uniqid()];
    }

    // ── every role: checkout → confirmation → duplicate delivery ──────────────

    #[DataProvider('everyRole')]
    public function test_a_paid_enrollment_runs_its_whole_lifecycle_for_every_role(string $role): void
    {
        $user = $this->userWithRole($role);
        $course = $this->paidCourse();

        // 1. Checkout: a Stripe test session is created, and the enrollment waits for the money.
        $response = $this->checkout($user, $course)->assertOk();
        $this->assertStringStartsWith('https://checkout.stripe.test/', (string) $response->json('checkout_url'));
        [$order, $registration, $payment] = $this->rowsOf($user, $course);
        $this->assertSame('pending', $order->status);
        $this->assertSame('pending_payment', $registration->status);
        $this->assertSame(PaymentStatuses::PENDING, $payment->status);
        $this->assertSame($role, $user->fresh()->role);
        $this->assertCount(1, $this->stripeCalls);
        $this->assertStringContainsString('/v1/checkout/sessions', $this->stripeCalls[0]['url']);
        $this->assertSame(6000, $this->stripeCalls[0]['params']['line_items'][0]['price_data']['unit_amount'], 'the amount is the server\'s, never the client\'s');

        // Not enrolled yet: no learning access, whatever the role.
        $this->assertSame([], $this->actingAs($user, 'sanctum')->getJson('/api/student/courses')->assertOk()->json('data'));

        // 2. Stripe confirms: the enrollment becomes active.
        $session = $payment->provider_payment_id;
        $meta = $this->metaOf($order, $registration, $user, $course);
        $this->webhook('checkout.session.completed', $session, $meta, $this->paid($session))->assertOk();

        [$order, $registration, $payment] = $this->rowsOf($user, $course);
        $this->assertSame('paid', $order->status);
        $this->assertSame('registered', $registration->status);
        $this->assertSame(PaymentStatuses::CONFIRMED, $payment->status);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        $this->assertSame(1, StudentProgress::where('user_id', $user->id)->where('course_id', $course->id)->count());
        $this->assertSame([$course->id], array_column($this->actingAs($user, 'sanctum')->getJson('/api/student/courses')->assertOk()->json('data'), 'course_id'));

        // 3. The same webhook delivered again changes nothing.
        $this->webhook('checkout.session.completed', $session, $meta, $this->paid($session))->assertOk();
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        $this->assertSame(1, Registration::where('user_id', $user->id)->where('course_id', $course->id)->count());
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame(1, StudentProgress::where('user_id', $user->id)->where('course_id', $course->id)->count());
        $this->assertSame('paid', $order->fresh()->status);

        // 4. Paying twice is not possible: the enrollment is active.
        $this->checkout($user, $course)->assertStatus(422)->assertJsonPath('code', 'already_enrolled');
        $this->assertSame(1, Order::where('user_id', $user->id)->where('course_id', $course->id)->count());
    }

    #[DataProvider('everyRole')]
    public function test_a_failed_or_abandoned_payment_leaves_no_access_and_can_be_retried_for_every_role(string $role): void
    {
        $user = $this->userWithRole($role);
        $course = $this->paidCourse();

        $this->checkout($user, $course)->assertOk();
        [$order, $registration, $payment] = $this->rowsOf($user, $course);
        $meta = $this->metaOf($order, $registration, $user, $course);

        // The learner closes the Stripe page; Stripe later expires the session.
        $this->webhook('checkout.session.expired', $payment->provider_payment_id, $meta, ['payment_status' => 'unpaid', 'status' => 'expired'])->assertOk();

        $this->assertSame(PaymentStatuses::FAILED, $payment->fresh()->status);
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame('pending_payment', $registration->fresh()->status, 'a failed payment never activates the enrollment');
        $this->assertSame([], $this->actingAs($user, 'sanctum')->getJson('/api/student/courses')->assertOk()->json('data'));

        // A late "expired" after a failure, or a failure event repeated, changes nothing more.
        $this->webhook('checkout.session.expired', $payment->provider_payment_id, $meta, ['payment_status' => 'unpaid', 'status' => 'expired'])->assertOk();
        $this->assertSame(PaymentStatuses::FAILED, $payment->fresh()->status);

        // Retry: the same registration row is reused, a new session is created, and paying it works.
        $this->checkout($user, $course)->assertOk();
        $this->assertSame(1, Registration::where('user_id', $user->id)->where('course_id', $course->id)->count());
        $payment2 = Payment::where('order_id', '!=', $order->id)->whereHas('order', fn ($q) => $q->where('user_id', $user->id))->latest('id')->firstOrFail();
        $order2 = $payment2->order;
        $this->webhook('checkout.session.completed', $payment2->provider_payment_id, $this->metaOf($order2, $registration, $user, $course), $this->paid($payment2->provider_payment_id))->assertOk();

        $this->assertSame('registered', $registration->fresh()->status);
        $this->assertSame('paid', $order2->fresh()->status);
        $this->assertSame(PaymentStatuses::FAILED, $payment->fresh()->status, 'the first attempt stays failed');
    }

    // ── instructor conflict ──────────────────────────────────────────────────

    #[DataProvider('everyRole')]
    public function test_an_instructor_of_the_course_never_reaches_stripe_whatever_their_role(string $role): void
    {
        $user = $this->userWithRole($role);
        $profile = Instructor::create(['user_id' => $user->id, 'name' => $user->name]);
        $taught = $this->paidCourse(['instructor_id' => $profile->id]);

        $response = $this->checkout($user, $taught);

        $response->assertStatus(403)->assertJsonPath('code', 'instructor_teaches_program');
        $this->assertSame($response->json('message'), $response->json('message_ar'));
        $this->assertSame([], $this->stripeCalls, 'no Stripe session is created for a refused enrollment');
        $this->assertSame(0, Order::where('user_id', $user->id)->count());
        $this->assertSame(0, Registration::where('user_id', $user->id)->count());
        $this->assertSame(0, Payment::count());

        // The same account pays for a course it does not teach.
        $this->checkout($user, $this->paidCourse())->assertOk();
    }

    public function test_a_coupon_cannot_be_previewed_for_a_course_the_user_teaches_to_learn_nothing_and_only_learners_pass(): void
    {
        $user = $this->userWithRole('hr_manager');
        $course = $this->paidCourse();

        // Any account may preview a coupon (the learner route); an unknown code is a validation answer, not a role refusal.
        $status = $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/coupon/validate", ['code' => 'NOPE'])->status();
        $this->assertNotContains($status, [401, 403]);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/courses/{$course->id}/coupon/validate", ['code' => 'NOPE'])->assertUnauthorized();
    }

    // ── webhook safety does not depend on who the learner is ─────────────────

    public function test_a_webhook_with_a_bad_signature_changes_nothing_even_for_an_admin_enrollment(): void
    {
        $user = $this->userWithRole('super_admin');
        $course = $this->paidCourse();
        $this->checkout($user, $course)->assertOk();
        [$order, $registration, $payment] = $this->rowsOf($user, $course);

        $event = ['id' => 'evt_x', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $payment->provider_payment_id, 'payment_status' => 'paid', 'status' => 'complete', 'metadata' => ['flow' => 'order'] + $this->metaOf($order, $registration, $user, $course)]]];
        $this->app['auth']->forgetGuards();
        $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=forged'], json_encode($event))->assertStatus(400);

        $this->assertSame('pending_payment', $registration->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_an_unpaid_completed_event_does_not_activate_the_enrollment(): void
    {
        $user = $this->userWithRole('partner');
        $course = $this->paidCourse();
        $this->checkout($user, $course)->assertOk();
        [$order, $registration, $payment] = $this->rowsOf($user, $course);

        // checkout.session.completed with payment_status "unpaid" (e.g. an asynchronous method still pending) must not grant access.
        $this->webhook('checkout.session.completed', $payment->provider_payment_id, $this->metaOf($order, $registration, $user, $course), ['payment_status' => 'unpaid', 'status' => 'open'])->assertOk();

        $this->assertSame('pending_payment', $registration->fresh()->status);
        $this->assertSame(PaymentStatuses::PENDING, $payment->fresh()->status);
    }

    public function test_a_confirmed_payment_is_never_downgraded_by_a_late_failure_event(): void
    {
        $user = $this->userWithRole('volunteer');
        $course = $this->paidCourse();
        $this->checkout($user, $course)->assertOk();
        [$order, $registration, $payment] = $this->rowsOf($user, $course);
        $meta = $this->metaOf($order, $registration, $user, $course);

        $this->webhook('checkout.session.completed', $payment->provider_payment_id, $meta, $this->paid($payment->provider_payment_id))->assertOk();
        $this->webhook('checkout.session.expired', $payment->provider_payment_id, $meta, ['payment_status' => 'unpaid', 'status' => 'expired'])->assertOk();

        $this->assertSame(PaymentStatuses::CONFIRMED, $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('registered', $registration->fresh()->status);
    }

    public function test_paid_enrollment_does_not_change_the_role_or_the_teaching_permissions_of_the_payer(): void
    {
        $user = $this->userWithRole('marketing_manager');
        $course = $this->paidCourse();
        $this->checkout($user, $course)->assertOk();
        [$order, $registration, $payment] = $this->rowsOf($user, $course);
        $this->webhook('checkout.session.completed', $payment->provider_payment_id, $this->metaOf($order, $registration, $user, $course), $this->paid($payment->provider_payment_id))->assertOk();

        $this->assertSame('marketing_manager', $user->fresh()->role);
        $this->actingAs($user, 'sanctum')->getJson('/api/instructor/courses')->assertForbidden();
    }
}
