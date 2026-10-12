<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\FinancialRequest;
use App\Models\FinancialRequestAttachment;
use App\Models\FinancialRequestWorkflowStep;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FinancialRequestController extends Controller
{
    // ── Authorization helpers ─────────────────────────────────────────────────

    /**
     * Returns all active team profiles for the authenticated user.
     * Any user with at least one profile is a department member.
     *
     * @return TeamProfile[]|\Illuminate\Database\Eloquent\Collection
     */
    private function myTeamProfiles()
    {
        return TeamProfile::where('user_id', Auth::id())
            ->where('is_active', true)
            ->with('department')
            ->get();
    }

    /**
     * Assert user has at least one active team_profile (or is super_admin).
     * Returns the primary profile to use for the request.
     * If $departmentId is given (super_admin flow), returns a synthetic profile-like array.
     */
    private function assertTeamMember(?int $departmentId = null): array
    {
        $user = Auth::user();

        if ($this->isSuperAdmin()) {
            if ($departmentId) {
                $dept = Department::findOrFail($departmentId);
                return [
                    'department_id' => $dept->id,
                    'is_leader'     => true,
                ];
            }
            // Super admin must supply department_id
            abort(422, 'يرجى تحديد القسم للطلب.');
        }

        $profiles = $this->myTeamProfiles()->where('is_leader', true);
        abort_if($profiles->isEmpty(), 403, 'يجب أن تكون قائداً لقسم لإنشاء طلب مالي.');

        if ($departmentId) {
            $profile = $profiles->firstWhere('department_id', $departmentId);
            abort_unless($profile, 403, 'لا تنتمي إلى هذا القسم أو لست قائده.');
        } else {
            $profile = $profiles->first();
        }

        return [
            'department_id' => $profile->department_id,
            'is_leader'     => true,
        ];
    }

    private function isFinanceActor(): bool
    {
        return Auth::user()->hasRole('finance_manager', 'admin', 'super_admin', 'tech_admin');
    }

    private function isExecutiveActor(): bool
    {
        return Auth::user()->hasRole('executive_admin', 'admin', 'super_admin', 'tech_admin');
    }

    private function isSuperAdmin(): bool
    {
        return Auth::user()->hasRole('super_admin', 'tech_admin', 'admin');
    }

    /** Record a workflow step and update request status */
    private function transition(
        FinancialRequest $req,
        string $action,
        string $toStatus,
        ?string $note = null
    ): void {
        $user       = Auth::user();
        $fromStatus = $req->status;

        FinancialRequestWorkflowStep::create([
            'financial_request_id' => $req->id,
            'actor_id'             => $user->id,
            'actor_role'           => $user->role,
            'action'               => $action,
            'from_status'          => $fromStatus,
            'to_status'            => $toStatus,
            'note'                 => $note,
        ]);

        $req->update(['status' => $toStatus]);
        AuditLogService::logAction($action, $req, "[$fromStatus → $toStatus] {$req->title}");
    }

    private function financeUserIds(): array
    {
        return User::whereIn('role', ['finance_manager', 'admin', 'super_admin'])->pluck('id')->all();
    }

    private function executiveUserIds(): array
    {
        return User::where('role', 'executive_admin')->orWhereIn('role', ['admin', 'super_admin'])->pluck('id')->all();
    }

    // ── MY CONTEXT ────────────────────────────────────────────────────────────
    // Returns user's team profiles + departments for the create form

    public function myContext(): JsonResponse
    {
        $user     = Auth::user();
        $profiles = $this->myTeamProfiles();

        // For create eligibility, only leader profiles count
        $leaderProfiles = $this->isSuperAdmin() ? $profiles : $profiles->where('is_leader', true);

        $departments = $this->isSuperAdmin()
            ? Department::active()->where('is_active', true)->get()->map(fn($d) => [
                'id'        => $d->id,
                'name'      => $d->name_ar ?? $d->name ?? $d->name_en ?? '',
                'is_leader' => true,
              ])->values()
            : $leaderProfiles->map(fn($p) => [
                'id'        => $p->department_id,
                'name'      => $p->department?->name_ar ?? $p->department?->name ?? $p->department?->name_en ?? "قسم #{$p->department_id}",
                'is_leader' => true,
              ])->unique('id')->values();

        return response()->json([
            'data' => [
                'is_super_admin'     => $this->isSuperAdmin(),
                'can_create'         => $this->isSuperAdmin() || $leaderProfiles->isNotEmpty(),
                'departments'        => $departments,
                'primary_department' => $departments->first(),
            ],
        ]);
    }

    // ── INDEX ─────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user  = Auth::user();
        $query = FinancialRequest::with(['requester', 'department', 'financeReviewer', 'executiveReviewer'])
            ->orderByDesc('created_at');

        if ($this->isSuperAdmin()) {
            // super admin sees all
        } elseif ($this->isFinanceActor()) {
            // Finance sees anything that left draft state
            $query->whereNotIn('status', ['draft', 'cancelled']);
        } elseif ($this->isExecutiveActor()) {
            $query->whereIn('status', ['executive_review', 'approved', 'rejected', 'finance_approved']);
        } else {
            // Department member: own requests + own department's requests
            $profileDepartmentIds = TeamProfile::where('user_id', $user->id)
                ->where('is_active', true)
                ->pluck('department_id')
                ->unique()
                ->values()
                ->all();

            $query->where(function ($q) use ($user, $profileDepartmentIds) {
                $q->where('requester_id', $user->id);
                if (!empty($profileDepartmentIds)) {
                    $q->orWhereIn('department_id', $profileDepartmentIds);
                }
            });
        }

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('request_type')) {
            $query->where('request_type', $request->request_type);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        $perPage = min((int) ($request->per_page ?? 20), 50);
        $result  = $query->paginate($perPage);

        return response()->json(['data' => $result]);
    }

    // ── SHOW ──────────────────────────────────────────────────────────────────

    public function show(FinancialRequest $financialRequest): JsonResponse
    {
        $user = Auth::user();
        $this->authorizeView($user, $financialRequest);

        $financialRequest->load([
            'requester', 'department', 'financeReviewer',
            'executiveReviewer', 'attachments.uploader', 'workflowSteps.actor',
        ]);

        return response()->json(['data' => $this->format($financialRequest)]);
    }

    // ── STORE ─────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'description'    => 'nullable|string|max:5000',
            'request_type'   => ['required', Rule::in(FinancialRequest::TYPES)],
            'amount'         => 'required|numeric|min:0.01',
            'currency'       => 'nullable|string|max:10',
            'needed_by_date' => 'nullable|date|after_or_equal:today',
            'priority'       => ['nullable', Rule::in(FinancialRequest::PRIORITIES)],
            'department_id'  => 'nullable|integer|exists:departments,id',
        ]);

        $memberInfo = $this->assertTeamMember($data['department_id'] ?? null);

        $req = DB::transaction(function () use ($data, $memberInfo) {
            $req = FinancialRequest::create([
                'title'         => $data['title'],
                'description'   => $data['description'] ?? null,
                'request_type'  => $data['request_type'],
                'amount'        => $data['amount'],
                'currency'      => $data['currency'] ?? 'SAR',
                'needed_by_date'=> $data['needed_by_date'] ?? null,
                'priority'      => $data['priority'] ?? 'normal',
                'requester_id'  => Auth::id(),
                'department_id' => $memberInfo['department_id'],
                'status'        => 'draft',
            ]);
            AuditLogService::logCreate($req, "طلب مالي جديد: {$req->title}");

            // Auto-submit: transition directly to finance_review so Finance Manager sees it immediately
            $this->transition($req, 'SUBMIT', 'finance_review');
            $req->update(['submitted_at' => now()]);

            NotificationService::send(
                $this->financeUserIds(),
                'financial_request_submitted',
                'طلب مالي جديد',
                "طلب مالي جديد بانتظار مراجعتك: {$req->title}",
                ['entity_id' => $req->id, 'url' => "/dashboard/finance/financial-requests/{$req->id}"]
            );

            return $req;
        });

        return response()->json(['data' => $this->format($req->fresh(['requester', 'department', 'workflowSteps.actor']))], 201);
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────

    public function update(Request $request, FinancialRequest $financialRequest): JsonResponse
    {
        $user = Auth::user();
        if (!$this->isSuperAdmin()) {
            abort_unless($financialRequest->requester_id === $user->id, 403);
            abort_unless(in_array($financialRequest->status, ['draft', 'returned'], true), 422, 'لا يمكن تعديل الطلب بعد إرساله.');
        }

        $data = $request->validate([
            'title'          => 'sometimes|string|max:255',
            'description'    => 'nullable|string|max:5000',
            'request_type'   => ['sometimes', Rule::in(FinancialRequest::TYPES)],
            'amount'         => 'sometimes|numeric|min:0.01',
            'currency'       => 'nullable|string|max:10',
            'needed_by_date' => 'nullable|date',
            'priority'       => ['nullable', Rule::in(FinancialRequest::PRIORITIES)],
        ]);

        $financialRequest->update($data);
        AuditLogService::logUpdate($financialRequest, [], $data);

        return response()->json(['data' => $this->format($financialRequest->fresh(['requester', 'department']))]);
    }

    // ── SUBMIT ────────────────────────────────────────────────────────────────

    public function submit(FinancialRequest $financialRequest): JsonResponse
    {
        $user = Auth::user();
        abort_unless(
            $financialRequest->requester_id === $user->id || $this->isSuperAdmin(),
            403
        );
        abort_unless(
            in_array($financialRequest->status, ['draft', 'returned'], true),
            422, 'لا يمكن إرسال هذا الطلب بوضعه الحالي.'
        );

        DB::transaction(function () use ($financialRequest) {
            $this->transition($financialRequest, 'SUBMIT', 'finance_review');
            $financialRequest->update(['submitted_at' => now()]);

            NotificationService::send(
                $this->financeUserIds(),
                'financial_request_submitted',
                'طلب مالي جديد',
                "طلب مالي جديد بانتظار مراجعتك: {$financialRequest->title}",
                ['entity_id' => $financialRequest->id, 'url' => "/dashboard/finance/financial-requests/{$financialRequest->id}"]
            );
        });

        return response()->json(['data' => $this->format($financialRequest->fresh(['requester', 'department', 'workflowSteps.actor']))]);
    }

    // ── FINANCE APPROVE ───────────────────────────────────────────────────────

    public function financeApprove(Request $request, FinancialRequest $financialRequest): JsonResponse
    {
        abort_unless($this->isFinanceActor(), 403);
        abort_unless($financialRequest->status === 'finance_review', 422, 'الطلب ليس في مرحلة المراجعة المالية.');

        $data = $request->validate(['note' => 'nullable|string|max:2000']);

        DB::transaction(function () use ($financialRequest, $data) {
            $this->transition($financialRequest, 'FINANCE_APPROVE', 'finance_approved', $data['note'] ?? null);
            $financialRequest->update([
                'finance_reviewer_id' => Auth::id(),
                'finance_reviewed_at' => now(),
                'finance_note'        => $data['note'] ?? null,
            ]);
            $this->transition($financialRequest, 'ESCALATE', 'executive_review');

            NotificationService::send(
                $this->executiveUserIds(),
                'financial_request_executive_review',
                'طلب مالي بانتظار اعتمادك',
                "تمت الموافقة المالية على: {$financialRequest->title}، بانتظار اعتمادك.",
                ['entity_id' => $financialRequest->id, 'url' => "/dashboard/executive/financial-requests/{$financialRequest->id}"]
            );
        });

        return response()->json(['data' => $this->format($financialRequest->fresh(['requester', 'department', 'financeReviewer', 'workflowSteps.actor']))]);
    }

    // ── FINANCE REJECT ────────────────────────────────────────────────────────

    public function financeReject(Request $request, FinancialRequest $financialRequest): JsonResponse
    {
        abort_unless($this->isFinanceActor(), 403);
        abort_unless($financialRequest->status === 'finance_review', 422, 'الطلب ليس في مرحلة المراجعة المالية.');

        $data = $request->validate(['reason' => 'required|string|max:2000']);

        DB::transaction(function () use ($financialRequest, $data) {
            $this->transition($financialRequest, 'FINANCE_REJECT', 'finance_rejected', $data['reason']);
            $financialRequest->update([
                'finance_reviewer_id' => Auth::id(),
                'finance_reviewed_at' => now(),
                'rejection_reason'    => $data['reason'],
            ]);

            NotificationService::send(
                [$financialRequest->requester_id],
                'financial_request_rejected',
                'تم رفض طلبك المالي',
                "تم رفض الطلب: {$financialRequest->title}. السبب: {$data['reason']}",
                ['entity_id' => $financialRequest->id, 'url' => "/dashboard/department/financial-requests/{$financialRequest->id}"]
            );
        });

        return response()->json(['data' => $this->format($financialRequest->fresh(['requester', 'department', 'financeReviewer', 'workflowSteps.actor']))]);
    }

    // ── RETURN ────────────────────────────────────────────────────────────────

    public function returnRequest(Request $request, FinancialRequest $financialRequest): JsonResponse
    {
        abort_unless($this->isFinanceActor(), 403);
        abort_unless($financialRequest->status === 'finance_review', 422, 'لا يمكن إرجاع الطلب بوضعه الحالي.');

        $data = $request->validate(['reason' => 'required|string|max:2000']);

        DB::transaction(function () use ($financialRequest, $data) {
            $this->transition($financialRequest, 'RETURN', 'returned', $data['reason']);
            $financialRequest->update(['returned_reason' => $data['reason']]);

            NotificationService::send(
                [$financialRequest->requester_id],
                'financial_request_returned',
                'تم إرجاع طلبك المالي',
                "تم إرجاع الطلب: {$financialRequest->title} لمراجعة إضافية.",
                ['entity_id' => $financialRequest->id, 'url' => "/dashboard/department/financial-requests/{$financialRequest->id}"]
            );
        });

        return response()->json(['data' => $this->format($financialRequest->fresh(['requester', 'department', 'workflowSteps.actor']))]);
    }

    // ── EXECUTIVE APPROVE ─────────────────────────────────────────────────────

    public function executiveApprove(Request $request, FinancialRequest $financialRequest): JsonResponse
    {
        abort_unless($this->isExecutiveActor(), 403);
        abort_unless($financialRequest->status === 'executive_review', 422, 'الطلب ليس في مرحلة الاعتماد التنفيذي.');

        $data = $request->validate(['note' => 'nullable|string|max:2000']);

        DB::transaction(function () use ($financialRequest, $data) {
            $this->transition($financialRequest, 'EXECUTIVE_APPROVE', 'approved', $data['note'] ?? null);
            $financialRequest->update([
                'executive_reviewer_id' => Auth::id(),
                'executive_reviewed_at' => now(),
                'executive_note'        => $data['note'] ?? null,
            ]);

            NotificationService::send(
                [$financialRequest->requester_id],
                'financial_request_approved',
                'تمت الموافقة على طلبك المالي',
                "تمت الموافقة النهائية على طلبك: {$financialRequest->title}.",
                ['entity_id' => $financialRequest->id, 'url' => "/dashboard/department/financial-requests/{$financialRequest->id}"]
            );
        });

        return response()->json(['data' => $this->format($financialRequest->fresh(['requester', 'department', 'executiveReviewer', 'workflowSteps.actor']))]);
    }

    // ── EXECUTIVE REJECT ──────────────────────────────────────────────────────

    public function executiveReject(Request $request, FinancialRequest $financialRequest): JsonResponse
    {
        abort_unless($this->isExecutiveActor(), 403);
        abort_unless($financialRequest->status === 'executive_review', 422, 'الطلب ليس في مرحلة الاعتماد التنفيذي.');

        $data = $request->validate(['reason' => 'required|string|max:2000']);

        DB::transaction(function () use ($financialRequest, $data) {
            $this->transition($financialRequest, 'EXECUTIVE_REJECT', 'rejected', $data['reason']);
            $financialRequest->update([
                'executive_reviewer_id' => Auth::id(),
                'executive_reviewed_at' => now(),
                'rejection_reason'      => $data['reason'],
            ]);

            NotificationService::send(
                [$financialRequest->requester_id],
                'financial_request_rejected',
                'تم رفض طلبك المالي',
                "تم رفض الطلب: {$financialRequest->title}. السبب: {$data['reason']}",
                ['entity_id' => $financialRequest->id, 'url' => "/dashboard/department/financial-requests/{$financialRequest->id}"]
            );
        });

        return response()->json(['data' => $this->format($financialRequest->fresh(['requester', 'department', 'executiveReviewer', 'workflowSteps.actor']))]);
    }

    // ── ATTACHMENTS ───────────────────────────────────────────────────────────

    public function storeAttachment(Request $request, FinancialRequest $financialRequest): JsonResponse
    {
        $user = Auth::user();
        $this->authorizeView($user, $financialRequest);

        $canUpload = $financialRequest->requester_id === $user->id
            || $this->isFinanceActor()
            || $this->isExecutiveActor()
            || $this->isSuperAdmin();
        abort_unless($canUpload, 403);

        $request->validate(['file' => 'required|file|max:20480']);

        $uploader = app(FileUploadService::class);
        $stored   = $uploader->store($request->file('file'), 'financial_requests');

        $attachment = FinancialRequestAttachment::create([
            'financial_request_id' => $financialRequest->id,
            'uploaded_by'          => $user->id,
            'original_name'        => $stored['original_name'],
            'file_path'            => $stored['path'],
            'disk'                 => $stored['disk'],
            'mime_type'            => $stored['mime'],
            'file_size'            => $stored['size'],
        ]);

        AuditLogService::logAction(
            'DOWNLOAD_ATTACHMENT',
            $financialRequest,
            "رُفع مرفق: {$stored['original_name']} للطلب: {$financialRequest->title}"
        );

        return response()->json(['data' => $this->formatAttachment($attachment)], 201);
    }

    public function downloadAttachment(FinancialRequest $financialRequest, FinancialRequestAttachment $attachment): mixed
    {
        $user = Auth::user();
        $this->authorizeView($user, $financialRequest);

        abort_unless($attachment->financial_request_id === $financialRequest->id, 404);

        AuditLogService::logDownload($attachment, $attachment->original_name);

        return Storage::disk($attachment->disk)->download($attachment->file_path, $attachment->original_name);
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private function authorizeView(User $user, FinancialRequest $req): void
    {
        if ($this->isSuperAdmin() || $this->isFinanceActor() || $this->isExecutiveActor()) {
            return;
        }

        // Own request
        if ($req->requester_id === $user->id) {
            return;
        }

        // Department member: can see own department requests
        $profileDeptIds = TeamProfile::where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('department_id')
            ->all();

        abort_unless(in_array($req->department_id, $profileDeptIds), 403);
    }

    private function format(FinancialRequest $req): array
    {
        return [
            'id'                    => $req->id,
            'title'                 => $req->title,
            'description'           => $req->description,
            'request_type'          => $req->request_type,
            'amount'                => (float) $req->amount,
            'currency'              => $req->currency,
            'needed_by_date'        => $req->needed_by_date?->toDateString(),
            'priority'              => $req->priority,
            'status'                => $req->status,
            'finance_note'          => $req->finance_note,
            'executive_note'        => $req->executive_note,
            'rejection_reason'      => $req->rejection_reason,
            'returned_reason'       => $req->returned_reason,
            'submitted_at'          => $req->submitted_at?->toISOString(),
            'finance_reviewed_at'   => $req->finance_reviewed_at?->toISOString(),
            'executive_reviewed_at' => $req->executive_reviewed_at?->toISOString(),
            'created_at'            => $req->created_at?->toISOString(),
            'updated_at'            => $req->updated_at?->toISOString(),
            'requester'             => $req->requester ? [
                'id'    => $req->requester->id,
                'name'  => $req->requester->name,
                'email' => $req->requester->email,
            ] : null,
            'department'            => $req->department ? [
                'id'   => $req->department->id,
                'name' => $req->department->name_ar ?? $req->department->name ?? $req->department->name_en ?? '',
            ] : null,
            'finance_reviewer'      => $req->financeReviewer ? [
                'id'   => $req->financeReviewer->id,
                'name' => $req->financeReviewer->name,
            ] : null,
            'executive_reviewer'    => $req->executiveReviewer ? [
                'id'   => $req->executiveReviewer->id,
                'name' => $req->executiveReviewer->name,
            ] : null,
            'attachments'           => $req->attachments?->map(fn($a) => $this->formatAttachment($a))->values()->all() ?? [],
            'workflow_steps'        => $req->workflowSteps?->map(fn($s) => [
                'id'          => $s->id,
                'action'      => $s->action,
                'from_status' => $s->from_status,
                'to_status'   => $s->to_status,
                'note'        => $s->note,
                'actor_role'  => $s->actor_role,
                'actor'       => $s->actor ? ['id' => $s->actor->id, 'name' => $s->actor->name] : null,
                'created_at'  => $s->created_at?->toISOString(),
            ])->values()->all() ?? [],
        ];
    }

    private function formatAttachment(FinancialRequestAttachment $a): array
    {
        return [
            'id'            => $a->id,
            'original_name' => $a->original_name,
            'mime_type'     => $a->mime_type,
            'file_size'     => $a->file_size,
            'uploaded_by'   => $a->uploader ? ['id' => $a->uploader->id, 'name' => $a->uploader->name] : null,
            'created_at'    => $a->created_at?->toISOString(),
            'download_url'  => "/api/financial-requests/{$a->financial_request_id}/attachments/{$a->id}/download",
        ];
    }
}
