<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Services\AuditLogService;
use App\Services\EmailService;
use App\Mail\CourseRegistrationConfirmation;
use App\Support\PaymentStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;

class StripeWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $secret    = (string) config('services.stripe.webhook_secret', '');

        if ($secret === '') {
            Log::error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not configured.');
            return response()->json(['message' => 'Webhook secret not configured.'], 500);
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException) {
            return response()->json(['message' => 'Invalid Stripe webhook signature.'], 400);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Unable to parse Stripe webhook payload.'], 400);
        }

        try {
            $session  = $event->data->object ?? null;
            $metadata = (array) ($session?->metadata ?? []);

            if (($session?->id ?? null) === null) {
                return response()->json(['message' => 'Missing session identifier.'], 422);
            }

            // Only handle the order-based flow from CheckoutController
            if (($metadata['flow'] ?? '') !== 'order') {
                Log::info('Stripe webhook: non-order flow ignored', [
                    'event_type' => $event->type,
                    'flow'       => $metadata['flow'] ?? null,
                    'session_id' => $session->id,
                ]);
                return response()->json(['message' => 'Not an order-based session — ignored.']);
            }

            if ($event->type === 'checkout.session.completed') {
                $this->handleCompleted($session);
            } elseif (in_array($event->type, ['checkout.session.expired', 'payment_intent.payment_failed'], true)) {
                $this->handleFailed($session->id);
            }

            return response()->json(['message' => 'Stripe webhook processed successfully.']);
        } catch (Throwable $e) {
            report($e);
            Log::error('Stripe webhook processing failed', [
                'error'      => $e->getMessage(),
                'session_id' => $session?->id ?? null,
            ]);
            return response()->json(['message' => 'Unable to process Stripe webhook.'], 500);
        }
    }

    private function handleCompleted(mixed $session): void
    {
        $meta           = $session->metadata ?? null;
        $sessionId      = $session->id ?? null;
        $orderId        = isset($meta->order_id)        ? (int) $meta->order_id        : null;
        $registrationId = isset($meta->registration_id) ? (int) $meta->registration_id : null;
        $paymentStatus  = $session->payment_status ?? null;
        $sessionStatus  = $session->status ?? null;

        Log::info('Stripe checkout.session.completed received', [
            'session_id'      => $sessionId,
            'order_id'        => $orderId,
            'registration_id' => $registrationId,
            'payment_status'  => $paymentStatus,
            'status'          => $sessionStatus,
        ]);

        // Guard: only process confirmed payments
        if ($paymentStatus !== 'paid' && $sessionStatus !== 'complete') {
            Log::warning('Stripe session not yet paid — skipping', [
                'session_id'     => $sessionId,
                'payment_status' => $paymentStatus,
                'status'         => $sessionStatus,
            ]);
            return;
        }

        $order        = null;
        $registration = null;
        $payment      = null;
        $shouldMail   = false;

        DB::transaction(function () use (
            $session, $sessionId, $orderId, $registrationId,
            &$order, &$registration, &$payment, &$shouldMail
        ) {
            // Primary lookup via metadata (reliable).
            // lockForUpdate prevents duplicate processing under concurrent delivery.
            if ($orderId !== null) {
                $order = Order::lockForUpdate()->find($orderId);
            }
            if ($registrationId !== null) {
                $registration = Registration::lockForUpdate()->find($registrationId);
            }

            if (! $order && ! $registration) {
                Log::warning('Stripe webhook: order and registration not found — cannot process', [
                    'session_id'      => $sessionId,
                    'order_id'        => $orderId,
                    'registration_id' => $registrationId,
                ]);
                return;
            }

            // Idempotency: order already paid means we already processed this event
            if ($order?->status === 'paid') {
                Log::info('Stripe webhook: order already paid — idempotent skip', [
                    'order_id'   => $orderId,
                    'session_id' => $sessionId,
                ]);
                return;
            }

            // Locate payment row: try order_id → registration_id → provider_payment_id
            $payment = Payment::where(function ($q) use ($orderId, $registrationId, $sessionId) {
                if ($orderId !== null) {
                    $q->orWhere('order_id', $orderId);
                }
                if ($registrationId !== null) {
                    $q->orWhere('registration_id', $registrationId);
                }
                if ($sessionId !== null) {
                    $q->orWhere('provider_payment_id', $sessionId);
                }
            })->lockForUpdate()->first();

            if (! $payment) {
                Log::warning('Stripe webhook: no Payment row found', [
                    'session_id'      => $sessionId,
                    'order_id'        => $orderId,
                    'registration_id' => $registrationId,
                ]);
                return;
            }

            // Idempotency: payment already confirmed
            if ($payment->status === PaymentStatuses::CONFIRMED) {
                Log::info('Stripe webhook: payment already confirmed — idempotent skip', [
                    'payment_id' => $payment->id,
                    'session_id' => $sessionId,
                ]);
                return;
            }

            // ── Update payment ────────────────────────────────────────────────
            $payment->update([
                'status'              => PaymentStatuses::CONFIRMED,
                'confirmed_at'        => now(),
                'provider_payment_id' => $sessionId,
                'receipt_url'         => $session->receipt_url ?? null,
            ]);

            // ── Update registration ───────────────────────────────────────────
            if ($registration) {
                $registration->update(['status' => RegistrationStatus::REGISTERED->value]);
                $registration->load('course.instructor', 'course.features', 'course.track');

                if ($registration->course_id && $registration->user_id) {
                    StudentProgress::firstOrCreate(
                        [
                            'user_id'   => $registration->user_id,
                            'course_id' => $registration->course_id,
                        ],
                        [
                            'status'           => 'in_progress',
                            'progress_percent' => 0,
                            'registration_id'  => $registration->id,
                        ]
                    );
                }
            }

            // ── Update order + invoice ────────────────────────────────────────
            if ($order) {
                $order->update([
                    'status'                     => 'paid',
                    'paid_at'                    => now(),
                    'provider_payment_intent_id' => $session->payment_intent ?? null,
                ]);

                Invoice::firstOrCreate(
                    ['order_id' => $order->id],
                    [
                        'user_id'        => $order->user_id,
                        'invoice_number' => Invoice::generateInvoiceNumber(),
                        'issued_at'      => now(),
                    ]
                );
            }

            $shouldMail = true;

            Log::info('Stripe webhook: payment processing complete', [
                'session_id'      => $sessionId,
                'payment_id'      => $payment->id,
                'order_id'        => $order?->id,
                'registration_id' => $registration?->id,
            ]);
        });

        if (! $shouldMail || ! $payment) {
            return;
        }

        AuditLogService::log('payment_completed', 'Payment', $payment->id, null, null, [
            'order_id'   => $order?->id,
            'session_id' => $sessionId,
        ]);

        if ($registration) {
            AuditLogService::log('student_enrolled_after_payment', 'Registration', $registration->id);

            EmailService::dispatch(
                'payment_confirmation',
                $registration->email,
                $registration->full_name ?? '',
                $registration->user_id,
                new CourseRegistrationConfirmation($registration),
                'تأكيد تسجيلك في الدورة'
            );
        }
    }

    private function handleFailed(string $sessionId): void
    {
        DB::transaction(function () use ($sessionId) {
            $payment = Payment::where(function ($q) use ($sessionId) {
                $q->where('provider_payment_id', $sessionId);
            })->lockForUpdate()->first();

            if (! $payment || $payment->status === PaymentStatuses::CONFIRMED) {
                return;
            }

            $payment->update(['status' => PaymentStatuses::FAILED]);

            if ($payment->order_id) {
                Order::where('id', $payment->order_id)
                    ->whereNotIn('status', ['paid'])
                    ->update(['status' => 'failed']);
            }
        });
    }
}
