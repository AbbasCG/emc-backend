<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VolunteerProfileResource;
use App\Models\VolunteerProfile;
use Illuminate\Http\Request;

class VolunteerController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $volunteers = VolunteerProfile::with(['user', 'department', 'section'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => VolunteerProfileResource::collection($volunteers)->response()->getData(true)]);
    }

    public function show(VolunteerProfile $volunteer): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new VolunteerProfileResource($volunteer->load(['user', 'department', 'section'])),
        ]);
    }

    public function updateStatus(Request $request, VolunteerProfile $volunteer): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:applicant,under_review,accepted,active,partially_available,inactive,withdrawn',
        ]);

        $volunteer->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Volunteer status updated.',
            'data'    => new VolunteerProfileResource($volunteer->load(['user', 'department'])),
        ]);
    }

    public function assignDepartment(Request $request, VolunteerProfile $volunteer): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'section_id'    => 'nullable|exists:department_sections,id',
        ]);

        $volunteer->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Volunteer department assigned.',
            'data'    => new VolunteerProfileResource($volunteer->load(['user', 'department', 'section'])),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'user_id'           => 'required|exists:users,id|unique:volunteer_profiles,user_id',
            'department_id'     => 'nullable|exists:departments,id',
            'section_id'        => 'nullable|exists:department_sections,id',
            'status'            => 'in:applicant,under_review,accepted,active,partially_available,inactive,withdrawn',
            'skills'            => 'nullable|array',
            'availability'      => 'nullable|string',
            'onboarding_status' => 'in:pending,in_progress,completed',
            'notes'             => 'nullable|string',
        ]);

        $volunteer = VolunteerProfile::create($data);

        return response()->json([
            'success' => true,
            'data'    => new VolunteerProfileResource($volunteer->load(['user', 'department'])),
        ], 201);
    }

    public function update(Request $request, VolunteerProfile $volunteer): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'department_id'     => 'nullable|exists:departments,id',
            'section_id'        => 'nullable|exists:department_sections,id',
            'status'            => 'in:applicant,under_review,accepted,active,partially_available,inactive,withdrawn',
            'skills'            => 'nullable|array',
            'availability'      => 'nullable|string',
            'hours_logged'      => 'numeric|min:0',
            'onboarding_status' => 'in:pending,in_progress,completed',
            'notes'             => 'nullable|string',
        ]);

        $volunteer->update($data);

        return response()->json([
            'success' => true,
            'data'    => new VolunteerProfileResource($volunteer->load(['user', 'department', 'section'])),
        ]);
    }
}
