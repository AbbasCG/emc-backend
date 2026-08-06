<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartnershipRequestRequest;
use App\Models\PartnershipRequest;
use Illuminate\Http\JsonResponse;

class PartnershipRequestController extends Controller
{
    public function store(StorePartnershipRequestRequest $request): JsonResponse
    {
        $partnershipRequest = PartnershipRequest::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب الشراكة بنجاح. سيتواصل معك فريق EMC بعد مراجعة الطلب.',
            'data'    => ['id' => $partnershipRequest->id],
        ], 201);
    }
}
