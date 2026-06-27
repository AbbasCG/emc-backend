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

    private string $webhookSecret = 'whsec_testsecret1234567890abcdef';

    /**
     * Build a Stripe-signed webhook request for the given event payload.
     */
    private function stripePost(array $eventData): \Illuminate\Testing\TestResponse
    {
        $payload   = json_encode($eventData);
        $timestamp = time();
        $secret    = substr($this->webhookSecret, 6); // strip 'whsec_'
        $sig       = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        $header    = "t={$timestamp},v1={$sig}";

        config(['services.stripe.webhook_secret' => $this->webhookSecret]);

        return $this->withHeaders(['Stripe-Signature' => $header])
            ->postJson('/api/webhooks/stripe', $eventData);
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

    private function createOrderAndPayment(User $user, Course $course): array
    {
        $registration = Registration::factory()->create([
            'user_id'   => $user->id,
            'course_id' => $course->id,
            'status'    => 'pending_payment',
        ]);

        $order = Order::create([
            'user_id'        => $user->id,
            'course_id'      => $course->id,
            'order_number'   => Order::generateOrderNumber(),
            'type'           => 'course',
            'subtotal'       => 100,
            'tax_amount'     => 0,
            'total'          => 100,
            'currency'       => 'eur',
            'status'         => 'pending',
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

    // ── TESTS ─────────────────────────────────────────────────────────────────

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

        // Send the same event twice
        $this->stripePost($eventData)->assertOk();
        $this->stripePost($eventData)->assertOk();

        // Only one invoice should be created
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        // Only one StudentProgress row
        $this->assertSame(1, StudentProgress::where([
            'user_id'   => $user->id,
            'course_id' => $course->id,
        ])->count());

        // Order is paid
        $this->assertSame('paid', Order::find($order->id)->status);
    }

    public function test_missing_metadata_order_id_logs_warning_and_returns_200(): void
    {
        $eventData = $this->makeSessionEvent(['order_id' => null, 'registration_id' => null]);
        unset($eventData['data']['object']['metadata']['order_id']);
        unset($eventData['data']['object']['metadata']['registration_id']);

        $response = $this->stripePost($eventData);

        // Should still return 200 (Stripe must not retry on missing data)
        $response->assertOk();

        // Nothing changed
        $this->assertDatabaseEmpty('orders');
    }

    public function test_non_order_flow_is_ignored(): void
    {
        $eventData = $this->makeSessionEvent(['flow' => 'legacy']);

        $response = $this->stripePost($eventData);
        $response->assertOk();
        $this->assertDatabaseEmpty('orders');
    }

    public function test_invalid_signature_returns_400(): void
    {
        config(['services.stripe.webhook_secret' => $this->webhookSecret]);

        $response = $this->withHeaders(['Stripe-Signature' => 't=1,v1=badsig'])
            ->postJson('/api/webhooks/stripe', $this->makeSessionEvent());

        $response->assertStatus(400);
    }

    public function test_expired_session_marks_payment_failed(): void
    {
        $user   = User::factory()->create();
        $course = Course::factory()->paid()->create();

        [$order, $registration, $payment] = $this->createOrderAndPayment($user, $course);

        $meta = [
            'flow'            => 'order',
            'order_id'        => (string) $order->id,
            'registration_id' => (string) $registration->id,
        ];

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
                    'metadata'       => $meta,
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
