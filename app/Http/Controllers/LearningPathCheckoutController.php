<?php

namespace App\Http\Controllers;

use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AuditLogService;
use App\Services\LearningPathEnrollmentService;
use App\Support\PaymentStatuses;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException as StripeApiException;
use Stripe\StripeClient;
use Throwable;

class LearningPathCheckoutController extends Controller
{
    public function __construct(private readonly LearningPathEnrollmentService $enrollments)
    {
    }

    public function checkout(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        $path = LearningPath::where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        // A payment already received and under financial review is answered first and is never paid a second time.
        if (\App\Models\PaymentReconciliation::query()->where('user_id', $user->id)->where('learning_path_id', $path->id)
            ->where('status', \App\Models\PaymentReconciliation::STATUS_OPEN)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'تم استلام دفعتك وهي قيد المراجعة المالية. لا حاجة لدفع مرة أخرى، وسنتواصل معك.',
                'code'    => 'payment_under_review',
            ], 409);
        }

        // Learning is open to every account; teaching this path (or a course in it) is the one thing that rules it out.
        app(\App\Services\LearnerEnrollmentGuard::class)->assertCanEnrollInPath($user, $path);

        if ($path->isEnded()) {
            return response()->json([
                'success' => false,
                'message' => 'انتهى هذا المسار التعليمي ولا يمكن التسجيل فيه حالياً.',
                'code'    => 'program_ended',
            ], 422);
        }

        if (! $path->enrollment_open) {
            return response()->json([
                'success' => false,
                'message' => 'التسجيل مغلق لهذا المسار.',
                'code' => 'registration_closed',
            ], 422);
        }

        if (! $path->isPaid()) {
            return response()->json([
                'success' => false,
                'message' => 'هذا المسار مجاني ولا يتطلب دفعًا.',
                'code' => 'learning_path_not_paid',
            ], 422);
        }

        if (($path->finance_approval_status ?? null) !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'سعر هذا المسار بانتظار الاعتماد المالي.',
                'code' => 'finance_approval_required',
            ], 422);
        }

        $price = $this->effectivePrice($path);
        if ($price <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'تعذر قراءة سعر المسار.',
                'code' => 'invalid_learning_path_price',
            ], 422);
        }

        $existing = LearningPathEnrollment::where('learning_path_id', $path->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && in_array($existing->status, ['active', 'completed'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'أنت مسجل بالفعل في هذا المسار.',
                'code' => 'already_enrolled',
            ], 422);
        }

        if (trim((string) config('services.stripe.secret')) === '') {
            Log::error('Learning path checkout blocked: Stripe is not configured', [
                'learning_path_id' => $path->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'بوابة الدفع غير مهيأة حاليًا. يرجى المحاولة لاحقًا.',
                'code' => 'stripe_not_configured',
            ], 503);
        }

        try {
            $result = DB::transaction(function () use ($user, $path, $price) {
                Order::where('user_id', $user->id)
                    ->where('learning_path_id', $path->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled']);

                $currency = strtolower($path->currency ?: 'eur');
                $order = Order::create([
                    'user_id' => $user->id,
                    'learning_path_id' => $path->id,
                    'order_number' => Order::generateOrderNumber(),
                    'type' => 'learning_path',
                    'subtotal' => $price,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'total' => $price,
                    'currency' => strtoupper($currency),
                    'status' => 'pending',
                    'payment_provider' => 'stripe',
                ]);

                $enrollment = $this->enrollments->createPendingPayment($user, $path, $order);
                $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

                $stripe = new StripeClient((string) config('services.stripe.secret'));
                $session = $stripe->checkout->sessions->create([
                    'mode' => 'payment',
                    'success_url' => $frontendUrl . '/dashboard/student/payment-success?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => $frontendUrl . '/learning-paths/' . $path->slug . '?payment=cancelled',
                    'payment_method_types' => $currency === 'eur' ? ['card', 'ideal'] : ['card'],
                    'line_items' => [[
                        'quantity' => 1,
                        'price_data' => [
                            'currency' => $currency,
                            'product_data' => ['name' => $path->title],
                            'unit_amount' => (int) round($price * 100),
                        ],
                    ]],
                    'metadata' => [
                        'flow' => 'order',
                        'program_type' => 'learning_path',
                        'order_id' => (string) $order->id,
                        'learning_path_id' => (string) $path->id,
                        'learning_path_enrollment_id' => (string) $enrollment->id,
                        'user_id' => (string) $user->id,
                    ],
                ]);

                $order->update(['provider_session_id' => $session->id]);

                Payment::create([
                    'order_id' => $order->id,
                    'learning_path_enrollment_id' => $enrollment->id,
                    'amount' => $price,
                    'provider' => 'stripe',
                    'provider_payment_id' => $session->id,
                    'checkout_url' => $session->url,
                    'status' => PaymentStatuses::PENDING,
                ]);

                return [
                    'order_id' => $order->id,
                    'enrollment_id' => $enrollment->id,
                    'checkout_url' => $session->url,
                ];
            });

            AuditLogService::log('checkout_created', 'Order', $result['order_id'], null, null, [
                'learning_path_id' => $path->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'code' => 'checkout_session_created',
                'checkout_url' => $result['checkout_url'],
                'enrollment_id' => $result['enrollment_id'],
            ]);
        } catch (StripeApiException $exception) {
            Log::error('Stripe API error during learning path checkout', [
                'learning_path_id' => $path->id,
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'تعذر الاتصال ببوابة الدفع. حاول مرة أخرى.',
                'code' => 'checkout_session_failed',
            ], 502);
        } catch (QueryException $exception) {
            Log::error('Database error during learning path checkout', [
                'learning_path_id' => $path->id,
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'تعذر إنشاء جلسة الدفع. حاول مرة أخرى.',
                'code' => 'checkout_session_failed',
            ], 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'تعذر إنشاء جلسة الدفع. حاول مرة أخرى.',
                'code' => 'checkout_session_failed',
            ], 500);
        }
    }

    private function effectivePrice(LearningPath $path): float
    {
        $price = (float) $path->price;
        $discount = (float) ($path->discount_price ?? 0);

        return $discount > 0 && $discount < $price ? $discount : $price;
    }
}
