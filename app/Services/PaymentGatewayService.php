<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Registration;
use Exception;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Stripe\StripeClient;

class PaymentGatewayService
{
    public function createCoursePayment(Registration $registration, Course $course, string $provider): array
    {
        return match ($provider) {
            'stripe' => $this->createStripePayment($registration, $course),
            'paypal' => $this->createPaypalPayment($registration, $course),
            default => throw new Exception('Unsupported payment provider.'),
        };
    }

    protected function createStripePayment(Registration $registration, Course $course): array
    {
        $stripe = new StripeClient((string) config('services.stripe.secret'));

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'success_url' => config('app.frontend_payment_success_url'),
            'cancel_url' => config('app.frontend_payment_cancel_url', config('app.frontend_payment_success_url')),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => $course->title,
                    ],
                    'unit_amount' => (int) round(((float) $course->price) * 100),
                ],
            ]],
            'metadata' => [
                'registration_id' => (string) $registration->id,
                'course_id' => (string) $course->id,
            ],
        ]);

        return [
            'provider' => 'stripe',
            'provider_payment_id' => $session->id,
            'checkout_url' => $session->url,
        ];
    }

    protected function createPaypalPayment(Registration $registration, Course $course): array
    {
        $paypal = new PayPalClient();
        $paypal->setApiCredentials(config('services.paypal'));
        $paypal->getAccessToken();

        $order = $paypal->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'registration_' . $registration->id,
                'custom_id' => (string) $registration->id,
                'description' => $course->title,
                'amount' => [
                    'currency_code' => 'EUR',
                    'value' => number_format((float) $course->price, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'return_url' => config('app.frontend_payment_success_url'),
                'cancel_url' => config('app.frontend_payment_cancel_url', config('app.frontend_payment_success_url')),
                'user_action' => 'PAY_NOW',
            ],
        ]);

        if (($order['id'] ?? null) === null) {
            throw new Exception('Unable to create PayPal order.');
        }

        $approveUrl = null;
        foreach (($order['links'] ?? []) as $link) {
            if (($link['rel'] ?? null) === 'approve') {
                $approveUrl = $link['href'] ?? null;
                break;
            }
        }

        if ($approveUrl === null) {
            throw new Exception('Unable to get PayPal checkout URL.');
        }

        return [
            'provider' => 'paypal',
            'provider_payment_id' => $order['id'],
            'checkout_url' => $approveUrl,
        ];
    }
}
