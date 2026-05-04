<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Registration;
use App\Mail\CourseRegistrationConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Stripe\Webhook;
use Throwable;

class PaymentController extends Controller
{
    public function confirmFakePayment(Payment $payment): JsonResponse
    {
        if (! app()->environment('local')) {
            return response()->json([
                'message' => 'Fake payment endpoints are only available in local environment.',
            ], 403);
        }

        if ($payment->provider !== 'fake') {
            return response()->json([
                'message' => 'The given payment is not a fake provider payment.',
            ], 422);
        }

        $this->confirmPayment('fake', $payment->provider_payment_id);

        return response()->json([
            'message' => 'Fake payment confirmed successfully.',
            'data' => [
                'payment_id' => $payment->id,
                'status' => 'Payment Confirmed',
            ],
        ]);
    }

    public function failFakePayment(Payment $payment): JsonResponse
    {
        if (! app()->environment('local')) {
            return response()->json([
                'message' => 'Fake payment endpoints are only available in local environment.',
            ], 403);
        }

        if ($payment->provider !== 'fake') {
            return response()->json([
                'message' => 'The given payment is not a fake provider payment.',
            ], 422);
        }

        DB::transaction(function () use ($payment) {
            $payment->refresh();

            if ($payment->status === 'Payment Confirmed') {
                return;
            }

            $payment->update([
                'status' => 'Payment Failed',
            ]);
        });

        return response()->json([
            'message' => 'Fake payment marked as failed.',
            'data' => [
                'payment_id' => $payment->id,
                'status' => $payment->fresh()->status,
            ],
        ]);
    }

    public function stripeWebhook(Request $request): JsonResponse
    {
        try {
            $payload = $request->getContent();
            $signature = (string) $request->header('Stripe-Signature', '');
            $secret = (string) config('services.stripe.webhook_secret', '');

            $event = $secret !== ''
                ? Webhook::constructEvent($payload, $signature, $secret)
                : json_decode($payload, false, 512, JSON_THROW_ON_ERROR);

            $session = $event->data->object ?? null;
            $sessionId = $session->id ?? null;

            if ($sessionId === null) {
                return response()->json(['message' => 'Missing session identifier.'], 422);
            }

            if (($event->type ?? null) === 'checkout.session.completed') {
                $this->confirmPayment('stripe', $sessionId);
            } elseif (in_array(($event->type ?? ''), ['checkout.session.expired', 'payment_intent.payment_failed'], true)) {
                $this->failPayment('stripe', $sessionId);
            }

            return response()->json([
                'message' => 'Stripe webhook processed successfully.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to process Stripe webhook.',
            ], 500);
        }
    }

    public function paypalWebhook(Request $request): JsonResponse
    {
        try {
            $eventType = $request->input('event_type');
            $resource = $request->input('resource', []);
            $orderId = $resource['id'] ?? null;

            if ($eventType === 'CHECKOUT.ORDER.APPROVED' && $orderId !== null) {
                $paypal = new PayPalClient();
                $paypal->setApiCredentials(config('services.paypal'));
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

            return response()->json([
                'message' => 'PayPal webhook processed successfully.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to process PayPal webhook.',
            ], 500);
        }
    }

    protected function confirmPayment(string $provider, string $providerPaymentId): void
    {
        $registration = null;

        DB::transaction(function () use ($provider, $providerPaymentId, &$registration) {
            $payment = Payment::with('registration')
                ->where('provider', $provider)
                ->where('provider_payment_id', $providerPaymentId)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return;
            }

            $payment->update([
                'status' => 'Payment Confirmed',
                'confirmed_at' => $payment->confirmed_at ?? now(),
            ]);

            $registration = $payment->registration;
            $registration->update([
                'status' => 'Registered',
            ]);
            $registration->load('course.instructor', 'course.features');
        });

        if (! $registration) {
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

            if (! $payment || $payment->status === 'Payment Confirmed') {
                return;
            }

            $payment->update([
                'status' => 'Payment Failed',
            ]);
        });
    }
}
