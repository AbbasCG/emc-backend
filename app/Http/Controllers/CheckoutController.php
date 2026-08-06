<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Course;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Services\AuditLogService;
use App\Services\CouponValidationService;
use App\Support\PaymentStatuses;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException as StripeApiException;
use Stripe\StripeClient;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(private readonly CouponValidationService $coupons)
    {
    }

    public function checkout(Request $request, Course $course): JsonResponse
    {
        $user = $request->user();

        $courseIsPaid = ($course->is_paid ?? false) || $course->type === 'paid';
        if (! $courseIsPaid) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الدورة مجانية ولا تتطلب دفعًا.',
                'code'    => 'course_not_paid',
            ], 422);
        }

        if (! $course->registration_open) {
            return response()->json([
                'success' => false,
                'message' => 'التسجيل مغلق لهذه الدورة.',
                'code'    => 'registration_closed',
            ], 422);
        }

        if ($course->price === null || (float) $course->price <= 0) {
            Log::error('Checkout blocked: course has no valid price', ['course_id' => $course->id]);
            return response()->json([
                'success' => false,
                'message' => 'تعذر إنشاء جلسة الدفع. حاول مرة أخرى.',
                'code'    => 'invalid_course_price',
            ], 422);
        }

        // Only a truly ACTIVE enrollment blocks checkout — a student stuck in
        // pending_payment (abandoned/expired Stripe session), payment_failed,
        // or cancelled must be able to retry. `registrations` has a DB-level
        // UNIQUE constraint on (user_id, course_id), so retrying never creates
        // a second row below — the existing row is reused/updated instead.
        $existingRegistration = Registration::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existingRegistration && in_array($existingRegistration->status, RegistrationStatus::activeEnrollment(), true)) {
            return response()->json([
                'success' => false,
                'message' => 'أنت مسجل بالفعل في هذه الدورة.',
                'code'    => 'already_enrolled',
            ], 422);
        }

        // The frontend may only ever send a code — never an amount, a
        // percentage, or a finance account. Every price in this method is
        // computed server-side from the coupon row itself.
        $couponCode = trim((string) $request->input('coupon_code', ''));
        $coupon     = null;
        if ($couponCode !== '') {
            $result = $this->coupons->validateForCourse($couponCode, $user, $course);
            if (! $result['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'code'    => $result['code'],
                ], 422);
            }
            $coupon = $result['coupon'];
        }

        if (trim((string) config('services.stripe.secret')) === '') {
            Log::error('Checkout blocked: Stripe secret key is not configured', ['course_id' => $course->id]);
            return response()->json([
                'success' => false,
                'message' => 'بوابة الدفع غير مُهيأة حالياً. يرجى المحاولة لاحقاً.',
                'code'    => 'stripe_not_configured',
            ], 503);
        }

        $pricing = $coupon
            ? $this->coupons->calculateDiscount($coupon, (float) $course->price)
            : ['original_cents' => (int) round(((float) $course->price) * 100), 'discount_cents' => 0, 'final_cents' => (int) round(((float) $course->price) * 100)];

        try {
            $couponRaceLost = false;

            $result = DB::transaction(function () use ($user, $course, $coupon, $pricing, $existingRegistration, &$couponRaceLost) {
                // Re-check the coupon's usage limit under a row lock, inside
                // the same transaction that will create the reservation —
                // this is what makes the "last remaining use, two concurrent
                // checkouts" scenario safe. Only one request can hold this
                // lock at a time; the loser sees the up-to-date used count.
                if ($coupon) {
                    $lockedCoupon = Coupon::lockForUpdate()->find($coupon->id);
                    if (! $lockedCoupon || $lockedCoupon->status !== 'active') {
                        $couponRaceLost = true;
                        return null;
                    }
                    if ($lockedCoupon->max_uses !== null) {
                        $usedCount = CouponUsage::where('coupon_id', $lockedCoupon->id)
                            ->where('status', CouponUsage::STATUS_USED)
                            ->count();
                        if ($usedCount >= $lockedCoupon->max_uses) {
                            $couponRaceLost = true;
                            return null;
                        }
                    }
                }

                $originalAmount = round($pricing['original_cents'] / 100, 2);
                $discountAmount = round($pricing['discount_cents'] / 100, 2);
                $finalAmount    = round($pricing['final_cents'] / 100, 2);
                $currency       = strtolower($course->currency ?? 'eur');

                $order = Order::create([
                    'user_id'                 => $user->id,
                    'course_id'                => $course->id,
                    'order_number'             => Order::generateOrderNumber(),
                    'type'                     => 'course',
                    'subtotal'                 => $originalAmount,
                    'discount_amount'          => $discountAmount,
                    'coupon_id'                => $coupon?->id,
                    'coupon_code_snapshot'     => $coupon?->code,
                    'discount_type_snapshot'   => $coupon?->discount_type,
                    'discount_value_snapshot'  => $coupon?->discount_value,
                    'tax_amount'               => 0,
                    'total'                    => $finalAmount,
                    'currency'                 => strtoupper($currency),
                    'status'                   => $finalAmount <= 0 ? 'paid' : 'pending',
                    'payment_provider'         => 'stripe',
                    'paid_at'                  => $finalAmount <= 0 ? now() : null,
                ]);

                // `registrations` has a UNIQUE constraint on (user_id, course_id) —
                // a retried checkout must reuse this student's existing row for
                // this course (abandoned pending_payment, payment_failed, or a
                // previously cancelled registration) rather than inserting a
                // second one, which would throw a QueryException.
                $registrationAttrs = [
                    'email'     => $user->email,
                    'full_name' => $user->name,
                    'phone'     => $user->phone ?? null,
                    'city'      => $user->city ?? null,
                    'country'   => $user->country ?? null,
                    'status'    => $finalAmount <= 0
                        ? RegistrationStatus::REGISTERED->value
                        : RegistrationStatus::PENDING_PAYMENT->value,
                ];

                if ($existingRegistration) {
                    $existingRegistration->update($registrationAttrs);
                    $registration = $existingRegistration;

                    // Any earlier pending order/payment/coupon-reservation tied
                    // to the abandoned attempt is superseded — cancel it so it
                    // doesn't linger as a phantom reserved coupon usage or a
                    // second "pending" order for the same registration.
                    Order::where('user_id', $user->id)->where('course_id', $course->id)
                        ->where('status', 'pending')->update(['status' => 'cancelled']);
                    CouponUsage::where('registration_id', $registration->id)
                        ->where('status', CouponUsage::STATUS_RESERVED)
                        ->update(['status' => CouponUsage::STATUS_CANCELLED]);
                } else {
                    $registration = Registration::create(array_merge($registrationAttrs, [
                        'user_id'   => $user->id,
                        'course_id' => $course->id,
                    ]));
                }

                // ── Zero-price result (Phase 13): a coupon may reduce the
                // price to exactly 0 — Stripe cannot create a €0.00 session,
                // so this is finalized directly through a trusted internal
                // path instead, preserving the same audit trail a real
                // payment would leave (Payment row, CouponUsage row, finance
                // visibility) rather than silently treating it as an
                // ordinary free-course enrollment. ──────────────────────────
                if ($finalAmount <= 0) {
                    $payment = Payment::create([
                        'order_id'        => $order->id,
                        'registration_id' => $registration->id,
                        'amount'          => 0,
                        'discount_amount' => $discountAmount,
                        'coupon_id'       => $coupon?->id,
                        'provider'        => 'internal',
                        'status'          => PaymentStatuses::CONFIRMED,
                        'confirmed_at'    => now(),
                    ]);

                    if ($coupon) {
                        CouponUsage::create([
                            'coupon_id'        => $coupon->id,
                            'user_id'          => $user->id,
                            'course_id'        => $course->id,
                            'order_id'         => $order->id,
                            'payment_id'       => $payment->id,
                            'registration_id'  => $registration->id,
                            'original_amount'  => $originalAmount,
                            'discount_amount'  => $discountAmount,
                            'final_amount'     => 0,
                            'currency'         => strtoupper($currency),
                            'status'           => CouponUsage::STATUS_USED,
                            'used_at'          => now(),
                        ]);
                        Coupon::where('id', $coupon->id)->increment('used_count');
                    }

                    StudentProgress::firstOrCreate(
                        ['user_id' => $user->id, 'course_id' => $course->id],
                        ['status' => 'in_progress', 'progress_percent' => 0, 'registration_id' => $registration->id],
                    );

                    return ['checkout_url' => null, 'free' => true, 'order_id' => $order->id];
                }

                $stripe = new StripeClient((string) config('services.stripe.secret'));

                $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
                $successUrl  = $frontendUrl . '/dashboard/student/payment-success?session_id={CHECKOUT_SESSION_ID}';
                $cancelUrl   = $frontendUrl . '/courses/' . $course->slug . '?payment=cancelled';

                $session = $stripe->checkout->sessions->create([
                    'mode'        => 'payment',
                    'success_url' => $successUrl,
                    'cancel_url'  => $cancelUrl,
                    // iDEAL is EUR-only per Stripe's own requirements — offering it
                    // for a non-EUR course price would fail at Stripe's side, so it
                    // stays card-only there rather than guessing a conversion.
                    'payment_method_types' => $currency === 'eur' ? ['card', 'ideal'] : ['card'],
                    'line_items'  => [[
                        'quantity'   => 1,
                        'price_data' => [
                            'currency'     => $currency,
                            'product_data' => ['name' => $course->title],
                            // The ONLY amount Stripe ever sees — computed
                            // server-side above, never accepted from the request.
                            'unit_amount'  => $pricing['final_cents'],
                        ],
                    ]],
                    'metadata' => [
                        'flow'             => 'order',
                        'order_id'         => (string) $order->id,
                        'registration_id'  => (string) $registration->id,
                        'course_id'        => (string) $course->id,
                        'user_id'          => (string) $registration->user_id,
                        'coupon_id'        => $coupon ? (string) $coupon->id : '',
                        'coupon_code'      => $coupon?->code ?? '',
                        'original_amount'  => (string) $originalAmount,
                        'discount_amount'  => (string) $discountAmount,
                        'final_amount'     => (string) $finalAmount,
                    ],
                ]);

                $order->update(['provider_session_id' => $session->id]);

                $payment = Payment::create([
                    'order_id'            => $order->id,
                    'registration_id'     => $registration->id,
                    'amount'              => $finalAmount,
                    'discount_amount'     => $discountAmount,
                    'coupon_id'           => $coupon?->id,
                    'provider'            => 'stripe',
                    'provider_payment_id' => $session->id,
                    'checkout_url'        => $session->url,
                    'status'              => PaymentStatuses::PENDING,
                ]);

                if ($coupon) {
                    // Reserved, not used — permanent usage/limits are only
                    // consumed once the Stripe webhook confirms payment.
                    CouponUsage::create([
                        'coupon_id'                   => $coupon->id,
                        'user_id'                      => $user->id,
                        'course_id'                    => $course->id,
                        'order_id'                     => $order->id,
                        'payment_id'                   => $payment->id,
                        'registration_id'               => $registration->id,
                        'stripe_checkout_session_id'    => $session->id,
                        'original_amount'               => $originalAmount,
                        'discount_amount'                => $discountAmount,
                        'final_amount'                    => $finalAmount,
                        'currency'                         => strtoupper($currency),
                        'status'                           => CouponUsage::STATUS_RESERVED,
                    ]);
                }

                return ['checkout_url' => $session->url, 'free' => false, 'order_id' => $order->id];
            });

            if ($couponRaceLost) {
                return response()->json([
                    'success' => false,
                    'message' => 'تم استنفاد عدد مرات استخدام هذا الرمز.',
                    'code'    => 'usage_limit_reached',
                ], 422);
            }

            AuditLogService::log('checkout_created', 'Order', $result['order_id'], null, null, [
                'course_id'  => $course->id,
                'user_id'    => $user->id,
                'coupon_id'  => $coupon?->id,
                'free'       => $result['free'],
            ]);

            return response()->json([
                'success'      => true,
                'checkout_url' => $result['checkout_url'],
                'free'         => $result['free'],
                'code'         => $result['free'] ? 'enrollment_completed' : 'checkout_session_created',
            ], $result['free'] ? 200 : 200);
        } catch (StripeApiException $e) {
            Log::error('Stripe API error during checkout', [
                'course_id' => $course->id,
                'user_id'   => $user->id,
                'message'   => $e->getMessage(),
            ]);
            return response()->json([
                'success'    => false,
                'message'    => 'تعذّر الاتصال ببوابة الدفع. تأكد من إعدادات Stripe.',
                'message_en' => $e->getMessage(),
                'code'       => 'checkout_session_failed',
            ], 502);
        } catch (QueryException $e) {
            // Never leak SQL/column details to the client — logged in full
            // server-side (this is exactly the shape of error that a schema
            // drift between code and DB produces, e.g. a NOT NULL column
            // that Registration::create() forgot to populate).
            Log::error('Database error during checkout', [
                'course_id' => $course->id,
                'user_id'   => $user->id,
                'sql_state' => $e->getCode(),
                'message'   => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'تعذر إنشاء جلسة الدفع. حاول مرة أخرى.',
                'code'    => 'checkout_session_failed',
            ], 500);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'تعذر إنشاء جلسة الدفع. حاول مرة أخرى.',
                'code'    => 'checkout_session_failed',
            ], 500);
        }
    }
}
