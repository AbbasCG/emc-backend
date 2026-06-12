<?php

namespace App\Http\Controllers;

use App\Events\WorkshopRequestSubmittedEvent;
use App\Http\Requests\StoreWorkshopRequestRequest;
use App\Http\Resources\WorkshopRequestResource;
use App\Mail\WorkshopRequestConfirmationMail;
use App\Mail\WorkshopRequestSubmittedMail;
use App\Models\User;
use App\Models\WorkshopRequest;
use App\Models\WorkshopRequestWorkflowStep;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\WorkshopAnnouncementService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Mail;
use Throwable;

class WorkshopRequestController extends Controller
{
    /** Ordered workflow: step_number => [key, label] */
    const WORKFLOW_STEPS = [
        1 => ['key' => 'quality_governance', 'label' => 'إدارة الجودة والحوكمة'],
        2 => ['key' => 'programs_tracks',    'label' => 'إدارة البرامج والمسارات'],
        3 => ['key' => 'operations',         'label' => 'إدارة العمليات والتشغيل'],
        4 => ['key' => 'finance',            'label' => 'الإدارة المالية'],
        5 => ['key' => 'media_marketing',    'label' => 'إدارة الإعلام والتسويق'],
        6 => ['key' => 'quality_governance', 'label' => 'إدارة الجودة والحوكمة (مراجعة نهائية)'],
        7 => ['key' => 'executive_admin',    'label' => 'الإدارة العليا'],
    ];

    // ── List ─────────────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $user    = $request->user();
        $perPage = min((int) $request->query('per_page', 15), 100);

        $query = WorkshopRequest::query()
            ->with('workflowSteps.actor:id,name,email')
            ->orderByDesc('created_at');

        $isGlobalViewer = $user && (
            $user->isSuperAdmin()
            || $user->role === 'admin'
            || $user->hasPermission(Permissions::WR_VIEW_ALL)
            || $user->hasPermission(Permissions::WR_MANAGE)
        );

        if ($user && ! $isGlobalViewer) {
            $dept = $this->getDeptKeyForUser($user);
            if ($dept === null) {
                $query->whereRaw('0 = 1');
            } elseif ($dept === 'operations') {
                // Operations sees: current dept + observer range (2–6) + previously acted requests
                $query->where(function ($q) use ($dept) {
                    $q->where('current_department', $dept)
                      ->orWhereBetween('current_step', [2, 6])
                      ->orWhereHas('workflowSteps', fn ($q2) =>
                          $q2->where('department_key', $dept)->where('status', 'approved')
                      );
                });
            } else {
                // Other depts: current dept + previously approved steps (history)
                $query->where(function ($q) use ($dept) {
                    $q->where('current_department', $dept)
                      ->orWhereHas('workflowSteps', fn ($q2) =>
                          $q2->where('department_key', $dept)->where('status', 'approved')
                      );
                });
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('workflow_status')) {
            $query->where('workflow_status', $request->query('workflow_status'));
        }
        if ($request->filled('search')) {
            $term = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('program_name', 'like', $term)
                  ->orWhere('requester_name', 'like', $term)
                  ->orWhere('requester_email', 'like', $term);
            });
        }

        return WorkshopRequestResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Workshop requests retrieved']);
    }

    // ── Store (public) ───────────────────────────────────────────────────────

    public function store(
        StoreWorkshopRequestRequest $request,
        WorkshopAnnouncementService $announcementService
    ): JsonResponse {
        try {
            $validated = $request->validated();

            $speakerPhotoPath = null;
            if ($request->hasFile('speaker_photo')) {
                $speakerPhotoPath = $request->file('speaker_photo')
                    ->store('workshop-speakers', 'public');
            }

            $workshopRequest = WorkshopRequest::create([
                'requester_name'              => $validated['requester_name'],
                'requester_email'             => $validated['requester_email'],
                'requester_phone'             => $validated['requester_phone']      ?? null,
                'requester_department'        => $validated['requester_department'] ?? null,
                'program_name'                => $validated['program_name'],
                'speaker_name'                => $validated['speaker_name'],
                'speaker_job_title'           => $validated['speaker_job_title'],
                'speaker_photo'               => $speakerPhotoPath,
                'topics'                      => $validated['topics'],
                'categories'                  => $validated['categories'],
                'target_audience'             => $validated['target_audience'],
                'proposed_date_1'             => $validated['proposed_date_1']      ?? null,
                'proposed_time_1'             => $validated['proposed_time_1']      ?? null,
                'proposed_date_2'             => $validated['proposed_date_2']      ?? null,
                'proposed_time_2'             => $validated['proposed_time_2']      ?? null,
                'proposed_date_3'             => $validated['proposed_date_3']      ?? null,
                'proposed_time_3'             => $validated['proposed_time_3']      ?? null,
                // Mirror first option to legacy columns for backward compat
                'proposed_date'               => $validated['proposed_date_1']      ?? $validated['proposed_date'] ?? null,
                'proposed_time'               => $validated['proposed_time_1']      ?? $validated['proposed_time'] ?? null,
                'location_types'              => $validated['location_types'],
                'price_type'                  => $validated['price_type'],
                'price_amount'                => $validated['price_amount']         ?? null,
                'generated_announcement_text' => null,
                'status'                      => 'new',
                'workflow_status'             => 'pending',
                'current_step'                => 1,
                'current_department'          => 'quality_governance',
            ]);

            $workshopRequest->update([
                'generated_announcement_text' => $announcementService->generateAnnouncement($workshopRequest),
            ]);

            WorkshopRequestWorkflowStep::create([
                'workshop_request_id' => $workshopRequest->id,
                'step_number'         => 1,
                'department_key'      => 'quality_governance',
                'department_label'    => 'إدارة الجودة والحوكمة',
                'status'              => 'pending',
            ]);

            event(new WorkshopRequestSubmittedEvent($workshopRequest));
            $this->sendEmails($workshopRequest);

            NotificationService::workshopRequestMovedToStep(
                $workshopRequest->id,
                $workshopRequest->program_name,
                $workshopRequest->requester_name,
                1,
                'quality_governance',
                'إدارة الجودة والحوكمة'
            );

            return (new WorkshopRequestResource($workshopRequest))
                ->additional(['success' => true, 'message' => 'تم استقبال طلب البرنامج التدريبي بنجاح'])
                ->response()
                ->setStatusCode(201);

        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['success' => false, 'message' => 'حدث خطأ أثناء معالجة الطلب'], 500);
        }
    }

    // ── Show ─────────────────────────────────────────────────────────────────

    public function show(WorkshopRequest $workshopRequest): JsonResponse
    {
        $user = request()->user();

        if ($user && !$this->canViewRequest($user, $workshopRequest)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بالوصول لهذا الطلب'], 403);
        }

        $workshopRequest->load('workflowSteps.actor:id,name,email');

        $canAct         = $user ? $this->canActOnCurrentStep($user, $workshopRequest) : false;
        $dept           = $user ? $this->getDeptKeyForUser($user) : null;
        $isHistoryViewer = ($dept !== null && !$canAct)
            ? $workshopRequest->workflowSteps->contains(
                fn ($step) => $step->department_key === $dept && $step->status === 'approved'
            )
            : false;

        return (new WorkshopRequestResource($workshopRequest))
            ->additional([
                'success'          => true,
                'can_act'          => $canAct,
                'is_history_viewer' => $isHistoryViewer,
            ])
            ->response();
    }

    // ── Approve ──────────────────────────────────────────────────────────────

    public function approve(Request $request, WorkshopRequest $workshopRequest): JsonResponse
    {
        $user = $request->user();

        if (!$this->canActOnCurrentStep($user, $workshopRequest)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بالموافقة على هذا الطلب في مرحلته الحالية'], 403);
        }

        if (in_array($workshopRequest->workflow_status, ['final_approved', 'rejected'], true)) {
            return response()->json(['success' => false, 'message' => 'الطلب في حالة نهائية ولا يمكن تعديله'], 422);
        }

        if ($user->email === $workshopRequest->requester_email) {
            return response()->json(['success' => false, 'message' => 'لا يمكنك الموافقة على طلبك الخاص'], 403);
        }

        $currentStep = $workshopRequest->current_step;
        $dept        = $this->getDeptKeyForUser($user);
        $notes       = $request->input('notes');

        // Operations (step 3) and executive_admin (step 7) may select the approved date option
        $selectedDateOption = null;
        if (in_array($dept, ['operations', 'executive_admin'], true)) {
            $raw = $request->input('selected_date_option');
            if (in_array((int) $raw, [1, 2, 3], true)) {
                $selectedDateOption = (int) $raw;
            }
        }

        WorkshopRequestWorkflowStep::updateOrCreate(
            ['workshop_request_id' => $workshopRequest->id, 'step_number' => $currentStep],
            [
                'department_key'   => $workshopRequest->current_department,
                'department_label' => self::WORKFLOW_STEPS[$currentStep]['label'] ?? $workshopRequest->current_department,
                'status'           => 'approved',
                'actor_id'         => $user->id,
                'acted_at'         => now(),
                'notes'            => $notes,
            ]
        );

        $nextStep = $currentStep + 1;

        if ($nextStep > 7) {
            $workshopRequest->update(array_filter([
                'workflow_status'      => 'final_approved',
                'status'               => 'final_approved',
                'selected_date_option' => $selectedDateOption,
            ], fn ($v) => $v !== null));

            AuditLogService::log(
                'final_approved', 'WorkshopRequest', $workshopRequest->id,
                $workshopRequest->program_name, null, null,
                ['acted_by_id' => $user->id, 'selected_date_option' => $selectedDateOption],
                $request
            );

            return response()->json(['success' => true, 'message' => 'تمت الموافقة النهائية على الطلب']);
        }

        $nextDeptKey   = self::WORKFLOW_STEPS[$nextStep]['key'];
        $nextDeptLabel = self::WORKFLOW_STEPS[$nextStep]['label'];

        $updateData = [
            'current_step'       => $nextStep,
            'current_department' => $nextDeptKey,
            'workflow_status'    => $nextStep === 7 ? 'final_approval' : 'in_review',
            'status'             => 'in_review',
        ];
        if ($selectedDateOption !== null) {
            $updateData['selected_date_option'] = $selectedDateOption;
        }
        $workshopRequest->update($updateData);

        WorkshopRequestWorkflowStep::firstOrCreate(
            ['workshop_request_id' => $workshopRequest->id, 'step_number' => $nextStep],
            ['department_key' => $nextDeptKey, 'department_label' => $nextDeptLabel, 'status' => 'pending']
        );

        NotificationService::workshopRequestMovedToStep(
            $workshopRequest->id, $workshopRequest->program_name,
            $workshopRequest->requester_name, $nextStep, $nextDeptKey, $nextDeptLabel
        );

        AuditLogService::log(
            'approved_step_' . $currentStep, 'WorkshopRequest', $workshopRequest->id,
            $workshopRequest->program_name, null, ['next_step' => $nextStep, 'next_department' => $nextDeptKey],
            ['acted_by_id' => $user->id, 'selected_date_option' => $selectedDateOption],
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'تمت الموافقة وتم تحويل الطلب إلى المرحلة التالية: ' . $nextDeptLabel,
        ]);
    }

    // ── Reject ───────────────────────────────────────────────────────────────

    public function reject(Request $request, WorkshopRequest $workshopRequest): JsonResponse
    {
        $user = $request->user();

        if (!$this->canActOnCurrentStep($user, $workshopRequest)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك برفض هذا الطلب في مرحلته الحالية'], 403);
        }

        if (in_array($workshopRequest->workflow_status, ['final_approved', 'rejected'], true)) {
            return response()->json(['success' => false, 'message' => 'الطلب في حالة نهائية ولا يمكن تعديله'], 422);
        }

        if ($user->email === $workshopRequest->requester_email) {
            return response()->json(['success' => false, 'message' => 'لا يمكنك رفض طلبك الخاص'], 403);
        }

        $currentStep = $workshopRequest->current_step;
        $notes       = $request->input('notes', '');

        WorkshopRequestWorkflowStep::updateOrCreate(
            ['workshop_request_id' => $workshopRequest->id, 'step_number' => $currentStep],
            [
                'department_key'   => $workshopRequest->current_department,
                'department_label' => self::WORKFLOW_STEPS[$currentStep]['label'] ?? $workshopRequest->current_department,
                'status'           => 'rejected',
                'actor_id'         => $user->id,
                'acted_at'         => now(),
                'notes'            => $notes,
            ]
        );

        $workshopRequest->update([
            'workflow_status' => 'rejected',
            'status'          => 'rejected',
        ]);

        AuditLogService::log(
            'rejected', 'WorkshopRequest', $workshopRequest->id,
            $workshopRequest->program_name, null, ['step' => $currentStep],
            ['acted_by_id' => $user->id, 'notes' => $notes ?: null],
            $request
        );

        NotificationService::workshopRequestRejected(
            $workshopRequest->id, $workshopRequest->program_name,
            $workshopRequest->requester_name, $currentStep
        );

        return response()->json(['success' => true, 'message' => 'تم رفض الطلب']);
    }

    // ── Workflow History ─────────────────────────────────────────────────────

    public function workflowHistory(WorkshopRequest $workshopRequest): JsonResponse
    {
        $user = request()->user();

        if ($user && !$this->canViewRequest($user, $workshopRequest)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح'], 403);
        }

        $recordedSteps = $workshopRequest->workflowSteps()->with('actor:id,name,email')->get();

        $canAct          = $user ? $this->canActOnCurrentStep($user, $workshopRequest) : false;
        $dept            = $user ? $this->getDeptKeyForUser($user) : null;
        $isHistoryViewer = ($dept !== null && !$canAct)
            ? $recordedSteps->contains(
                fn ($step) => $step->department_key === $dept && $step->status === 'approved'
            )
            : false;

        $allSteps = collect(self::WORKFLOW_STEPS)->map(function ($step, $number) use ($workshopRequest, $recordedSteps) {
            $record = $recordedSteps->firstWhere('step_number', $number);

            return [
                'step_number'      => $number,
                'department_key'   => $step['key'],
                'department_label' => $step['label'],
                'status'           => $record?->status
                    ?? ($number < $workshopRequest->current_step ? 'approved' : 'pending'),
                'actor_name'       => $record?->actor?->name,
                'actor_email'      => $record?->actor?->email,
                'acted_at'         => $record?->acted_at,
                'notes'            => $record?->notes,
                'is_current'       => $number === $workshopRequest->current_step
                    && !in_array($workshopRequest->workflow_status, ['final_approved', 'rejected'], true),
            ];
        })->values();

        return response()->json([
            'success'            => true,
            'current_step'       => $workshopRequest->current_step,
            'current_department' => $workshopRequest->current_department,
            'workflow_status'    => $workshopRequest->workflow_status,
            'can_act'            => $canAct,
            'is_history_viewer'  => $isHistoryViewer,
            'all_steps'          => $allSteps,
        ]);
    }

    // ── Update (legacy admin patch) ──────────────────────────────────────────

    public function update(Request $request, WorkshopRequest $workshopRequest): JsonResponse
    {
        $data = $request->validate([
            'status'                      => 'sometimes|string|max:50',
            'generated_announcement_text' => 'sometimes|nullable|string',
        ]);

        $oldSnap = ['status' => $workshopRequest->status, 'program_name' => $workshopRequest->program_name];
        $workshopRequest->update($data);
        $fresh  = $workshopRequest->fresh();
        $action = isset($data['status']) && $fresh->status !== $oldSnap['status'] ? 'status_changed' : 'updated';

        AuditLogService::log($action, 'WorkshopRequest', $fresh->id, $fresh->program_name,
            $oldSnap, ['status' => $fresh->status, 'program_name' => $fresh->program_name], null, $request);

        return (new WorkshopRequestResource($fresh))
            ->additional(['success' => true, 'message' => 'Updated'])
            ->response();
    }

    // ── Destroy ──────────────────────────────────────────────────────────────

    public function destroy(WorkshopRequest $workshopRequest): JsonResponse
    {
        $snap  = ['status' => $workshopRequest->status, 'program_name' => $workshopRequest->program_name];
        $label = $workshopRequest->program_name;
        $id    = $workshopRequest->id;
        $workshopRequest->delete();
        AuditLogService::log('deleted', 'WorkshopRequest', $id, $label, $snap, null, null, request());
        return response()->json(['success' => true, 'message' => 'Deleted']);
    }

    // ── Email helpers ─────────────────────────────────────────────────────────

    protected function sendEmails(WorkshopRequest $workshopRequest): void
    {
        try {
            Mail::to($workshopRequest->requester_email)
                ->queue(new WorkshopRequestConfirmationMail($workshopRequest));
        } catch (Throwable $e) {
            report($e);
        }

        try {
            $designerEmail = (string) config('mail.designer_email', '');
            if ($designerEmail !== '' && $designerEmail !== 'null') {
                Mail::to($designerEmail)->queue(new WorkshopRequestSubmittedMail($workshopRequest));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ── Authorization helpers ─────────────────────────────────────────────────

    private function canActOnCurrentStep(User $user, WorkshopRequest $workshopRequest): bool
    {
        if (in_array($workshopRequest->workflow_status, ['final_approved', 'rejected'], true)) {
            return false;
        }

        // Super-admin and full-admin bypass all checks
        if ($user->isSuperAdmin() || $user->role === 'admin') {
            return true;
        }

        // Permission gate: must hold approve or manage to act on any step
        if (! $user->hasPermission(Permissions::WR_APPROVE)
            && ! $user->hasPermission(Permissions::WR_MANAGE)) {
            return false;
        }

        // Department-stage gate remains intact regardless of permission level
        $dept        = $this->getDeptKeyForUser($user);
        $currentStep = $workshopRequest->current_step;

        if ($dept === null) {
            return false;
        }
        if ($currentStep === 7) {
            return $dept === 'executive_admin';
        }

        return $dept === (string) $workshopRequest->current_department;
    }

    private function canViewRequest(User $user, WorkshopRequest $workshopRequest): bool
    {
        // Global admin access
        if ($user->isSuperAdmin() || $user->role === 'admin') {
            return true;
        }

        // Granular: view_all / manage bypass department filter entirely
        if ($user->hasPermission(Permissions::WR_VIEW_ALL)
            || $user->hasPermission(Permissions::WR_MANAGE)) {
            return true;
        }

        // Department-scoped access: user must hold at least view_department or review
        $hasDeptView = $user->hasPermission(Permissions::WR_VIEW_DEPARTMENT)
            || $user->hasPermission(Permissions::WR_REVIEW)
            || $user->hasPermission(Permissions::WR_APPROVE)
            || $user->hasPermission(Permissions::WR_REJECT);

        // Resolve department key regardless; if null, deny
        $dept        = $this->getDeptKeyForUser($user);
        $currentStep = $workshopRequest->current_step;

        if ($dept === null || ! $hasDeptView) {
            return false;
        }

        // Current stage match
        if ($dept === (string) $workshopRequest->current_department) {
            return true;
        }

        // Operations observes steps 2–6 regardless of whether it's acted yet
        if ($dept === 'operations' && $currentStep >= 2 && $currentStep <= 6) {
            return true;
        }

        // History viewers: dept has previously approved a step on this request
        if ($workshopRequest->relationLoaded('workflowSteps')) {
            return $workshopRequest->workflowSteps->contains(
                fn ($step) => $step->department_key === $dept && $step->status === 'approved'
            );
        }

        return $workshopRequest->workflowSteps()
            ->where('department_key', $dept)
            ->where('status', 'approved')
            ->exists();
    }

    /**
     * Resolve the canonical workflow department key for a user.
     *
     * Resolution order:
     * 1. Named-role fast path (quality_manager → quality_governance, etc.)
     * 2. DB lookup — department where this user is the leader (most reliable for department_manager)
     * 3. DB lookup — team_profile where this user is linked as is_leader = true
     * 4. users.department if it already is a valid workflow key
     * 5. users.department matched against Arabic department labels
     * 6. users.department matched against URL slugs
     * 7. null — no resolvable department
     */
    private function getDeptKeyForUser(User $user): ?string
    {
        static $knownKeys   = null;
        static $labelMap    = null;
        static $slugMap     = null;

        if ($knownKeys === null) {
            $knownKeys = array_unique(array_column(self::WORKFLOW_STEPS, 'key'));

            $labelMap = [
                'إدارة الجودة والحوكمة'         => 'quality_governance',
                'إدارة البرامج والمسارات'       => 'programs_tracks',
                'إدارة العمليات'                => 'operations',
                'إدارة التشغيل والعمليات'       => 'operations',
                'إدارة العمليات والتشغيل'       => 'operations',
                'إدارة التشغيل'                 => 'operations',
                'الإدارة المالية'               => 'finance',
                'إدارة المالية'                 => 'finance',
                'إدارة الإعلام والتسويق'        => 'media_marketing',
                'إدارة التسويق والإعلام'        => 'media_marketing',
                'الإدارة العليا'                => 'executive_admin',
            ];

            $slugMap = [
                'quality-governance'    => 'quality_governance',
                'quality_governance'    => 'quality_governance',
                'programs-tracks'       => 'programs_tracks',
                'programs_tracks'       => 'programs_tracks',
                'operations'            => 'operations',
                'finance'               => 'finance',
                'media-marketing'       => 'media_marketing',
                'media_marketing'       => 'media_marketing',
                'marketing-media'       => 'media_marketing',
                'executive-leadership'  => 'executive_admin',
                'executive_admin'       => 'executive_admin',
                'executive'             => 'executive_admin',
            ];
        }

        // ── 1. Named-role fast path ───────────────────────────────────────────
        $roleToKey = [
            'quality_manager'    => 'quality_governance',
            'finance_manager'    => 'finance',
            'marketing_manager'  => 'media_marketing',
            'executive_admin'    => 'executive_admin',
            'programs_manager'   => 'programs_tracks',
            'operations_manager' => 'operations',
        ];

        if (isset($roleToKey[$user->role])) {
            return $roleToKey[$user->role];
        }

        // ── 2. DB lookup: departments where this user is the leader ───────────
        // Most reliable signal — admin sets leader_id when assigning a dept manager.
        $leaderDept = \App\Models\Department::where('leader_id', $user->id)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->first(['name_ar', 'name', 'slug']);

        if ($leaderDept) {
            return $labelMap[$leaderDept->name_ar]
                ?? $slugMap[$leaderDept->slug]
                ?? $slugMap[str_replace('-', '_', (string) $leaderDept->slug)]
                ?? null;
        }

        // ── 3. DB lookup: team_profile linked to this user as leader ──────────
        $leaderProfile = \App\Models\TeamProfile::where('user_id', $user->id)
            ->where('is_leader', true)
            ->with('department:id,name_ar,slug,is_active')
            ->first();

        if ($leaderProfile?->department) {
            $d = $leaderProfile->department;
            return $labelMap[$d->name_ar]
                ?? $slugMap[$d->slug]
                ?? $slugMap[str_replace('-', '_', (string) $d->slug)]
                ?? null;
        }

        // ── 4 – 6. Fallback: parse users.department string ────────────────────
        $dept = trim((string) $user->department);
        if ($dept === '') {
            return null;
        }

        // Already a valid workflow key
        if (in_array($dept, $knownKeys, true)) {
            return $dept;
        }

        return $labelMap[$dept] ?? $slugMap[$dept] ?? null;
    }
}
