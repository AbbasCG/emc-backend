<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartnershipRequestResource;
use App\Models\PartnershipRequest;
use App\Services\AuditLogService;
use App\Services\PartnershipOnboardingService;
use DomainException;
use Illuminate\Http\Request;

class PartnershipRequestAdminController extends Controller
{
    public function __construct(private readonly PartnershipOnboardingService $onboarding)
    {
    }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $requests = PartnershipRequest::with(['reviewedBy', 'convertedPartner', 'convertedUser'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => PartnershipRequestResource::collection($requests)->response()->getData(true)]);
    }

    public function show(PartnershipRequest $partnershipRequest): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new PartnershipRequestResource($partnershipRequest->load(['reviewedBy', 'convertedPartner', 'convertedUser'])),
        ]);
    }

    public function updateStatus(Request $request, PartnershipRequest $partnershipRequest): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:new,under_review,approved,rejected,archived',
            'review_notes' => 'nullable|string|max:2000',
        ]);

        if ($data['status'] === 'approved') {
            try {
                $result = $this->onboarding->approve(
                    $partnershipRequest,
                    $request->user(),
                    $data['review_notes'] ?? null,
                );
            } catch (DomainException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'code' => 'partner_onboarding_conflict',
                ], 422);
            }

            AuditLogService::log('partnership_approved', 'PartnershipRequest', $partnershipRequest->id, null, null, [
                'partner_id' => $result['partner']->id,
                'user_id' => $result['user']->id,
                'account_created' => $result['account_created'],
            ], null, $request);

            return response()->json([
                'success' => true,
                'message' => $result['account_created']
                    ? 'تم اعتماد الشراكة وإنشاء حساب البوابة. يضبط الشريك كلمة مروره عبر صفحة نسيت كلمة المرور.'
                    : 'تم اعتماد الشراكة وربط حساب البوابة.',
                'data' => new PartnershipRequestResource(
                    $result['request']->load(['reviewedBy', 'convertedPartner', 'convertedUser'])
                ),
                'onboarding' => [
                    'partner_id' => $result['partner']->id,
                    'user_id' => $result['user']->id,
                    'account_created' => $result['account_created'],
                    'credential_setup' => $result['account_created'] ? 'password_reset' : 'existing_account',
                ],
            ]);
        }

        $partnershipRequest->update([
            'status'      => $data['status'],
            'reviewed_by' => $request->user()->id,
            'review_notes' => $data['review_notes'] ?? $partnershipRequest->review_notes,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الطلب.',
            'data'    => new PartnershipRequestResource($partnershipRequest->load(['reviewedBy', 'convertedPartner', 'convertedUser'])),
        ]);
    }
}
