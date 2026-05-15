<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResource;
use App\Http\Resources\RegistrationResource;
use App\Models\Payment;
use App\Mail\CourseRegistrationConfirmation;
use App\Support\FakePaymentEnvironment;
use App\Support\PaymentStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;

class PaymentController extends Controller
{
    public function confirmFakePayment(Payment $payment): JsonResponse
    {
        if (! FakePaymentEnvironment::allowed()) {
            return response()->json([
                'success' => false,
                'message' => 'Fake payment endpoints are only available in the local application environment.',
            ], 403);
        }

        if ($payment->provider !== 'fake') {
            return response()->json([
                'success' => false,
                'message' => 'The given payment is not a fake provider payment.',
            ], 422);
        }

        $this->confirmPayment('fake', (string) $payment->provider_payment_id);

        $payment->refresh();
        $payment->load('registration');

        return response()->json([
            'success'              => true,
            'message'              => 'Fake payment confirmed successfully.',
            'payment_status'       => $payment->status,
            'registration_status'  => $payment->registration?->status,
            'redirect_url'         => config('app.frontend_payment_success_url'),
            'payment'              => new PaymentResource($payment),
            'registration'         => $payment->relationLoaded('registration') && $payment->registration
                ? new RegistrationResource($payment->registration->loadMissing(['course.track', 'course.instructor', 'payments']))
                : null,
        ]);
    }

    public function failFakePayment(Payment $payment): JsonResponse
    {
        if (! FakePaymentEnvironment::allowed()) {
            return response()->json([
                'success' => false,
                'message' => 'Fake payment endpoints are only available in the local application environment.',
            ], 403);
        }

        if ($payment->provider !== 'fake') {
            return response()->json([
                'success' => false,
                'message' => 'The given payment is not a fake provider payment.',
            ], 422);
        }

        DB::transaction(function () use ($payment) {
            $payment->refresh();

            if ($payment->status === PaymentStatuses::CONFIRMED) {
                return;
            }

            $payment->update([
                'status' => PaymentStatuses::FAILED,
            ]);
        });

        $payment->refresh();
        $payment->load('registration');

        return response()->json([
            'success'             => true,
            'message'             => 'Fake payment marked as failed.',
            'payment_status'      => $payment->status,
            'registration_status' => $payment->registration?->status,
            'payment'             => new PaymentResource($payment),
            'registration'        => $payment->registration
                ? new RegistrationResource($payment->registration->loadMissing(['course.track', 'course.instructor', 'payments']))
                : null,
        ]);
    }

    public function stripeWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $secret = (string) config('services.stripe.webhook_secret', '');

        if ($secret === '') {
            Log::error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not configured.');
            return response()->json(['message' => 'Webhook secret not configured.'], 500);
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException) {
            return response()->json(['message' => 'Invalid Stripe webhook signature.'], 400);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Unable to parse Stripe webhook payload.'], 400);
        }

        try {
            $session = $event->data->object ?? null;
            $sessionId = $session->id ?? null;

            if ($sessionId === null) {
                return response()->json(['message' => 'Missing session identifier.'], 422);
            }

            if ($event->type === 'checkout.session.completed') {
                $this->confirmPayment('stripe', $sessionId);
            } elseif (in_array($event->type, ['checkout.session.expired', 'payment_intent.payment_failed'], true)) {
                $this->failPayment('stripe', $sessionId);
            }

            return response()->json(['message' => 'Stripe webhook processed successfully.']);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Unable to process Stripe webhook.'], 500);
        }
    }

    public function paypalWebhook(Request $request): JsonResponse
    {
        try {
            $paypal = new PayPalClient();
            $paypal->setApiCredentials(config('services.paypal'));

            $webhookId = (string) config('services.paypal.webhook_id', '');

            if ($webhookId !== '') {
                $paypal->getAccessToken();

                $verified = $paypal->verifyWebHook([
                    'auth_algo'         => $request->header('PAYPAL-AUTH-ALGO', ''),
                    'cert_url'          => $request->header('PAYPAL-CERT-URL', ''),
                    'transmission_id'   => $request->header('PAYPAL-TRANSMISSION-ID', ''),
                    'transmission_sig'  => $request->header('PAYPAL-TRANSMISSION-SIG', ''),
                    'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME', ''),
                    'webhook_id'        => $webhookId,
                    'webhook_event'     => $request->all(),
                ]);

                if (($verified['verification_status'] ?? null) !== 'SUCCESS') {
                    return response()->json(['message' => 'Invalid PayPal webhook signature.'], 400);
                }
            } else {
                Log::warning('PAYPAL_WEBHOOK_ID is not configured — skipping PayPal webhook signature verification.');
            }

            $eventType = $request->input('event_type');
            $resource = $request->input('resource', []);
            $orderId = $resource['id'] ?? null;

            if ($eventType === 'CHECKOUT.ORDER.APPROVED' && $orderId !== null) {
                $paypal->getAccessToken();

                $capture = $paypal->capturePaymentOrder($orderId);
                if (($capture['status'] ?? null) === 'COMPLETED') {
                    $this->confirmPayment('paypal', $orderId);
                } else {
                    $this->failPayment('paypal', $orderId);
                }
            } elseif ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
                $relatedOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
                if ($relatedOrderId !== null) {
                    $this->confirmPayment('paypal', $relatedOrderId);
                }
            } elseif (in_array((string) $eventType, ['CHECKOUT.ORDER.VOIDED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED'], true)) {
                if ($orderId !== null) {
                    $this->failPayment('paypal', $orderId);
                }
            }

            return response()->json(['message' => 'PayPal webhook processed successfully.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Unable to process PayPal webhook.'], 500);
        }
    }

    protected function confirmPayment(string $provider, string $providerPaymentId): void
    {
        $registration = null;
        $shouldSendMail = false;

        DB::transaction(function () use ($provider, $providerPaymentId, &$registration, &$shouldSendMail) {
            $payment = Payment::with('registration')
                ->where('provider', $provider)
                ->where('provider_payment_id', $providerPaymentId)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return;
            }

            if ($payment->status === PaymentStatuses::CONFIRMED) {
                return;
            }

            $payment->update([
                'status'       => PaymentStatuses::CONFIRMED,
                'confirmed_at' => $payment->confirmed_at ?? now(),
            ]);

            $registration = $payment->registration;
            $registration->update([
                'status' => 'Registered',
            ]);
            $registration->load('course.instructor', 'course.features', 'course.track');
            $shouldSendMail = true;
        });

        if (! $registration || ! $shouldSendMail) {
            return;
        }

        try {
            Mail::to($registration->email)
                ->send(new CourseRegistrationConfirmation($registration));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function failPayment(string $provider, string $providerPaymentId): void
    {
        DB::transaction(function () use ($provider, $providerPaymentId) {
            $payment = Payment::where('provider', $provider)
                ->where('provider_payment_id', $providerPaymentId)
                ->lockForUpdate()
                ->first();

            if (! $payment || $payment->status === PaymentStatuses::CONFIRMED) {
                return;
            }

            if ($payment->status === PaymentStatuses::FAILED) {
                return;
            }

            $payment->update([
                'status' => PaymentStatuses::FAILED,
            ]);
        });
    }
}
