<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Models\Course;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Registration;
use App\Services\AuditLogService;
use App\Support\PaymentStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException as StripeApiException;
use Stripe\StripeClient;
use Throwable;

class CheckoutController extends Controller
{
    public function checkout(Request $request, Course $course): JsonResponse
    {
        $user = $request->user();

        $courseIsPaid = ($course->is_paid ?? false) || $course->type === 'paid';
        if (! $courseIsPaid) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الدورة مجانية ولا تتطلب دفعًا.',
            ], 422);
        }

        if (! $course->registration_open) {
            return response()->json([
                'success' => false,
                'message' => 'التسجيل مغلق لهذه الدورة.',
            ], 422);
        }

        $alreadyEnrolled = Registration::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereNotIn('status', [RegistrationStatus::CANCELLED->value])
            ->exists();

        if ($alreadyEnrolled) {
            return response()->json([
                'success' => false,
                'message' => 'أنت مسجل بالفعل في هذه الدورة.',
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($user, $course) {
                $order = Order::create([
                    'user_id'          => $user->id,
                    'course_id'        => $course->id,
                    'order_number'     => Order::generateOrderNumber(),
                    'type'             => 'course',
                    'subtotal'         => $course->price,
                    'tax_amount'       => 0,
                    'total'            => $course->price,
                    'currency'         => strtolower($course->currency ?? 'eur'),
                    'status'           => 'pending',
                    'payment_provider' => 'stripe',
                ]);

                $registration = Registration::create([
                    'user_id'   => $user->id,
                    'course_id' => $course->id,
                    'email'     => $user->email,
                    'name'      => $user->name,
                    'status'    => RegistrationStatus::PENDING_PAYMENT->value,
                ]);

                $stripe = new StripeClient((string) config('services.stripe.secret'));

                $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
                $successUrl  = $frontendUrl . '/dashboard/student/payment-success?session_id={CHECKOUT_SESSION_ID}';
                $cancelUrl   = $frontendUrl . '/courses/' . $course->slug . '?payment=cancelled';

                $session = $stripe->checkout->sessions->create([
                    'mode'        => 'payment',
                    'success_url' => $successUrl,
                    'cancel_url'  => $cancelUrl,
                    'line_items'  => [[
                        'quantity'   => 1,
                        'price_data' => [
                            'currency'     => strtolower($course->currency ?? 'eur'),
                            'product_data' => ['name' => $course->title],
                            'unit_amount'  => (int) round(((float) $course->price) * 100),
                        ],
                    ]],
                    'metadata' => [
                        'flow'            => 'order',
                        'order_id'        => (string) $order->id,
                        'registration_id' => (string) $registration->id,
                        'course_id'       => (string) $course->id,
                        'user_id'         => (string) $registration->user_id,
                    ],
                ]);

                $order->update(['provider_session_id' => $session->id]);

                Payment::create([
                    'order_id'            => $order->id,
                    'registration_id'     => $registration->id,
                    'amount'              => $course->price,
                    'provider'            => 'stripe',
                    'provider_payment_id' => $session->id,
                    'checkout_url'        => $session->url,
                    'status'              => PaymentStatuses::PENDING,
                ]);

                return ['checkout_url' => $session->url, 'order_id' => $order->id];
            });

            AuditLogService::log('checkout_created', 'Order', $result['order_id'], null, null, [
                'course_id' => $course->id,
                'user_id'   => $user->id,
            ]);

            return response()->json([
                'success'      => true,
                'checkout_url' => $result['checkout_url'],
            ]);
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
            ], 502);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'تعذر إنشاء جلسة الدفع. حاول مرة أخرى.',
            ], 500);
        }
    }
}
