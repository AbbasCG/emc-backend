<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;

/**
 * The single source of truth for coupon validity and discount calculation.
 * Every checkout-adjacent path (public validate endpoint, CheckoutController)
 * must go through this class — never re-implement these checks inline.
 *
 * All money math here is done in integer minor units (cents) to avoid
 * floating-point rounding drift; callers pass/receive euros as decimals and
 * this service is the only place that converts.
 */
class CouponValidationService
{
    /** Coupon codes are case-insensitive, trimmed, and stored uppercase — "emc20", " EMC20 ", "EMC20" all resolve to the same row. */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', '', $code) ?? ''));
    }

    /**
     * @return array{valid: bool, coupon: ?Coupon, message: ?string, code: ?string}
     */
    public function validateForCourse(string $code, User $user, Course $course): array
    {
        $normalized = self::normalizeCode($code);
        if ($normalized === '') {
            return $this->invalid('يرجى إدخال رمز الخصم.', 'coupon_code_required');
        }

        $coupon = Coupon::where('code', $normalized)->first();

        // Generic message either way — do not reveal whether a code exists
        // at all (enumeration hardening), and do not distinguish "expired"
        // from "never existed" to a public caller.
        if (! $coupon || $coupon->derivedStatus() !== 'active') {
            return $this->invalid('رمز الخصم غير صالح أو منتهي.', 'invalid_coupon');
        }

        if (! $course->isPaid()) {
            return $this->invalid('رمز الخصم لا ينطبق على الدورات المجانية.', 'course_not_eligible');
        }

        if (! $this->courseIsEligible($coupon, $course)) {
            return $this->invalid('رمز الخصم لا ينطبق على هذه الدورة.', 'course_not_eligible');
        }

        if ($coupon->minimum_order_amount !== null && (float) $course->price < (float) $coupon->minimum_order_amount) {
            return $this->invalid('السعر أقل من الحد الأدنى المطلوب لاستخدام هذا الرمز.', 'below_minimum_order');
        }

        if ($coupon->currency !== null) {
            $courseCurrency = strtoupper($course->currency ?? 'EUR');
            if (strtoupper($coupon->currency) !== $courseCurrency) {
                return $this->invalid('رمز الخصم غير صالح أو منتهي.', 'invalid_coupon');
            }
        }

        if ($coupon->max_uses !== null) {
            $usedCount = CouponUsage::where('coupon_id', $coupon->id)->where('status', CouponUsage::STATUS_USED)->count();
            if ($usedCount >= $coupon->max_uses) {
                return $this->invalid('تم استنفاد عدد مرات استخدام هذا الرمز.', 'usage_limit_reached');
            }
        }

        if ($coupon->usage_limit_per_user !== null) {
            $userUsedCount = CouponUsage::where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->where('status', CouponUsage::STATUS_USED)
                ->count();
            if ($userUsedCount >= $coupon->usage_limit_per_user) {
                return $this->invalid('لقد استخدمت هذا الرمز بالفعل الحد الأقصى المسموح به.', 'user_usage_limit_reached');
            }
        }

        if ($coupon->first_order_only) {
            $hasPriorPaidOrder = Order::where('user_id', $user->id)->where('status', 'paid')->exists();
            if ($hasPriorPaidOrder) {
                return $this->invalid('رمز الخصم مخصص للطلب الأول فقط.', 'first_order_only');
            }
        }

        return ['valid' => true, 'coupon' => $coupon, 'message' => null, 'code' => null];
    }

    private function courseIsEligible(Coupon $coupon, Course $course): bool
    {
        if ($coupon->excludedCourses()->where('courses.id', $course->id)->exists()) {
            return false;
        }

        if ($coupon->eligibility_type === Coupon::ELIGIBILITY_SELECTED_COURSES) {
            return $coupon->courses()->where('courses.id', $course->id)->exists();
        }

        return true;
    }

    private function invalid(string $message, string $code): array
    {
        return ['valid' => false, 'coupon' => null, 'message' => $message, 'code' => $code];
    }

    /**
     * @return array{original_cents: int, discount_cents: int, final_cents: int}
     */
    public function calculateDiscount(Coupon $coupon, float $originalAmountEuros): array
    {
        $originalCents = (int) round($originalAmountEuros * 100);

        if ($coupon->discount_type === 'percentage') {
            $discountCents = (int) floor($originalCents * ((float) $coupon->discount_value) / 100);
        } else {
            $discountCents = (int) round(((float) $coupon->discount_value) * 100);
        }

        if ($coupon->maximum_discount_amount !== null) {
            $discountCents = min($discountCents, (int) round((float) $coupon->maximum_discount_amount * 100));
        }

        // Never exceed the original amount, never go negative.
        $discountCents = max(0, min($discountCents, $originalCents));
        $finalCents    = max(0, $originalCents - $discountCents);

        return [
            'original_cents' => $originalCents,
            'discount_cents' => $discountCents,
            'final_cents'    => $finalCents,
        ];
    }
}
