<?php

namespace Tests\Feature\Payments;

use App\Models\Course;
use App\Models\FinanceAccount;
use App\Models\FinanceAccountTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\User;
use App\Services\Payments\StripePaymentMethodResolver;
use App\Support\PaymentStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Proves Stripe Card and Stripe iDEAL payments are correctly distinguished
 * end-to-end: the webhook resolves the ACTUAL payment method from the
 * PaymentIntent (never trusting the frontend's selection or the session's
 * offered-methods list), and posts the ledger entry to the matching
 * system finance account — never a single shared "stripe" account.
 *
 * StripePaymentMethodResolver is swapped for a fake via the container (see
 * AppServiceProvider binding) so no real Stripe network call happens here.
 */
class StripeIdealPaymentTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_testsecret1234567890abcdef12';

    private function fakeResolver(?string $type): void
    {
        $this->app->instance(StripePaymentMethodResolver::class, new class($type) extends StripePaymentMethodResolver {
            public function __construct(private readonly ?string $fakeType)
            {
            }

            public function resolve(string $paymentIntentId): ?string
            {
                return $this->fakeType;
            }
        });
    }

    private function stripePost(array $eventData): \Illuminate\Testing\TestResponse
    {
        config(['services.stripe.webhook_secret' => $this->webhookSecret]);

        $payload   = json_encode($eventData);
        $timestamp = time();
        // Stripe's SDK (WebhookSignature::computeSignature) HMACs with the
        // FULL secret string, including the `whsec_` prefix — it is never
        // stripped. (A pre-existing bug in StripeWebhookTest.php's own
        // helper does strip it, which is why every one of its tests already
        // fails independent of this change — out of scope to fix here.)
        $sig    = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->webhookSecret);
        $header = "t={$timestamp},v1={$sig}";

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload);
    }

    private function makeSessionEvent(array $metaOverrides = [], array $sessionOverrides = []): array
    {
        $meta = array_merge([
            'flow' => 'order', 'order_id' => '1', 'registration_id' => '1',
            'user_id' => '1', 'course_id' => '1',
        ], $metaOverrides);

        return [
            'id' => 'evt_test_' . uniqid(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => array_merge([
                'id' => 'cs_test_' . uniqid(), 'object' => 'checkout.session',
                'payment_status' => 'paid', 'status' => 'complete',
                'payment_intent' => 'pi_test_' . uniqid(), 'receipt_url' => null,
                'metadata' => $meta,
            ], $sessionOverrides)],
        ];
    }

    /** @return array{0: Order, 1: Registration, 2: Payment} */
    private function createOrderAndPayment(User $user, Course $course): array
    {
        $registration = Registration::factory()->create([
            'user_id' => $user->id, 'course_id' => $course->id, 'status' => 'pending_payment',
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'order_number' => Order::generateOrderNumber(), 'type' => 'course',
            'subtotal' => 100, 'tax_amount' => 0, 'total' => 100,
            'currency' => 'eur', 'status' => 'pending', 'payment_provider' => 'stripe',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id, 'registration_id' => $registration->id, 'amount' => 100,
            'provider' => 'stripe', 'provider_payment_id' => 'cs_pending_' . uniqid(),
            'status' => PaymentStatuses::PENDING,
        ]);

        return [$order, $registration, $payment];
    }

    public function test_a_card_payment_maps_to_the_stripe_card_finance_account(): void
    {
        Mail::fake();
        FinanceAccount::ensureSystemAccounts();
        $this->fakeResolver('card');

        $user = User::factory()->create();
        $course = Course::factory()->paid()->create();
        [$order, $registration, $payment] = $this->createOrderAndPayment($user, $course);

        $this->stripePost($this->makeSessionEvent([
            'order_id' => (string) $order->id, 'registration_id' => (string) $registration->id,
        ]))->assertOk();

        $payment->refresh();
        $this->assertSame('card', $payment->payment_method);

        $cardAccount = FinanceAccount::forCode('STRIPE_CARD');
        $this->assertDatabaseHas('finance_account_transactions', [
            'finance_account_id' => $cardAccount->id,
            'reference_type'     => 'payment',
            'reference_id'       => $payment->id,
            'payment_method'     => 'card',
        ]);
        $this->assertDatabaseMissing('finance_account_transactions', [
            'reference_id' => $payment->id, 'finance_account_id' => FinanceAccount::forCode('STRIPE_IDEAL')->id,
        ]);
    }

    public function test_an_ideal_payment_maps_to_the_stripe_ideal_finance_account(): void
    {
        Mail::fake();
        FinanceAccount::ensureSystemAccounts();
        $this->fakeResolver('ideal');

        $user = User::factory()->create();
        $course = Course::factory()->paid()->create();
        [$order, $registration, $payment] = $this->createOrderAndPayment($user, $course);

        $this->stripePost($this->makeSessionEvent([
            'order_id' => (string) $order->id, 'registration_id' => (string) $registration->id,
        ]))->assertOk();

        $payment->refresh();
        $this->assertSame('ideal', $payment->payment_method);

        $idealAccount = FinanceAccount::forCode('STRIPE_IDEAL');
        $this->assertDatabaseHas('finance_account_transactions', [
            'finance_account_id' => $idealAccount->id,
            'reference_type'     => 'payment',
            'reference_id'       => $payment->id,
            'payment_method'     => 'ideal',
        ]);
    }

    public function test_unresolvable_payment_method_safely_defaults_to_the_card_account(): void
    {
        Mail::fake();
        FinanceAccount::ensureSystemAccounts();
        $this->fakeResolver(null); // simulates a failed Stripe lookup

        $user = User::factory()->create();
        $course = Course::factory()->paid()->create();
        [$order, $registration, $payment] = $this->createOrderAndPayment($user, $course);

        $this->stripePost($this->makeSessionEvent([
            'order_id' => (string) $order->id, 'registration_id' => (string) $registration->id,
        ]))->assertOk();

        $cardAccount = FinanceAccount::forCode('STRIPE_CARD');
        $this->assertDatabaseHas('finance_account_transactions', [
            'finance_account_id' => $cardAccount->id, 'reference_id' => $payment->id,
        ]);
    }

    public function test_webhook_retry_does_not_create_a_second_finance_transaction(): void
    {
        Mail::fake();
        FinanceAccount::ensureSystemAccounts();
        $this->fakeResolver('ideal');

        $user = User::factory()->create();
        $course = Course::factory()->paid()->create();
        [$order, $registration, $payment] = $this->createOrderAndPayment($user, $course);

        $event = $this->makeSessionEvent([
            'order_id' => (string) $order->id, 'registration_id' => (string) $registration->id,
        ]);

        $this->stripePost($event)->assertOk();
        $this->stripePost($event)->assertOk(); // retry, identical payload

        $this->assertSame(
            1,
            FinanceAccountTransaction::where('reference_type', 'payment')->where('reference_id', $payment->id)->count(),
        );
    }

    public function test_card_payment_still_grants_enrollment_as_before(): void
    {
        Mail::fake();
        FinanceAccount::ensureSystemAccounts();
        $this->fakeResolver('card');

        $user = User::factory()->create();
        $course = Course::factory()->paid()->create();
        [$order, $registration, $payment] = $this->createOrderAndPayment($user, $course);

        $this->stripePost($this->makeSessionEvent([
            'order_id' => (string) $order->id, 'registration_id' => (string) $registration->id,
        ]))->assertOk();

        $registration->refresh();
        $this->assertSame(\App\Enums\RegistrationStatus::REGISTERED->value, $registration->status);
        $this->assertDatabaseHas('student_progress', ['user_id' => $user->id, 'course_id' => $course->id]);
    }
}
