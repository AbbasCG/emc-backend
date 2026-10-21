<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Mail\PaymentConfirmedMail;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\CouponUsage;
use App\Models\FinanceAccount;
use App\Models\FinanceAccountTransaction;
use App\Models\Invoice;
use App\Models\LearningPathEnrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\PlatformNotification;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EmailService;
use App\Services\LearnerEnrollmentGuard;
use App\Services\LearningPathEnrollmentService;
use App\Services\NotificationService;
use App\Services\Payments\StripePaymentMethodResolver;
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
    public function __construct(
        private readonly StripePaymentMethodResolver $paymentMethodResolver,
        private readonly LearningPathEnrollmentService $learningPathEnrollments,
    ) {
    }

    /**
     * Convert Stripe metadata (StripeObject, ArrayObject, array, or plain object)
     * to a plain PHP associative array.
     *
     * NEVER use (array) cast directly on a StripeObject — it returns the internal
     * _values/_opts properties, not the actual metadata key-value pairs.
     */
    private function metadataArray(mixed $metadata): array
    {
        if ($metadata === null) {
            return [];
        }

        if (is_array($metadata)) {
            return $metadata;
        }

        // Stripe\StripeObject (and all subclasses) expose toArray()
        if (is_object($metadata) && method_exists($metadata, 'toArray')) {
            return (array) $metadata->toArray();
        }

        // Fallback: JSON round-trip to get a plain array from any object
        $json = json_encode($metadata);
        if ($json !== false) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function handle(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $secret    = (string) config('services.stripe.webhook_secret', '');

        if ($secret === '') {
            Log::error('Stripe webhook: STRIPE_WEBHOOK_SECRET is not configured.');
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
            $session = $event->data->object ?? null;

            Log::info('Stripe webhook received', [
                'event'   => $event->type,
                'session' => $session?->id,
            ]);

            if (($session?->id ?? null) === null) {
                Log::warning('Stripe webhook: missing session identifier.');
                return response()->json(['message' => 'Missing session identifier.'], 422);
            }

            // Convert metadata to a reliable plain array ONCE here
            $meta = $this->metadataArray($session->metadata ?? null);
            $flow = $meta['flow'] ?? '';

            Log::info('Stripe metadata', array_merge(['session' => $session->id], $meta));

            // Only handle the order-based flow created by CheckoutController
            if ($flow !== 'order') {
                Log::info('Stripe webhook: non-order flow — ignored', [
                    'flow'    => $flow ?: '(empty)',
                    'event'   => $event->type,
                    'session' => $session->id,
                ]);
                return response()->json(['message' => 'Not an order-based session — ignored.']);
            }

            if ($event->type === 'checkout.session.completed') {
                $this->handleCompleted($session, $meta);
            } elseif (in_array($event->type, ['checkout.session.expired', 'payment_intent.payment_failed'], true)) {
                $this->handleFailed($session->id, $meta);
            }

            return response()->json(['message' => 'Stripe webhook processed successfully.']);

        } catch (Throwable $e) {
            report($e);
            Log::error('Stripe webhook processing exception', [
                'error'   => $e->getMessage(),
                'session' => $session?->id ?? null,
            ]);
            return response()->json(['message' => 'Unable to process Stripe webhook.'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function handleCompleted(mixed $session, array $meta): void
    {
        $sessionId      = (string) $session->id;
        $orderId        = (int) ($meta['order_id']        ?? 0);
        $registrationId = (int) ($meta['registration_id'] ?? 0);
        $pathEnrollmentId = (int) ($meta['learning_path_enrollment_id'] ?? 0);
        $courseId       = (int) ($meta['course_id']       ?? 0);
        $userId         = (int) ($meta['user_id']         ?? 0);
        $paymentStatus  = $session->payment_status ?? null;
        $sessionStatus  = $session->status ?? null;

        Log::info('Stripe checkout.session.completed received', [
            'session_id'      => $sessionId,
            'order_id'        => $orderId,
            'registration_id' => $registrationId,
            'learning_path_enrollment_id' => $pathEnrollmentId,
            'course_id'       => $courseId,
            'user_id'         => $userId,
            'payment_status'  => $paymentStatus,
            'status'          => $sessionStatus,
        ]);

        // Guard: must be a paid/complete session
        if ($paymentStatus !== 'paid' && $sessionStatus !== 'complete') {
            Log::warning('Stripe webhook: session not paid — skipping', [
                'session_id'     => $sessionId,
                'payment_status' => $paymentStatus,
                'status'         => $sessionStatus,
            ]);
            return;
        }

        if ($orderId === 0 && $registrationId === 0) {
            Log::warning('Stripe webhook: metadata missing order_id and registration_id', [
                'session_id' => $sessionId,
                'meta'       => $meta,
            ]);
            return;
        }

        $order        = null;
        $registration = null;
        $pathEnrollment = null;
        $pathActivated = false;
        $payment      = null;
        $shouldMail   = false;
        $held         = null; // PaymentReconciliation, when the money is kept but the enrollment cannot be granted
        $redelivered  = null; // an open PaymentReconciliation found on a redelivery (its notifications are re-announced)

        // What to lock is worked out BEFORE the transaction: a plain read inside it would fix the snapshot ahead of the locks (MariaDB
        // REPEATABLE READ) and the conflict check below would then read the world as it was before a concurrent assignment committed.
        $lockIds  = $courseId > 0 ? [$courseId] : [];
        $lockPath = null;
        if ($pathEnrollmentId > 0) {
            $lockPath = LearningPathEnrollment::query()->whereKey($pathEnrollmentId)->value('learning_path_id');
            if ($lockPath !== null) {
                $lockPath = (int) $lockPath;
                $lockIds = array_merge($lockIds, app(LearnerEnrollmentGuard::class)->pathCourseIds($lockPath));
            }
        }

        DB::transaction(function () use (
            $session, $sessionId, $orderId, $registrationId, $pathEnrollmentId, $lockIds, $lockPath,
            &$order, &$registration, &$pathEnrollment, &$pathActivated, &$payment, &$shouldMail, &$held, &$redelivered
        ) {
            // ── Serialize with instructor assignments ─────────────────────────
            // Lock the course rows FIRST (ascending id), then the path row, before the order and the registration: the same order
            // every other writer uses, so the assignment of an instructor and this confirmation cannot both succeed. Whichever
            // commits second sees the other's result (see GuardsInstructorAssignment).
            app(LearnerEnrollmentGuard::class)->lockForEnrollment($lockIds, $lockPath);

            // ── Primary lookup: metadata IDs are the canonical source ─────────
            if ($orderId > 0) {
                $order = Order::lockForUpdate()->find($orderId);
            }
            if ($registrationId > 0) {
                $registration = Registration::lockForUpdate()->find($registrationId);
            }
            if ($pathEnrollmentId > 0) {
                $pathEnrollment = LearningPathEnrollment::lockForUpdate()->find($pathEnrollmentId);
            }

            Log::info('Stripe order found', [
                'order'        => $order?->id,
                'registration' => $registration?->id,
                'learning_path_enrollment' => $pathEnrollment?->id,
                'order_status' => $order?->status,
            ]);

            if (! $order && ! $registration) {
                Log::warning('Stripe webhook: order and registration not found — cannot process', [
                    'session_id'      => $sessionId,
                    'order_id'        => $orderId,
                    'registration_id' => $registrationId,
                ]);
                return;
            }

            // ── Idempotency: already paid ─────────────────────────────────────
            if ($order?->status === 'paid') {
                Log::info('Stripe webhook: order already paid — idempotent skip', [
                    'order_id'   => $order->id,
                    'session_id' => $sessionId,
                ]);
                // A redelivery of a payment that was held for review: nothing is changed, but if the first delivery died before its
                // notifications were stored they are sent now (they are de-duplicated by idempotency key, so this never doubles them).
                $redelivered = PaymentReconciliation::query()->where('order_id', $order->id)->where('status', PaymentReconciliation::STATUS_OPEN)->first();
                return;
            }

            // ── Locate payment: the exact session first, then the order, then the registration ───────────
            // Most specific first, and the NEWEST row of the broader matches: after a failed attempt and a retry, one registration has
            // several payments, and picking the old failed one would give it the new session id (a unique-key violation, a 500, and a
            // customer who paid but is not enrolled).
            $payment = Payment::where('provider_payment_id', $sessionId)->lockForUpdate()->first()
                ?? ($orderId > 0 ? Payment::where('order_id', $orderId)->latest('id')->lockForUpdate()->first() : null)
                ?? ($registrationId > 0 ? Payment::where('registration_id', $registrationId)->latest('id')->lockForUpdate()->first() : null);

            Log::info('Stripe payment found', [
                'payment'        => $payment?->id,
                'payment_status' => $payment?->status,
            ]);

            if (! $payment) {
                Log::warning('Stripe webhook: no Payment row found', [
                    'session_id'      => $sessionId,
                    'order_id'        => $orderId,
                    'registration_id' => $registrationId,
                ]);
                return;
            }

            // ── Idempotency: payment already confirmed ────────────────────────
            if ($payment->status === PaymentStatuses::CONFIRMED) {
                Log::info('Stripe webhook: payment already confirmed — idempotent skip', [
                    'payment_id' => $payment->id,
                    'session_id' => $sessionId,
                ]);
                $redelivered = PaymentReconciliation::query()->where('payment_id', $payment->id)->where('status', PaymentReconciliation::STATUS_OPEN)->first();
                return;
            }

            // ── Can the payer still be enrolled? ──────────────────────────────
            // Checkout refuses an instructor of the program, but an account can be ASSIGNED to teach it after it started paying. The
            // money is real and is recorded below exactly as for any payment; what is withheld is the enrollment, so a person never
            // gets learner access to what they teach. The open item is recorded for somebody to settle (PaymentReconciliation).
            // Nothing is refunded or cancelled here.
            $conflict = $this->instructorConflict($registration, $pathEnrollment);

            // ── Resolve the ACTUAL payment method used (never the frontend's
            //    selection, never the session's offered `payment_method_types`
            //    list — only the confirmed PaymentIntent knows what the
            //    customer actually chose) ────────────────────────────────────
            $paymentIntentId    = $session->payment_intent ?? null;
            $resolvedMethodType = $paymentIntentId
                ? $this->paymentMethodResolver->resolve((string) $paymentIntentId)
                : null;

            $financeAccountCode = match ($resolvedMethodType) {
                'ideal'  => FinanceAccount::CODE_STRIPE_IDEAL,
                'card'   => FinanceAccount::CODE_STRIPE_CARD,
                default  => FinanceAccount::CODE_STRIPE_CARD, // unresolved/unrecognized — safe default, never blocks the payment
            };
            if ($resolvedMethodType !== null && ! in_array($resolvedMethodType, ['card', 'ideal'], true)) {
                Log::warning('Stripe webhook: unrecognized payment method type — defaulted to STRIPE_CARD account', [
                    'payment_method_type' => $resolvedMethodType,
                    'payment_intent_id'   => $paymentIntentId,
                    'session_id'          => $sessionId,
                ]);
            }
            $storedPaymentMethod = $resolvedMethodType ?? 'card';

            // ── Update payment ────────────────────────────────────────────────
            $payment->update([
                'status'              => PaymentStatuses::CONFIRMED,
                'confirmed_at'        => now(),
                'provider_payment_id' => $sessionId,
                'receipt_url'         => $session->receipt_url ?? null,
                'payment_method'      => $storedPaymentMethod,
            ]);

            // ── Finalize coupon usage (Phase 15/16) ───────────────────────────
            // The reservation row created by CheckoutController is the
            // idempotency guard: only a row still in 'reserved' gets marked
            // 'used' and only then does the coupon's permanent counter move.
            // A webhook retry finds the row already 'used' and does nothing.
            $couponUsage = CouponUsage::where('payment_id', $payment->id)
                ->orWhere('stripe_checkout_session_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if ($couponUsage && $couponUsage->status === CouponUsage::STATUS_RESERVED) {
                $couponUsage->update([
                    'status'  => CouponUsage::STATUS_USED,
                    'used_at' => now(),
                ]);
                Coupon::where('id', $couponUsage->coupon_id)->increment('used_count');
            }

            // ── Update registration ───────────────────────────────────────────
            if ($registration && $conflict === null) {
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
                            'progress_percentage' => 0,
                        ]
                    );
                }
            }

            if ($pathEnrollment && $order?->type === 'learning_path' && $conflict === null) {
                $pathActivated = ! in_array($pathEnrollment->status, ['active', 'completed'], true);
                $pathEnrollment = $this->learningPathEnrollments->activate($pathEnrollment);
            }

            // ── Update order + create invoice ─────────────────────────────────
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

            // ── Finance ledger entry ──────────────────────────────────────────
            // Every confirmed Stripe payment must produce one FinanceAccountTransaction
            // on the configured Stripe finance account. Without this entry the account
            // balance never reflects online income.
            //
            // Idempotency: we check for an existing ledger entry keyed by
            // (reference_type='payment', reference_id=$payment->id) before creating.
            $ledgerExists = FinanceAccountTransaction::where('reference_type', 'payment')
                ->where('reference_id', $payment->id)
                ->exists();

            if (! $ledgerExists) {
                // Resolved by the payment's actual method (STRIPE_CARD vs
                // STRIPE_IDEAL) — never a single shared "stripe" account, so
                // card and iDEAL income are never mixed together.
                $stripeAccount = FinanceAccount::forCode($financeAccountCode);

                if ($stripeAccount) {
                    FinanceAccountTransaction::create([
                        'finance_account_id' => $stripeAccount->id,
                        'type'               => 'revenue',
                        'category'           => $order?->type === 'learning_path' ? 'learning_path_revenue' : 'course_revenue',
                        'amount'             => (float) $payment->amount,
                        'currency'           => $stripeAccount->currency,
                        'status'             => 'confirmed',
                        'description'        => 'Stripe - ' . ($order?->order_number ?? $sessionId),
                        'payment_method'     => $storedPaymentMethod,
                        'reference_type'     => 'payment',
                        'reference_id'       => $payment->id,
                        'external_reference' => $sessionId,
                        'transaction_date'   => now()->toDateString(),
                        'created_by'         => null,
                    ]);
                    $stripeAccount->increment('current_balance', (float) $payment->amount);
                } else {
                    Log::warning('Stripe webhook: system finance account not found for code=' . $financeAccountCode . '. Ledger entry skipped — run finance:ensure-system-accounts.', [
                        'payment_id' => $payment->id,
                        'session_id' => $sessionId,
                    ]);
                }
            }

            if ($conflict !== null) {
                $held = PaymentReconciliation::firstOrCreate(
                    ['payment_id' => $payment->id],
                    [
                        'order_id'                    => $order?->id,
                        'registration_id'             => $registration?->id,
                        'learning_path_enrollment_id' => $pathEnrollment?->id,
                        'user_id'                     => $conflict['user_id'],
                        'course_id'                   => $conflict['course_id'],
                        'learning_path_id'            => $conflict['learning_path_id'],
                        'reason'                      => PaymentReconciliation::REASON_INSTRUCTOR_CONFLICT,
                        'status'                      => PaymentReconciliation::STATUS_OPEN,
                        'details'                     => ['session_id' => $sessionId, 'amount' => (float) $payment->amount, 'currency' => $order?->currency],
                    ],
                );
            }

            $shouldMail = $conflict === null;

            Log::info('Stripe payment processed', [
                'order'        => $order?->id,
                'payment'      => $payment->id,
                'registration' => $registration?->id,
                'session_id'   => $sessionId,
            ]);
        });

        if ($held !== null && $payment) {
            $this->announceHold($held, $payment, recorded: true);

            return;
        }

        if ($redelivered !== null && $this->notificationsMissing($redelivered)) {
            $this->announceHold($redelivered, $redelivered->payment()->first() ?? $payment, recorded: false);

            return;
        }

        if ($redelivered !== null) {
            return;
        }

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
                'payment_confirmed',
                $registration->email,
                $registration->full_name ?? '',
                $registration->user_id,
                PaymentConfirmedMail::fromRegistration($registration),
                'تأكيد الدفع وإتمام التسجيل — ' . ($registration->course?->title ?? ''),
            );
        }

        if ($pathActivated && $pathEnrollment) {
            $pathEnrollment->loadMissing('learningPath.courses');
            NotificationService::learningPathEnrolled(
                $pathEnrollment->user_id,
                $pathEnrollment->learningPath->title,
                $pathEnrollment->learningPath->courses->count(),
                $pathEnrollment->learning_path_id,
                $pathEnrollment->learningPath->duration,
            );
            AuditLogService::log(
                'student_enrolled_after_payment',
                'LearningPathEnrollment',
                $pathEnrollment->id,
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{user_id: int, course_id: ?int, learning_path_id: ?int}|null set when the payer teaches what they paid for
     */
    private function instructorConflict(?Registration $registration, ?LearningPathEnrollment $pathEnrollment): ?array
    {
        $guard = app(LearnerEnrollmentGuard::class);

        if ($registration && $registration->user_id && $registration->course_id) {
            $user = User::find($registration->user_id);
            $course = Course::find($registration->course_id);
            if ($user && $course && $guard->teachesCourse($user, $course)) {
                return ['user_id' => $user->id, 'course_id' => $course->id, 'learning_path_id' => null];
            }
        }

        if ($pathEnrollment && $pathEnrollment->user_id) {
            $user = User::find($pathEnrollment->user_id);
            $path = $pathEnrollment->learningPath()->first();
            if ($user && $path && $guard->teachesLearningPath($user, $path)) {
                return ['user_id' => $user->id, 'course_id' => null, 'learning_path_id' => $path->id];
            }
        }

        return null;
    }

    /** Tells the payer their payment arrived and is being reviewed, and the people who settle it that there is an open item. */
    /**
     * Whether a redelivery should re-send the two reconciliation notifications: only for a case old enough that its own first
     * delivery has long finished announcing (deliveries arriving together must not all announce at once: they would race to insert
     * the same de-duplicated rows) and only when one of the two is actually missing.
     */
    private function notificationsMissing(PaymentReconciliation $held): bool
    {
        if ($held->created_at === null || $held->created_at->gt(now()->subMinute())) {
            return false;
        }

        $payerKey = 'payment_under_review:'.$held->payment_id;
        $staffKey = 'payment_reconciliation:'.$held->id;

        return ! PlatformNotification::query()->where('type', 'payment_under_review')->where('idempotency_key', $payerKey)->exists()
            || ! PlatformNotification::query()->where('type', 'payment_reconciliation_required')->where('idempotency_key', $staffKey)->exists();
    }

    /**
     * @param  bool  $recorded  true on the delivery that opened the case (audit + log once); false on a redelivery, which only re-sends
     *                          the notifications (idempotency keys make that a no-op when they already exist).
     */
    private function announceHold(PaymentReconciliation $held, ?Payment $payment, bool $recorded): void
    {
        if ($payment === null) {
            return;
        }

        if ($recorded) {
            AuditLogService::log('payment_held_for_reconciliation', 'Payment', $payment->id, null, null, [
                'reconciliation_id' => $held->id,
                'reason'            => $held->reason,
                'course_id'         => $held->course_id,
                'user_id'           => $held->user_id,
            ]);
            Log::warning('Stripe payment confirmed but enrollment withheld (instructor conflict)', ['payment' => $payment->id, 'reconciliation' => $held->id]);
        }

        $staff = User::whereIn('role', ['admin', 'super_admin', 'finance_manager'])->where('is_active', true)->pluck('id')->all();
        NotificationService::send($staff, 'payment_reconciliation_required', 'دفعة تحتاج مراجعة',
            'وصلت دفعة مؤكَّدة لبرنامج يُدرّسه الدافع نفسه، فلم يُفعَّل التسجيل. راجع بند التسوية.',
            ['entity_type' => 'payment_reconciliation', 'entity_id' => $held->id, 'idempotency_key' => 'payment_reconciliation:'.$held->id, 'icon' => 'alert-triangle', 'color' => 'amber']);

        NotificationService::send($held->user_id, 'payment_under_review', 'تم استلام دفعتك',
            'استلمنا دفعتك وهي قيد المراجعة من فريق المالية. سنتواصل معك بخصوصها.',
            ['entity_type' => 'payment', 'entity_id' => $payment->id, 'idempotency_key' => 'payment_under_review:'.$payment->id]);
    }

    private function handleFailed(string $sessionId, array $meta): void
    {
        $orderId = (int) ($meta['order_id'] ?? 0);

        DB::transaction(function () use ($sessionId, $orderId) {
            // The exact session first (a late event for an old attempt must not touch the newer one), then the order's latest payment.
            $payment = Payment::where('provider_payment_id', $sessionId)->lockForUpdate()->first()
                ?? ($orderId > 0 ? Payment::where('order_id', $orderId)->latest('id')->lockForUpdate()->first() : null);

            if (! $payment || $payment->status === PaymentStatuses::CONFIRMED) {
                return;
            }

            $payment->update(['status' => PaymentStatuses::FAILED]);

            if ($payment->order_id) {
                Order::where('id', $payment->order_id)
                    ->where('status', '!=', 'paid')
                    ->update(['status' => 'failed']);
            }

            // Release the reservation — it never counted toward any usage
            // limit, so there is nothing to roll back beyond the row itself.
            CouponUsage::where('payment_id', $payment->id)
                ->where('status', CouponUsage::STATUS_RESERVED)
                ->update(['status' => CouponUsage::STATUS_CANCELLED]);
        });
    }
}
