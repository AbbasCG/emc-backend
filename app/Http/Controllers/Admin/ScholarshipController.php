<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScholarshipResource;
use App\Models\Scholarship;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScholarshipController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $scholarships = Scholarship::with(['user', 'approvedBy'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where(function ($q2) use ($request) {
                $q2->where('name', 'like', '%' . $request->search . '%')
                   ->orWhere('email', 'like', '%' . $request->search . '%');
            }))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => ScholarshipResource::collection($scholarships)->response()->getData(true)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'user_id'             => 'nullable|exists:users,id',
            'name'                => 'required|string|max:255',
            'email'               => 'required|email',
            'type'                => 'required|in:full,partial',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'amount'              => 'nullable|numeric|min:0',
            'reason'              => 'nullable|string',
        ]);

        $scholarship = Scholarship::create($data);

        ActivityLogger::log('scholarship.created', $scholarship, [], $request);

        return response()->json(['success' => true, 'data' => new ScholarshipResource($scholarship->load(['user']))], 201);
    }

    public function show(Scholarship $scholarship): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => true, 'data' => new ScholarshipResource($scholarship->load(['user', 'approvedBy']))]);
    }

    public function approve(Request $request, Scholarship $scholarship): \Illuminate\Http\JsonResponse
    {
        if ($scholarship->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Only pending scholarships can be approved.'], 422);
        }

        DB::transaction(function () use ($scholarship, $request) {
            $scholarship->update([
                'status'      => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
            ActivityLogger::log('scholarship.approved', $scholarship, [], $request);
        });

        return response()->json([
            'success' => true,
            'message' => 'Scholarship approved.',
            'data'    => new ScholarshipResource($scholarship->load(['user', 'approvedBy'])),
        ]);
    }

    public function reject(Request $request, Scholarship $scholarship): \Illuminate\Http\JsonResponse
    {
        if ($scholarship->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Only pending scholarships can be rejected.'], 422);
        }

        $scholarship->update([
            'status'      => 'rejected',
            'approved_by' => $request->user()->id,
        ]);

        ActivityLogger::log('scholarship.rejected', $scholarship, [], $request);

        return response()->json(['success' => true, 'message' => 'Scholarship rejected.']);
    }
}
