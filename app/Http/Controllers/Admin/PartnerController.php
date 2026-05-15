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
        $partners = Partner::query()
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where('name', 'like', '%' . $request->search . '%'))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => PartnerResource::collection($partners)->response()->getData(true)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'type'           => 'in:university,school,company,institution,donor,community',
            'contact_person' => 'nullable|string|max:255',
            'email'          => 'nullable|email',
            'phone'          => 'nullable|string|max:50',
            'website'        => 'nullable|url',
            'country'        => 'nullable|string|max:100',
            'city'           => 'nullable|string|max:100',
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
            'contact_person' => 'nullable|string|max:255',
            'email'          => 'nullable|email',
            'phone'          => 'nullable|string|max:50',
            'website'        => 'nullable|url',
            'country'        => 'nullable|string|max:100',
            'city'           => 'nullable|string|max:100',
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
