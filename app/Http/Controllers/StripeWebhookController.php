<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Mail\CourseRegistrationConfirmation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StudentProgress;
use App\Services\AuditLogService;
use App\Support\PaymentStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            $session   = $event->data->object ?? null;
            $sessionId = $session?->id ?? null;
            $metadata  = (array) ($session?->metadata ?? []);

            if ($sessionId === null) {
                return response()->json(['message' => 'Missing session identifier.'], 422);
            }

            // Only handle the order-based flow from CheckoutController
            if (($metadata['flow'] ?? '') !== 'order') {
                return response()->json(['message' => 'Not an order-based session — ignored.']);
            }

            if ($event->type === 'checkout.session.completed') {
                $this->handleCompleted($sessionId, $session);
            } elseif (in_array($event->type, ['checkout.session.expired', 'payment_intent.payment_failed'], true)) {
                $this->handleFailed($sessionId);
            }

            return response()->json(['message' => 'Stripe webhook processed successfully.']);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Unable to process Stripe webhook.'], 500);
        }
    }

    private function handleCompleted(string $sessionId, mixed $session): void
    {
        $registration = null;
        $order        = null;
        $payment      = null;
        $shouldMail   = false;

        DB::transaction(function () use ($sessionId, $session, &$registration, &$order, &$payment, &$shouldMail) {
            $payment = Payment::with(['registration', 'order'])
                ->where('provider', 'stripe')
                ->where('provider_payment_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if (! $payment || $payment->status === PaymentStatuses::CONFIRMED) {
                return;
            }

            $order = $payment->order;
            if ($order && $order->status === 'paid') {
                return;
            }

            $payment->update([
                'status'       => PaymentStatuses::CONFIRMED,
                'confirmed_at' => now(),
            ]);

            $registration = $payment->registration;
            if ($registration) {
                $registration->update(['status' => RegistrationStatus::REGISTERED->value]);
                $registration->load('course.instructor', 'course.features', 'course.track');

                // Create StudentProgress if not already existing
                if ($registration->course_id) {
                    StudentProgress::firstOrCreate([
                        'user_id'   => $registration->user_id,
                        'course_id' => $registration->course_id,
                    ], [
                        'status'            => 'in_progress',
                        'progress_percent'  => 0,
                        'registration_id'   => $registration->id,
                    ]);
                }
            }

            if ($order) {
                $order->update([
                    'status'                     => 'paid',
                    'paid_at'                    => now(),
                    'provider_payment_intent_id' => $session?->payment_intent ?? null,
                ]);

                // Generate invoice idempotently
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
        });

        if (! $payment || ! $shouldMail) {
            return;
        }

        AuditLogService::log('payment_completed', 'Payment', $payment->id, null, null, [
            'order_id'   => $order?->id,
            'session_id' => $sessionId,
        ]);

        if ($registration) {
            AuditLogService::log('student_enrolled_after_payment', 'Registration', $registration->id);

            Mail::to($registration->email)
                ->queue(new CourseRegistrationConfirmation($registration));
        }
    }

    private function handleFailed(string $sessionId): void
    {
        DB::transaction(function () use ($sessionId) {
            $payment = Payment::where('provider', 'stripe')
                ->where('provider_payment_id', $sessionId)
                ->lockForUpdate()
                ->first();

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
