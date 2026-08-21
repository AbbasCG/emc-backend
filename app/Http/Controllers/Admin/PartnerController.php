<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartnerResource;
use App\Models\Partner;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $base = Partner::query()
            ->when($request->project_scope, fn ($q) => $q->where('project_scope', $request->project_scope));

        $kpis = [
            'total' => (clone $base)->count(),
            'actual' => (clone $base)->where('classification', 'Actual Partner')->count(),
            'negotiation' => (clone $base)->whereIn('classification', ['Potential', 'Under Negotiation'])->count(),
            'rejected' => (clone $base)->where('classification', 'Rejected')->count(),
        ];

        $partners = $base
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where('name', 'like', '%' . $request->search . '%'))
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => PartnerResource::collection($partners)->response()->getData(true),
            'kpis' => $kpis,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'type'           => 'in:university,school,company,institution,donor,community',
            'partnership_type' => 'nullable|string|max:50',
            'project_scope' => 'required|in:EMC_GENERAL,HACKATHON,BOOTCAMP',
            'classification' => 'nullable|in:Actual Partner,Potential,Under Negotiation,Rejected',
            'field_of_work' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_position' => 'nullable|string|max:255',
            'email'          => 'nullable|email',
            'phone'          => 'nullable|string|max:50',
            'website'        => 'nullable|url',
            'country'        => 'nullable|string|max:100',
            'city'           => 'nullable|string|max:100',
            'assigned_to' => 'nullable|string|max:255',
            'first_contact_date' => 'nullable|date',
            'last_contact_date' => 'nullable|date|after_or_equal:first_contact_date',
            'rejection_reason' => 'nullable|string|max:2000',
            'attachment_url' => 'nullable|url|max:2048',
            'status'         => 'in:active,inactive,archived',
            'notes'          => 'nullable|string',
        ]);

        $partner = Partner::create($data);

        return response()->json([
            'success' => true,
            'data'    => new PartnerResource($partner),
        ], 201);
    }

    public function show(Partner $partner): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new PartnerResource($partner),
        ]);
    }

    public function update(Request $request, Partner $partner): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'           => 'string|max:255',
            'type'           => 'in:university,school,company,institution,donor,community',
            'partnership_type' => 'nullable|string|max:50',
            'project_scope' => 'sometimes|in:EMC_GENERAL,HACKATHON,BOOTCAMP',
            'classification' => 'nullable|in:Actual Partner,Potential,Under Negotiation,Rejected',
            'field_of_work' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'contact_position' => 'nullable|string|max:255',
            'email'          => 'nullable|email',
            'phone'          => 'nullable|string|max:50',
            'website'        => 'nullable|url',
            'country'        => 'nullable|string|max:100',
            'city'           => 'nullable|string|max:100',
            'assigned_to' => 'nullable|string|max:255',
            'first_contact_date' => 'nullable|date',
            'last_contact_date' => 'nullable|date|after_or_equal:first_contact_date',
            'rejection_reason' => 'nullable|string|max:2000',
            'attachment_url' => 'nullable|url|max:2048',
            'status'         => 'in:active,inactive,archived',
            'notes'          => 'nullable|string',
        ]);

        $partner->update($data);

        return response()->json([
            'success' => true,
            'data'    => new PartnerResource($partner),
        ]);
    }

    public function destroy(Partner $partner): \Illuminate\Http\JsonResponse
    {
        $partner->delete();

        return response()->json(['success' => true, 'message' => 'Partner deleted.']);
    }
}
