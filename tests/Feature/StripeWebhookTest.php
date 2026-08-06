<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use App\Support\PaymentStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_testsecret1234567890abcdef12';

    /**
     * POST a Stripe-signed webhook event.
     *
     * The raw JSON body must match the signed payload exactly, so we build the
     * signature ourselves using the same bytes that will be sent as the body.
     */
    private function stripePost(array $eventData): \Illuminate\Testing\TestResponse
    {
        config(['services.stripe.webhook_secret' => $this->webhookSecret]);

        $payload   = json_encode($eventData);
        $timestamp = time();
        $rawSecret = substr($this->webhookSecret, 6); // strip 'whsec_'
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $rawSecret);
        $header    = "t={$timestamp},v1={$sig}";

        return $this->call(
            'POST',
            '/api/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'         => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $header,
            ],
            $payload
        );
    }

    private function makeSessionEvent(array $metaOverrides = [], array $sessionOverrides = []): array
    {
        $meta = array_merge([
            'flow'            => 'order',
            'order_id'        => '1',
            'registration_id' => '1',
            'user_id'         => '1',
            'course_id'       => '1',
        ], $metaOverrides);

        return [
            'id'   => 'evt_test_' . uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => array_merge([
                    'id'             => 'cs_test_' . uniqid(),
                    'object'         => 'checkout.session',
                    'payment_status' => 'paid',
                    'status'         => 'complete',
                    'payment_intent' => 'pi_test_' . uniqid(),
                    'receipt_url'    => null,
                    'metadata'       => $meta,
                ], $sessionOverrides),
            ],
        ];
    }

    /** Create User, Course, Registration, Order, Payment in pending state. */
    private function createOrderAndPayment(User $user, Course $course): array
    {
        $registration = Registration::factory()->create([
            'user_id'   => $user->id,
            'course_id' => $course->id,
            'status'    => 'pending_payment',
        ]);

        $order = Order::create([
            'user_id'          => $user->id,
            'course_id'        => $course->id,
            'order_number'     => Order::generateOrderNumber(),
            'type'             => 'course',
            'subtotal'         => 100,
            'tax_amount'       => 0,
            'total'            => 100,
            'currency'         => 'eur',
            'status'           => 'pending',
            'payment_provider' => 'stripe',
        ]);

        $payment = Payment::create([
            'order_id'            => $order->id,
            'registration_id'     => $registration->id,
            'amount'              => 100,
            'provider'            => 'stripe',
            'provider_payment_id' => 'cs_pending_' . uniqid(),
            'status'              => PaymentStatuses::PENDING,
        ]);

        return [$order, $registration, $payment];
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_checkout_completed_updates_order_payment_registration(): void
    {
        Mail::fake();

        $user   = User::factory()->create();
        $course = Course::factory()->paid()->create();

        [$order, $registration, $payment] = $this->createOrderAndPayment($user, $course);

        $eventData = $this->makeSessionEvent([
            'order_id'        => (string) $order->id,
            'registration_id' => (string) $registration->id,
            'user_id'         => (string) $user->id,
            'course_id'       => (string) $course->id,
        ]);
        $sessionId = $eventData['data']['object']['id'];

        $response = $this->stripePost($eventData);
        $response->assertOk();

        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('payments', [
            'id'                  => $payment->id,
            'status'              => PaymentStatuses::CONFIRMED,
            'provider_payment_id' => $sessionId,
        ]);
        $this->assertDatabaseHas('registrations', [
            'id'     => $registration->id,
            'status' => 'registered',
        ]);
        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
        ]);
        $this->assertDatabaseHas('student_progress', [
            'user_id'   => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        Mail::fake();

        $user   = User::factory()->create();
        $course = Course::factory()->paid()->create();

        [$order, $registration, $payment] = $this->createOrderAndPayment($user, $course);

        $eventData = $this->makeSessionEvent([
            'order_id'        => (string) $order->id,
            'registration_id' => (string) $registration->id,
        ]);

        // Send twice — same signed payload
        $this->stripePost($eventData)->assertOk();
        $this->stripePost($eventData)->assertOk();

        // Exactly one invoice
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        // Exactly one StudentProgress
        $this->assertSame(1, StudentProgress::where([
            'user_id'   => $user->id,
            'course_id' => $course->id,
        ])->count());

        $this->assertSame('paid', Order::find($order->id)->status);
    }

    public function test_missing_metadata_logs_warning_and_returns_200(): void
    {
        // No order_id / registration_id → warning logged, 200 returned (Stripe must not retry)
        $eventData = $this->makeSessionEvent([], [
            'metadata' => ['flow' => 'order'],  // flow present but IDs missing
        ]);

        $response = $this->stripePost($eventData);
        $response->assertOk();
        $this->assertDatabaseEmpty('orders');
    }

    public function test_non_order_flow_is_ignored(): void
    {
        $eventData = $this->makeSessionEvent(['flow' => 'legacy']);

        $response = $this->stripePost($eventData);
        $response->assertOk();
        $this->assertStringContainsString('ignored', $response->json('message'));
        $this->assertDatabaseEmpty('orders');
    }

    public function test_invalid_signature_returns_400(): void
    {
        config(['services.stripe.webhook_secret' => $this->webhookSecret]);

        $response = $this->call(
            'POST',
            '/api/webhooks/stripe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=badsig'],
            json_encode($this->makeSessionEvent())
        );

        $response->assertStatus(400);
    }

    public function test_expired_session_marks_payment_failed(): void
    {
        $user   = User::factory()->create();
        $course = Course::factory()->paid()->create();

        [$order, $registration, $payment] = $this->createOrderAndPayment($user, $course);

        $eventData = [
            'id'   => 'evt_test_' . uniqid(),
            'type' => 'checkout.session.expired',
            'data' => [
                'object' => [
                    'id'             => $payment->provider_payment_id,
                    'object'         => 'checkout.session',
                    'payment_status' => 'unpaid',
                    'status'         => 'expired',
                    'payment_intent' => null,
                    'metadata'       => [
                        'flow'            => 'order',
                        'order_id'        => (string) $order->id,
                        'registration_id' => (string) $registration->id,
                    ],
                ],
            ],
        ];

        $this->stripePost($eventData)->assertOk();

        $this->assertDatabaseHas('payments', [
            'id'     => $payment->id,
            'status' => PaymentStatuses::FAILED,
        ]);
    }
}
