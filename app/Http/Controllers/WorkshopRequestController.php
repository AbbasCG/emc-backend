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

        if ($user && !$user->isSuperAdmin() && !in_array($user->role, ['executive_admin'], true)) {
            $dept = (string) $user->department;
            if ($dept === 'operations') {
                $query->where(function ($q) use ($dept) {
                    $q->where('current_department', $dept)
                      ->orWhereBetween('current_step', [2, 6]);
                });
            } else {
                $query->where('current_department', $dept);
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

        return (new WorkshopRequestResource($workshopRequest))
            ->additional([
                'success' => true,
                'can_act' => $user ? $this->canActOnCurrentStep($user, $workshopRequest) : false,
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
        $notes       = $request->input('notes');

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
            $workshopRequest->update([
                'workflow_status' => 'final_approved',
                'status'          => 'final_approved',
            ]);

            AuditLogService::log(
                'final_approved', 'WorkshopRequest', $workshopRequest->id,
                $workshopRequest->program_name, [], [], $user->id, $request
            );

            return response()->json(['success' => true, 'message' => 'تمت الموافقة النهائية على الطلب']);
        }

        $nextDeptKey   = self::WORKFLOW_STEPS[$nextStep]['key'];
        $nextDeptLabel = self::WORKFLOW_STEPS[$nextStep]['label'];

        $workshopRequest->update([
            'current_step'       => $nextStep,
            'current_department' => $nextDeptKey,
            'workflow_status'    => $nextStep === 7 ? 'final_approval' : 'in_review',
            'status'             => 'in_review',
        ]);

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
            $workshopRequest->program_name, [], ['next_step' => $nextStep], $user->id, $request
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
            $workshopRequest->program_name, [], ['notes' => $notes, 'step' => $currentStep],
            $user->id, $request
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
            'can_act'            => $user ? $this->canActOnCurrentStep($user, $workshopRequest) : false,
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
        if ($user->isSuperAdmin()) {
            return true;
        }
        $currentStep = $workshopRequest->current_step;
        if ($currentStep === 7) {
            return in_array($user->role, ['super_admin', 'executive_admin'], true);
        }
        return (string) $user->department === (string) $workshopRequest->current_department;
    }

    private function canViewRequest(User $user, WorkshopRequest $workshopRequest): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        if (in_array($user->role, ['executive_admin'], true)) {
            return true;
        }
        $dept        = (string) $user->department;
        $currentStep = $workshopRequest->current_step;

        if ($dept === (string) $workshopRequest->current_department) {
            return true;
        }
        // Operations observes steps 2–6
        if ($dept === 'operations' && $currentStep >= 2 && $currentStep <= 6) {
            return true;
        }
        return false;
    }
}
