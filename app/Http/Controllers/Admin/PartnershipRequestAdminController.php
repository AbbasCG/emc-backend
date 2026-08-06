<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartnershipRequestResource;
use App\Models\PartnershipRequest;
use Illuminate\Http\Request;

class PartnershipRequestAdminController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $requests = PartnershipRequest::with('reviewedBy')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => PartnershipRequestResource::collection($requests)->response()->getData(true)]);
    }

    public function show(PartnershipRequest $partnershipRequest): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new PartnershipRequestResource($partnershipRequest->load('reviewedBy')),
        ]);
    }

    public function updateStatus(Request $request, PartnershipRequest $partnershipRequest): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:new,under_review,approved,rejected,archived',
        ]);

        $partnershipRequest->update([
            'status'      => $data['status'],
            'reviewed_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated.',
            'data'    => new PartnershipRequestResource($partnershipRequest->load('reviewedBy')),
        ]);
    }
}
