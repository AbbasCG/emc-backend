<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HrRequest;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrRequestController extends Controller
{
    /**
     * Submit a new staffing/HR request from any department.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id'    => ['nullable', 'exists:departments,id'],
            'request_type'     => ['nullable', 'string', 'max:100'],
            'required_count'   => ['required', 'integer', 'min:1'],
            'skills_and_specs' => ['required', 'string'],
            'notes'            => ['nullable', 'string'],
        ]);

        $user = $request->user();

        // 1. Resolve department_id if omitted
        $deptId = $validated['department_id'] ?? null;
        if (!$deptId) {
            $teamProfile = $user->teamProfile;
            $deptId = $teamProfile?->department_id;
        }

        if (!$deptId) {
            $ledDept = Department::where('leader_id', $user->id)->first();
            $deptId = $ledDept?->id;
        }

        if (!$deptId) {
            $firstDept = Department::where('is_active', true)->first();
            $deptId = $firstDept?->id;
        }

        // 2. Determine target audience (HR or Top Management)
        $dept = Department::find($deptId);
        $isHrDepartment = false;

        if ($dept) {
            $slug = strtolower($dept->slug ?? '');
            $name = strtolower($dept->name ?? '' . $dept->name_ar ?? '');
            if (str_contains($slug, 'hr') || str_contains($name, 'موارد') || str_contains($name, 'hr')) {
                $isHrDepartment = true;
            }
        }

        if ($user->role === 'hr_manager' || $user->role === 'hr') {
            $isHrDepartment = true;
        }

        $targetAudience = $isHrDepartment ? 'top_management' : 'hr';

        // 3. Create request
        $hrRequest = HrRequest::create([
            'department_id'    => $deptId,
            'request_type'     => $validated['request_type'] ?? 'احتياج وظيفي / نقص أعضاء',
            'required_count'   => $validated['required_count'],
            'skills_and_specs' => $validated['skills_and_specs'],
            'notes'            => $validated['notes'] ?? null,
            'status'           => 'pending',
            'target_audience'  => $targetAudience,
            'created_by'       => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => $isHrDepartment 
                ? 'تم تقديم طلب احتیاج قسم HR بنجاح وتوجيهه للإدارة العليا' 
                : 'تم تقديم طلب الاحتياج بنجاح وإرساله لإدارة الموارد البشرية',
            'data'    => $hrRequest->load(['department', 'creator']),
        ], 201);
    }

    /**
     * Get requests submitted by the current user or their department.
     */
    public function myRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        $deptId = $user->teamProfile?->department_id;

        $query = HrRequest::with(['department', 'creator']);

        if ($user->role === 'super_admin' || $user->role === 'admin') {
            // Admins see all
        } else {
            $query->where(function ($q) use ($user, $deptId) {
                $q->where('created_by', $user->id);
                if ($deptId) {
                    $q->orWhere('department_id', $deptId);
                }
            });
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data'    => $requests,
        ]);
    }

    /**
     * Get incoming requests for HR or Top Management view.
     */
    public function incomingRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = HrRequest::with(['department', 'creator']);

        $audienceFilter = $request->query('target_audience');
        if ($audienceFilter) {
            $query->where('target_audience', $audienceFilter);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data'    => $requests,
        ]);
    }

    /**
     * Update HR request status and feedback notes.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $hrRequest = HrRequest::findOrFail($id);

        $validated = $request->validate([
            'status'      => ['required', 'in:pending,approved,rejected,in_progress'],
            'hr_feedback' => ['nullable', 'string'],
        ]);

        $hrRequest->update([
            'status'      => $validated['status'],
            'hr_feedback' => $validated['hr_feedback'] ?? $hrRequest->hr_feedback,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الطلب بنجاح',
            'data'    => $hrRequest->load(['department', 'creator']),
        ]);
    }
}
