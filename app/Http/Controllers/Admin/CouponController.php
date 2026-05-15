<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $coupons = Coupon::query()
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where('code', 'like', '%' . $request->search . '%'))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => CouponResource::collection($coupons)->response()->getData(true)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'code'             => 'nullable|string|unique:coupons,code',
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'discount_type'    => 'required|in:percentage,fixed',
            'discount_value'   => 'required|numeric|min:0',
            'max_uses'         => 'nullable|integer|min:1',
            'starts_at'        => 'nullable|date',
            'expires_at'       => 'nullable|date|after_or_equal:starts_at',
            'status'           => 'in:active,inactive',
            'applies_to_type'  => 'nullable|string',
            'applies_to_id'    => 'nullable|integer',
        ]);

        $data['code'] = strtoupper($data['code'] ?? Str::random(8));

        $coupon = Coupon::create($data);

        return response()->json(['success' => true, 'data' => new CouponResource($coupon)], 201);
    }

    public function show(Coupon $coupon): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => true, 'data' => new CouponResource($coupon)]);
    }

    public function update(Request $request, Coupon $coupon): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'code'           => 'string|unique:coupons,code,' . $coupon->id,
            'name'           => 'string|max:255',
            'description'    => 'nullable|string',
            'discount_type'  => 'in:percentage,fixed',
            'discount_value' => 'numeric|min:0',
            'max_uses'       => 'nullable|integer|min:1',
            'starts_at'      => 'nullable|date',
            'expires_at'     => 'nullable|date',
            'status'         => 'in:active,inactive',
        ]);

        $coupon->update($data);

        return response()->json(['success' => true, 'data' => new CouponResource($coupon)]);
    }

    public function destroy(Coupon $coupon): \Illuminate\Http\JsonResponse
    {
        $coupon->delete();

        return response()->json(['success' => true, 'message' => 'Coupon deleted.']);
    }

    public function validate(Request $request): \Illuminate\Http\JsonResponse
    {
        $data   = $request->validate(['code' => 'required|string', 'price' => 'required|numeric|min:0']);
        $coupon = Coupon::where('code', strtoupper($data['code']))->first();

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
}
