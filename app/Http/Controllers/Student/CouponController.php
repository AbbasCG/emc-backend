<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\CouponValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function __construct(private readonly CouponValidationService $coupons)
    {
    }

    /**
     * POST /api/courses/{course}/coupon/validate
     *
     * Preview only — never creates a CouponUsage row. The same
     * CouponValidationService is revalidated again inside CheckoutController
     * at the moment of actual payment, so nothing here is trusted as final.
     */
    public function validate(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50']]);
        $user = $request->user();

        $result = $this->coupons->validateForCourse($data['code'], $user, $course);

        if (! $result['valid']) {
            return response()->json([
                'valid'   => false,
                'message' => $result['message'],
                'code'    => $result['code'],
            ], 422);
        }

        $coupon  = $result['coupon'];
        $pricing = $this->coupons->calculateDiscount($coupon, (float) $course->price);

        $originalAmount = round($pricing['original_cents'] / 100, 2);
        $discountAmount = round($pricing['discount_cents'] / 100, 2);
        $finalAmount    = round($pricing['final_cents'] / 100, 2);
        $currency       = strtoupper($course->currency ?? 'EUR');

        return response()->json([
            'valid'  => true,
            'coupon' => [
                'code'           => $coupon->code,
                'name'           => $coupon->name,
                'discount_type'  => $coupon->discount_type,
                'discount_value' => (float) $coupon->discount_value,
            ],
            'pricing' => [
                'original_amount'    => $pricing['original_cents'],
                'discount_amount'    => $pricing['discount_cents'],
                'final_amount'       => $pricing['final_cents'],
                'currency'           => $currency,
                'formatted_original' => $this->formatEuro($originalAmount),
                'formatted_discount' => $this->formatEuro($discountAmount),
                'formatted_final'    => $this->formatEuro($finalAmount),
            ],
        ]);
    }

    private function formatEuro(float $amount): string
    {
        return '€ ' . number_format($amount, 2, ',', '.');
    }
}
