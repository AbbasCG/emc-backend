<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Throwable;

/**
 * Resolves the ACTUAL Stripe payment method type used for a completed
 * payment (e.g. 'card' vs 'ideal'). The Checkout Session's own
 * `payment_method_types` field only lists what was OFFERED, never what the
 * customer actually chose — the real answer only exists on the confirmed
 * PaymentIntent, so this always looks there.
 *
 * Bound in the container (see AppServiceProvider) so tests can swap in a
 * fake without making a real Stripe network call.
 */
class StripePaymentMethodResolver
{
    public function __construct(private readonly StripeClient $stripe)
    {
    }

    /** Returns the raw Stripe payment method type string (e.g. 'card', 'ideal'), or null if it couldn't be resolved. */
    public function resolve(string $paymentIntentId): ?string
    {
        try {
            $intent = $this->stripe->paymentIntents->retrieve($paymentIntentId, [
                'expand' => ['payment_method'],
            ]);

            return $intent->payment_method->type ?? null;
        } catch (Throwable $e) {
            Log::warning('StripePaymentMethodResolver: failed to resolve payment method type', [
                'payment_intent_id' => $paymentIntentId,
                'error'              => $e->getMessage(),
            ]);

            return null;
        }
    }
}
