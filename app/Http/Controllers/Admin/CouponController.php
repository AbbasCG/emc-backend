<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Services\AuditLogService;
use App\Services\CouponValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    private const DISCOUNT_TYPES = ['percentage', 'fixed'];
    private const ELIGIBILITY_TYPES = [Coupon::ELIGIBILITY_ALL_PAID_COURSES, Coupon::ELIGIBILITY_SELECTED_COURSES];
    private const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $coupons = Coupon::query()
            ->withCount('courses')
            // Full list returned (not capped here) — a per-coupon LIMIT
            // inside a belongsToMany eager load applies across the whole
            // paginated batch combined, not per coupon, so it would
            // silently return wrong chips for coupons after the first.
            // The frontend truncates to "+N more" instead.
            ->with(['courses:courses.id,courses.title,courses.price,courses.status'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where(function ($q2) use ($request) {
                $q2->where('code', 'like', '%' . $request->search . '%')
                    ->orWhere('name', 'like', '%' . $request->search . '%');
            }))
            ->when($request->discount_type, fn ($q) => $q->where('discount_type', $request->discount_type))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => CouponResource::collection($coupons)->response()->getData(true)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $this->validatePayload($request, isUpdate: false);

        $data['code'] = CouponValidationService::normalizeCode($data['code'] ?? Str::random(8));
        $data['created_by'] = $request->user()?->id;

        $courseIds = $data['course_ids'] ?? [];
        $excludedCourseIds = $data['excluded_course_ids'] ?? [];
        unset($data['course_ids'], $data['excluded_course_ids']);

        $coupon = Coupon::create($data);
        $this->syncCourses($coupon, $courseIds, $excludedCourseIds);

        AuditLogService::logCreate($coupon, "أنشأ كوبون خصم: {$coupon->code}");

        return response()->json(['success' => true, 'data' => new CouponResource($coupon->fresh(['courses'])->loadCount('courses'))], 201);
    }

    public function show(Coupon $coupon): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => true, 'data' => new CouponResource($coupon->load('courses', 'excludedCourses', 'creator', 'updater')->loadCount('courses'))]);
    }

    public function update(Request $request, Coupon $coupon): \Illuminate\Http\JsonResponse
    {
        $old = $coupon->toArray();
        $data = $this->validatePayload($request, isUpdate: true, coupon: $coupon);

        if (isset($data['code'])) {
            $data['code'] = CouponValidationService::normalizeCode($data['code']);
        }
        $data['updated_by'] = $request->user()?->id;

        $courseIds = $data['course_ids'] ?? null;
        $excludedCourseIds = $data['excluded_course_ids'] ?? null;
        unset($data['course_ids'], $data['excluded_course_ids']);

        $coupon->update($data);
        if ($courseIds !== null || $excludedCourseIds !== null) {
            $this->syncCourses($coupon, $courseIds ?? [], $excludedCourseIds ?? []);
        }

        AuditLogService::logUpdate($coupon, $old, $coupon->fresh()->toArray(), "عدّل كوبون الخصم: {$coupon->code}");

        return response()->json(['success' => true, 'data' => new CouponResource($coupon->fresh(['courses'])->loadCount('courses'))]);
    }

    public function destroy(Coupon $coupon): \Illuminate\Http\JsonResponse
    {
        // Never delete a coupon with real redemption history — archive it
        // instead, so historical orders/payments keep a resolvable coupon_id.
        $hasUsage = CouponUsage::where('coupon_id', $coupon->id)->where('status', CouponUsage::STATUS_USED)->exists();
        if ($hasUsage) {
            $coupon->update(['status' => 'archived']);
            AuditLogService::logUpdate($coupon, ['status' => $coupon->getOriginal('status')], ['status' => 'archived'], "أرشف كوبون الخصم لوجود سجل استخدام: {$coupon->code}");

            return response()->json([
                'success' => true,
                'message' => 'لهذا الكوبون سجل استخدام فعلي — تم أرشفته بدلاً من حذفه.',
                'data'    => new CouponResource($coupon),
            ]);
        }

        AuditLogService::logDelete($coupon, "حذف كوبون الخصم: {$coupon->code}");
        $coupon->delete();

        return response()->json(['success' => true, 'message' => 'Coupon deleted.']);
    }

    /** Legacy coarse preview action — kept for backward compatibility with any existing caller; the real checkout path uses CouponValidationService via Student\CouponController. */
    public function validate(Request $request): \Illuminate\Http\JsonResponse
    {
        $data   = $request->validate(['code' => 'required|string', 'price' => 'required|numeric|min:0']);
        $coupon = Coupon::where('code', CouponValidationService::normalizeCode($data['code']))->first();

        if (! $coupon || ! $coupon->isValid()) {
            return response()->json(['success' => false, 'message' => 'Coupon is invalid or expired.'], 422);
        }

        $discount    = $coupon->calculateDiscount((float) $data['price']);
        $finalPrice  = max(0, (float) $data['price'] - $discount);

        return response()->json([
            'success'       => true,
            'data'          => [
                'coupon'        => new CouponResource($coupon),
                'original_price'=> $data['price'],
                'discount'      => $discount,
                'final_price'   => $finalPrice,
            ],
        ]);
    }

    private function validatePayload(Request $request, bool $isUpdate, ?Coupon $coupon = null): array
    {
        $codeRule = $isUpdate
            ? 'string|max:50|unique:coupons,code,' . $coupon->id
            : 'nullable|string|max:50|unique:coupons,code';

        return $request->validate([
            'code'                     => $codeRule,
            'name'                     => ($isUpdate ? '' : 'required|') . 'string|max:255',
            'description'              => 'nullable|string|max:2000',
            'discount_type'            => ($isUpdate ? '' : 'required|') . 'in:' . implode(',', self::DISCOUNT_TYPES),
            'discount_value'           => ($isUpdate ? '' : 'required|') . 'numeric|min:0',
            'maximum_discount_amount'  => 'nullable|numeric|min:0',
            'minimum_order_amount'     => 'nullable|numeric|min:0',
            'currency'                 => 'nullable|string|size:3',
            'max_uses'                 => 'nullable|integer|min:1',
            'usage_limit_per_user'     => 'nullable|integer|min:1',
            'first_order_only'         => 'nullable|boolean',
            'individual_use_only'      => 'nullable|boolean',
            'starts_at'                => 'nullable|date',
            'expires_at'               => 'nullable|date|after_or_equal:starts_at',
            'status'                   => 'in:' . implode(',', self::STATUSES),
            'eligibility_type'         => 'nullable|in:' . implode(',', self::ELIGIBILITY_TYPES),
            'course_ids'               => 'required_if:eligibility_type,' . Coupon::ELIGIBILITY_SELECTED_COURSES . '|array' . ($isUpdate ? '' : '|min:1'),
            'course_ids.*'             => 'integer|exists:courses,id|distinct',
            'excluded_course_ids'      => 'nullable|array',
            'excluded_course_ids.*'    => 'integer|exists:courses,id|distinct',
        ], [
            'course_ids.required_if' => 'يجب اختيار دورة واحدة على الأقل عند تحديد "دورات محددة".',
        ]);
    }

    private function syncCourses(Coupon $coupon, array $courseIds, array $excludedCourseIds): void
    {
        $sync = [];
        foreach (array_unique($courseIds) as $id) {
            $sync[$id] = ['excluded' => false];
        }
        foreach (array_unique($excludedCourseIds) as $id) {
            $sync[$id] = ['excluded' => true];
        }
        $coupon->belongsToMany(\App\Models\Course::class, 'coupon_courses')->sync($sync);
    }
}
