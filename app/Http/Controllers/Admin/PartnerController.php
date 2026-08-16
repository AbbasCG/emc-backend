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
        $query = Partner::query()
            ->when($request->project_scope, fn ($q) => $q->where('project_scope', $request->project_scope))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->classification, fn ($q) => $q->where('classification', $request->classification))
            ->when($request->search, fn ($q) => $q->where('name', 'like', '%' . $request->search . '%'));

        // KPIs for the current scope
        $kpis = [
            'total' => (clone $query)->count(),
            'actual' => (clone $query)->where('classification', 'Actual Partner')->count(),
            'negotiation' => (clone $query)->where('classification', 'Under Negotiation')->count(),
            'rejected' => (clone $query)->where('classification', 'Rejected')->count(),
        ];

        $partners = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => PartnerResource::collection($partners)->response()->getData(true),
            'kpis' => $kpis
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'type'           => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'email'          => 'nullable|email',
            'phone'          => 'nullable|string|max:50',
            'website'        => 'nullable|url',
            'country'        => 'nullable|string|max:100',
            'city'           => 'nullable|string|max:100',
            'status'         => 'nullable|string|max:50',
            'notes'          => 'nullable|string',
            'project_scope'  => 'required|string|max:50',
            'classification' => 'nullable|string|max:50',
            'field_of_work'  => 'nullable|string',
            'contact_position' => 'nullable|string|max:150',
            'assigned_to'    => 'nullable|string|max:100',
            'first_contact_date' => 'nullable|date',
            'last_contact_date'  => 'nullable|date',
            'rejection_reason'   => 'nullable|string',
            'attachment_url'     => 'nullable|string',
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
            'type'           => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'email'          => 'nullable|email',
            'phone'          => 'nullable|string|max:50',
            'website'        => 'nullable|url',
            'country'        => 'nullable|string|max:100',
            'city'           => 'nullable|string|max:100',
            'status'         => 'nullable|string|max:50',
            'notes'          => 'nullable|string',
            'project_scope'  => 'string|max:50',
            'classification' => 'nullable|string|max:50',
            'field_of_work'  => 'nullable|string',
            'contact_position' => 'nullable|string|max:150',
            'assigned_to'    => 'nullable|string|max:100',
            'first_contact_date' => 'nullable|date',
            'last_contact_date'  => 'nullable|date',
            'rejection_reason'   => 'nullable|string',
            'attachment_url'     => 'nullable|string',
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
