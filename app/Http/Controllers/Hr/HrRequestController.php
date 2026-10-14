<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Http\Resources\HrIncomingRequestResource;
use App\Models\HrRequest;
use App\Services\HrRequestAccessService;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrRequestController extends Controller
{
    public function __construct(private readonly HrRequestAccessService $access) {}

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
     * Incoming staffing requests for the HR workspace.
     *
     * Authorized by HrRequestAccessService: manage_team holders and platform-wide roles see
     * every department; a department leader sees only the departments they lead; everyone
     * else gets 403. (This endpoint used to be open to any signed-in user.)
     */
    public function incomingRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->access->canView($user)) {
            return $this->forbidden();
        }

        $request->validate([
            'status' => ['sometimes', 'nullable', 'in:pending,approved,rejected,in_progress'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'target_audience' => ['sometimes', 'nullable', 'in:hr,top_management'],
            'has_draft' => ['sometimes', 'nullable', 'in:yes,no'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $scoped = $this->access->scope(HrRequest::query(), $user);

        // Filters shared by the list and the statistics (the statistics ignore the status filter,
        // so the cards keep showing every status while one is selected).
        $base = (clone $scoped)
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', (int) $request->query('department_id')))
            ->when($request->filled('target_audience'), fn ($q) => $q->where('target_audience', $request->query('target_audience')))
            ->when($request->query('has_draft') === 'yes', fn ($q) => $q->whereHas('opportunityDraft'))
            ->when($request->query('has_draft') === 'no', fn ($q) => $q->whereDoesntHave('opportunityDraft'))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->query('date_to')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $request->query('q'));
                $q->where(function ($w) use ($term) {
                    $w->where('request_type', 'like', "%{$term}%")
                        ->orWhere('skills_and_specs', 'like', "%{$term}%")
                        ->orWhere('notes', 'like', "%{$term}%")
                        ->orWhereHas('department', fn ($d) => $d->where('name', 'like', "%{$term}%")->orWhere('name_ar', 'like', "%{$term}%"))
                        ->orWhereHas('creator', fn ($c) => $c->where('name', 'like', "%{$term}%"));
                });
            });

        $counts = (clone $base)->select('status', DB::raw('COUNT(*) as c'))->groupBy('status')->pluck('c', 'status');
        $withDrafts = (clone $base)->whereHas('opportunityDraft')->count();

        $page = (clone $base)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->with(['department:id,name,name_ar', 'creator:id,name', 'opportunityDraft' => fn ($q) => $q->select(['id', 'incoming_request_id', 'title', 'status'])->withCount('applications')])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => HrIncomingRequestResource::collection($page->getCollection())->resolve(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'stats' => [
                'total' => (int) $counts->sum(),
                'pending' => (int) ($counts['pending'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'with_drafts' => $withDrafts,
            ],
            'filters' => [
                'departments' => Department::query()->whereIn('id', (clone $scoped)->whereNotNull('department_id')->distinct()->pluck('department_id'))
                    ->orderBy('name_ar')->get(['id', 'name', 'name_ar'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name_ar ?: $d->name])->values(),
            ],
            'capabilities' => $this->access->capabilities($user),
        ]);
    }

    /** One request with its draft, for the details drawer. */
    public function show(Request $request, HrRequest $hrRequest): JsonResponse
    {
        $user = $request->user();
        if (! $this->access->canSee($user, $hrRequest)) {
            return $this->forbidden();
        }

        $hrRequest->load(['department:id,name,name_ar', 'creator:id,name', 'opportunityDraft' => fn ($q) => $q->withCount('applications')]);

        return response()->json([
            'success' => true,
            'data' => (new HrIncomingRequestResource($hrRequest))->resolve(),
            'capabilities' => $this->access->capabilities($user),
        ]);
    }

    /**
     * Update HR request status and feedback notes. Requires manage_team.
     */
    public function updateStatus(Request $request, HrRequest $hrRequest): JsonResponse
    {
        if (! $this->access->canManage($request->user())) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'status'      => ['required', 'in:pending,approved,rejected,in_progress'],
            'hr_feedback' => ['nullable', 'string', 'max:2000'],
        ], [
            'status.required' => 'حالة الطلب مطلوبة.',
            'status.in' => 'حالة الطلب غير صالحة.',
        ]);

        $hrRequest->update([
            'status'      => $validated['status'],
            'hr_feedback' => $validated['hr_feedback'] ?? $hrRequest->hr_feedback,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الطلب بنجاح',
            'data'    => (new HrIncomingRequestResource($hrRequest->load(['department:id,name,name_ar', 'creator:id,name', 'opportunityDraft' => fn ($q) => $q->withCount('applications')])))->resolve(),
        ]);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'ليس لديك صلاحية للوصول إلى الطلبات الواردة.'], 403);
    }
}
