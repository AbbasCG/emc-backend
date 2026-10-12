<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VolunteerHrProfileResource;
use App\Models\Department;
use App\Models\VolunteerHrProfile;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\VolunteerApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrVolunteerProfileController extends Controller
{
    public function __construct(private readonly VolunteerApprovalService $approval)
    {
    }

    // GET /api/hr/volunteers
    public function index(Request $request): JsonResponse
    {
        $base = VolunteerHrProfile::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($sub) use ($term) {
                    $sub->where('full_name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('job_title', 'like', "%{$term}%")
                        ->orWhere('country', 'like', "%{$term}%")
                        ->orWhere('city', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->filled('job_title'), fn ($q) => $q->where('job_title', 'like', '%' . $request->job_title . '%'))
            ->when($request->filled('country'), fn ($q) => $q->where('country', $request->country))
            ->when($request->filled('city'), fn ($q) => $q->where('city', $request->city))
            ->when($request->filled('join_from'), fn ($q) => $q->whereDate('join_date', '>=', $request->join_from))
            ->when($request->filled('join_to'), fn ($q) => $q->whereDate('join_date', '<=', $request->join_to))
            ->when($request->filled('submitted_from'), fn ($q) => $q->whereDate('submitted_at', '>=', $request->submitted_from))
            ->when($request->filled('submitted_to'), fn ($q) => $q->whereDate('submitted_at', '<=', $request->submitted_to))
            ->when($request->filled('has_cv'), fn ($q) => $request->has_cv === 'yes' ? $q->whereNotNull('cv_path') : $q->whereNull('cv_path'));

        $statusCounts = (clone $base)->select('status', DB::raw('COUNT(*) as cnt'))->groupBy('status')->pluck('cnt', 'status');

        $statistics = [
            'total'         => $statusCounts->sum(),
            'submitted'     => (int) ($statusCounts['submitted'] ?? 0),
            'under_review'  => (int) ($statusCounts['under_review'] ?? 0),
            'approved'      => (int) ($statusCounts['approved'] ?? 0),
            'rejected'      => (int) ($statusCounts['rejected'] ?? 0),
            'departments_count' => (clone $base)->whereNotNull('department_id')->distinct('department_id')->count('department_id'),
            'joined_this_month' => (clone $base)->where('status', 'approved')
                ->whereMonth('join_date', now()->month)->whereYear('join_date', now()->year)->count(),
        ];

        $perPage = min((int) $request->query('per_page', 20), 100);
        $items = (clone $base)
            ->with(['department', 'reviewer', 'approver'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        $paginated = VolunteerHrProfileResource::collection($items)->response()->getData(true);

        return response()->json([
            'success'    => true,
            'data'       => $paginated['data'] ?? [],
            'meta'       => $paginated['meta'] ?? [],
            'links'      => $paginated['links'] ?? [],
            'statistics' => $statistics,
        ]);
    }

    // GET /api/hr/volunteers/filter-options
    public function filterOptions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'departments' => Department::active()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name']),
                'statuses'    => VolunteerHrProfile::STATUSES,
            ],
        ]);
    }

    // GET /api/hr/volunteers/{volunteerHrProfile}
    public function show(VolunteerHrProfile $volunteerHrProfile): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new VolunteerHrProfileResource($volunteerHrProfile->load(['department', 'reviewer', 'approver', 'user:id,name,email'])),
        ]);
    }

    // PATCH /api/hr/volunteers/{volunteerHrProfile}/review
    public function review(Request $request, VolunteerHrProfile $volunteerHrProfile): JsonResponse
    {
        if (! in_array($volunteerHrProfile->status, ['submitted', 'under_review'], true)) {
            return response()->json(['success' => false, 'message' => 'لا يمكن بدء المراجعة في الحالة الحالية.'], 422);
        }

        $volunteerHrProfile->update(['status' => 'under_review']);

        return response()->json([
            'success' => true,
            'data'    => new VolunteerHrProfileResource($volunteerHrProfile->fresh()),
        ]);
    }

    // POST /api/hr/volunteers/{volunteerHrProfile}/approve
    public function approve(Request $request, VolunteerHrProfile $volunteerHrProfile): JsonResponse
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        if ($volunteerHrProfile->status === 'approved') {
            return response()->json([
                'success' => true,
                'message' => 'تم قبول المتطوع وإضافته إلى الفريق',
                'data'    => new VolunteerHrProfileResource($volunteerHrProfile->fresh()),
            ]);
        }

        try {
            $result = $this->approval->approve($volunteerHrProfile, $request->user(), $data['department_id'] ?? null);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'تعذر إكمال عملية القبول، لم يتم إجراء أي تغيير',
                'error'   => $e->getMessage(),
            ], 422);
        }

        NotificationService::volunteerHrProfileApproved($volunteerHrProfile->id, $volunteerHrProfile->full_name, $volunteerHrProfile->user_id);

        return response()->json([
            'success' => true,
            'message' => 'تم قبول المتطوع وإضافته إلى الفريق',
            'data'    => new VolunteerHrProfileResource($result['profile']->load(['department', 'reviewer', 'approver'])),
        ]);
    }

    // POST /api/hr/volunteers/{volunteerHrProfile}/reject
    public function reject(Request $request, VolunteerHrProfile $volunteerHrProfile): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        $profile = $this->approval->reject($volunteerHrProfile, $request->user(), $data['reason']);

        NotificationService::volunteerHrProfileRejected($profile->id, $profile->full_name, $profile->user_id, $data['reason']);

        return response()->json([
            'success' => true,
            'message' => 'تم رفض الطلب.',
            'data'    => new VolunteerHrProfileResource($profile),
        ]);
    }

    // downloadCv() removed — privacy policy: staff may preview a volunteer's CV
    // in-app but never download the raw file. previewCv() below is unaffected.

    // GET /api/hr/volunteers/{volunteerHrProfile}/cv/preview — inline bytes for
    // the frontend's blob-based preview modal (same pattern as
    // AdminAmbassadorApplicationFileController::preview).
    public function previewCv(VolunteerHrProfile $volunteerHrProfile): StreamedResponse|JsonResponse
    {
        if (! $volunteerHrProfile->cv_path || ! Storage::disk('private')->exists($volunteerHrProfile->cv_path)) {
            return response()->json(['success' => false, 'message' => 'CV file not found.'], 404);
        }

        AuditLogService::logAction('PREVIEW', $volunteerHrProfile, 'CV previewed: ' . ($volunteerHrProfile->cv_original_name ?? basename($volunteerHrProfile->cv_path)));

        return Storage::disk('private')->response(
            $volunteerHrProfile->cv_path,
            $volunteerHrProfile->cv_original_name ?? basename($volunteerHrProfile->cv_path),
        );
    }
}
