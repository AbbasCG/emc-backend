<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Jobs\GenerateCertificatePdfJob;
use App\Mail\CertificateRevokedMail;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Certificate\CertificateRenderService;
use App\Services\Certificate\CertificateStatusGuard;
use App\Services\CertificateEligibilityService;
use App\Services\CertificateService;
use App\Services\EmailService;
use App\Support\CertificateTypes;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificateService $certificateService,
        private readonly CertificateEligibilityService $eligibilityService,
    ) {}

    // ── List ──────────────────────────────────────────────────────────────────

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);

        $certificates = Certificate::with(['user', 'course', 'track', 'workshop', 'approvedBy', 'template', 'issuedBy'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->certificate_type ?? $request->type, fn ($q) => $q->where('certificate_type', $request->certificate_type ?? $request->type))
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->batch_id, fn ($q) => $q->where('batch_id', $request->batch_id))
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->workshop_id, fn ($q) => $q->where('workshop_id', $request->workshop_id))
            ->when($request->track_id ?? $request->learning_path_id, fn ($q) => $q->where('track_id', $request->track_id ?? $request->learning_path_id))
            ->when($request->approval_status, fn ($q) => $q->where('approval_status', $request->approval_status))
            ->when($request->template_id, fn ($q) => $q->where('certificate_template_id', $request->template_id))
            ->when($request->issued_from, fn ($q) => $q->whereDate('issued_at', '>=', $request->issued_from))
            ->when($request->issued_to, fn ($q) => $q->whereDate('issued_at', '<=', $request->issued_to))
            ->when($request->created_from, fn ($q) => $q->whereDate('created_at', '>=', $request->created_from))
            ->when($request->created_to, fn ($q) => $q->whereDate('created_at', '<=', $request->created_to))
            ->when($request->pdf_status, function ($q) use ($request) {
                match ($request->pdf_status) {
                    'ready'      => $q->whereNotNull('pdf_path'),
                    'missing'    => $q->whereNull('pdf_path')->whereNotIn('status', ['generation_failed', 'pending_generation']),
                    'failed'     => $q->where('status', 'generation_failed'),
                    'generating' => $q->where('status', 'pending_generation'),
                    default      => null,
                };
            })
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($q2) use ($request) {
                    $q2->where('title', 'like', "%{$request->search}%")
                        ->orWhere('certificate_code', 'like', "%{$request->search}%")
                        ->orWhere('verification_code', 'like', "%{$request->search}%")
                        ->orWhere('recipient_name', 'like', "%{$request->search}%")
                        ->orWhere('recipient_email', 'like', "%{$request->search}%")
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$request->search}%")->orWhere('email', 'like', "%{$request->search}%"))
                        ->orWhereHas('course', fn ($cq) => $cq->where('title', 'like', "%{$request->search}%"))
                        ->orWhereHas('workshop', fn ($wq) => $wq->where('title', 'like', "%{$request->search}%"));
                });
            })
            ->latest()
            ->paginate(min((int) ($request->per_page ?? 25), 100));

        return response()->json([
            'success' => true,
            'data'    => CertificateResource::collection($certificates)->response()->getData(true),
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $certificate);

        return response()->json([
            'success' => true,
            'data'    => new CertificateResource(
                $certificate->load(['user', 'course', 'track', 'workshop', 'approvedBy', 'template', 'issuedBy', 'batch'])
            ),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Certificate::class);

        $data = $request->validate([
            'user_id'                 => 'required|exists:users,id',
            'course_id'               => 'nullable|exists:courses,id',
            'track_id'                => 'nullable|exists:tracks,id',
            'workshop_id'             => 'nullable|exists:workshops,id',
            'certificate_type'        => 'required|string|in:' . implode(',', CertificateTypes::ALL),
            'title'                   => 'required|string|max:255',
            'professional_title'      => 'nullable|string|max:255',
            'expires_at'              => 'nullable|date',
            'metadata'                => 'nullable|array',
            'certificate_template_id' => 'nullable|exists:certificate_templates,id',
            'recipient_name'          => 'nullable|string|max:255',
            'recipient_email'         => 'nullable|email',
            'related_type'            => 'nullable|string',
            'related_id'              => 'nullable|integer',
        ]);

        $data['verification_code'] = $this->certificateService->generateVerificationCode();
        $data['issued_by']         = $request->user()->id;

        $certificate = Certificate::create($data);

        ActivityLogger::log('certificate.created', $certificate, [], $request, null, [
            'certificate_code' => $certificate->certificate_code,
            'user_id'          => $certificate->user_id,
            'template_id'      => $certificate->certificate_template_id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => new CertificateResource($certificate->load(['user', 'course', 'track', 'workshop', 'template'])),
        ], 201);
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    public function approve(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        $this->authorize('approve', $certificate);

        CertificateStatusGuard::assertCanTransition($certificate, 'approved');

        $oldStatus   = $certificate->status;
        $certificate = $this->certificateService->approve($certificate, $request->user());

        ActivityLogger::log('certificate.approved', $certificate, [], $request, ['status' => $oldStatus], ['status' => 'approved']);

        return response()->json([
            'success' => true,
            'message' => 'تمت الموافقة على الشهادة.',
            'data'    => new CertificateResource($certificate->load(['user', 'approvedBy'])),
        ]);
    }

    // ── Issue ─────────────────────────────────────────────────────────────────

    public function issue(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        $this->authorize('issue', $certificate);

        if (! in_array($certificate->status, ['pending', 'approved'], true)) {
            return response()->json(['success' => false, 'message' => 'لا يمكن إصدار الشهادة بحالتها الحالية.'], 422);
        }

        $oldStatus   = $certificate->status;
        $certificate = $this->certificateService->issue($certificate, $request->user());

        ActivityLogger::log('certificate.issued', $certificate, [], $request, ['status' => $oldStatus], ['status' => 'issued']);

        return response()->json([
            'success' => true,
            'message' => 'تم إصدار الشهادة.',
            'data'    => new CertificateResource($certificate->load(['user', 'approvedBy'])),
        ]);
    }

    // ── Revoke ────────────────────────────────────────────────────────────────

    public function revoke(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        $this->authorize('revoke', $certificate);

        if ($certificate->status === 'revoked') {
            return response()->json(['success' => false, 'message' => 'الشهادة ملغاة بالفعل.'], 422);
        }

        CertificateStatusGuard::assertCanTransition($certificate, 'revoked');

        $request->validate([
            'revoke_reason' => 'required|string|min:5|max:1000',
        ]);

        $oldStatus = $certificate->status;

        $certificate->update([
            'status'        => 'revoked',
            'revoked_at'    => now(),
            'revoke_reason' => $request->revoke_reason,
        ]);

        ActivityLogger::log('certificate.revoked', $certificate, [], $request,
            ['status' => $oldStatus],
            ['status' => 'revoked', 'reason' => $request->revoke_reason],
        );

        if ($certificate->user?->email) {
            EmailService::dispatch(
                'certificate_revoked',
                $certificate->user->email,
                $certificate->user->name,
                $certificate->user_id,
                new CertificateRevokedMail($certificate->fresh()),
                'إشعار بإلغاء شهادتك — ' . config('app.name'),
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء الشهادة.',
            'data'    => new CertificateResource($certificate),
        ]);
    }

    // ── Regenerate ────────────────────────────────────────────────────────────

    public function regenerate(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        $this->authorize('regenerate', $certificate);

        if ($certificate->status === 'revoked') {
            return response()->json(['success' => false, 'message' => 'لا يمكن إعادة توليد شهادة ملغاة.'], 422);
        }

        GenerateCertificatePdfJob::dispatch($certificate, $certificate->batch_id);

        ActivityLogger::log('certificate.regenerated', $certificate, [], $request, null, [
            'certificate_code' => $certificate->certificate_code,
            'template_id'      => $certificate->certificate_template_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب إعادة توليد الشهادة.',
            'data'    => new CertificateResource($certificate),
        ]);
    }

    // ── Download ──────────────────────────────────────────────────────────────

    public function download(Request $request, Certificate $certificate): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $this->authorize('download', $certificate);

        try {
            $response = app(CertificateRenderService::class)->getDownloadResponse($certificate);

            ActivityLogger::log('certificate.downloaded', $certificate, [], $request, null, [
                'certificate_code' => $certificate->certificate_code,
                'template_id'      => $certificate->certificate_template_id,
                'role'             => $request->user()?->role,
            ]);

            return $response;
        } catch (\Throwable $e) {
            Log::error('[Certificate] Admin download failed', [
                'certificate_id' => $certificate->id,
                'error'          => $e->getMessage(),
                'ip'             => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'تعذّر إنشاء ملف الشهادة. حاول لاحقاً.',
            ], 500);
        }
    }

    // ── Bulk issue ────────────────────────────────────────────────────────────

    public function bulkIssue(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Certificate::class);

        $maxBatch = (int) config('certificates.max_bulk_size', 500);

        $request->validate([
            'user_ids'           => "nullable|array|min:1|max:{$maxBatch}",
            'user_ids.*'         => 'required|integer|exists:users,id',
            'recipient_ids'      => "nullable|array|min:1|max:{$maxBatch}",
            'recipient_ids.*'    => 'required|integer|exists:users,id',
            'type'               => 'nullable|string',
            'certificate_type'   => 'nullable|string',
            'template_id'        => 'nullable|exists:certificate_templates,id',
            'related_type'       => 'nullable|string',
            'related_id'         => 'nullable|integer',
            'title'              => 'nullable|string|max:255',
            'notes'              => 'nullable|string',
            'metadata'           => 'nullable|array|max:20',
            'override'           => 'nullable|boolean',
        ]);

        $userIds = $request->input('user_ids') ?? $request->input('recipient_ids');
        if (! is_array($userIds) || count($userIds) < 1) {
            throw ValidationException::withMessages(['user_ids' => ['يجب اختيار طالب واحد على الأقل.']]);
        }

        $type = $request->input('type') ?? $request->input('certificate_type');
        if (! $type || ! CertificateTypes::isValid($type)) {
            throw ValidationException::withMessages(['type' => ['نوع الشهادة مطلوب.']]);
        }

        $data = [
            'user_ids'         => array_values(array_map('intval', $userIds)),
            'type'             => $type,
            'certificate_type' => $type,
            'template_id'      => $request->input('template_id'),
            'related_type'     => $request->input('related_type'),
            'related_id'       => $request->input('related_id'),
            'title'            => $request->input('title'),
            'notes'            => $request->input('notes'),
            'metadata'         => $request->input('metadata'),
            'override'         => $request->boolean('override'),
        ];

        $batch = $this->certificateService->issueBulk($data['user_ids'], $data, $request->user());

        ActivityLogger::log('certificate.batch_started', null, [], $request, null, [
            'batch_id'         => $batch->id,
            'total_recipients' => $batch->total_recipients,
            'certificate_type' => $type,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء طلب إصدار الشهادات بنجاح.',
            'data'    => [
                'batch_id'             => $batch->id,
                'batch_code'           => $batch->batch_code,
                'total_recipients'     => $batch->total_recipients,
                'certificates_created' => $batch->total_recipients,
                'status'               => $batch->status,
            ],
        ], 202);
    }

    // ── Eligibility ───────────────────────────────────────────────────────────

    public function eligibility(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Certificate::class);

        $request->validate([
            'related_type'     => 'required|string',
            'related_id'       => 'nullable|integer',
            'type'             => 'nullable|string',
            'certificate_type' => 'nullable|string',
        ]);

        $relatedType     = $request->related_type;
        $relatedId       = (int) ($request->related_id ?? 0);
        $certificateType = $request->certificate_type ?? $request->type ?? 'course_completion';

        if ($relatedId <= 0) {
            return response()->json([
                'success' => true,
                'data'    => ['summary' => ['total' => 0, 'eligible' => 0, 'ineligible' => 0, 'already_issued' => 0], 'students' => []],
            ]);
        }

        $eligibility = match ($relatedType) {
            'course', 'App\\Models\\Course'                           => $this->eligibilityService->forCourse($relatedId, ['certificate_type' => $certificateType]),
            'workshop', 'App\\Models\\Workshop'                       => $this->eligibilityService->forWorkshop($relatedId, ['certificate_type' => $certificateType]),
            'learning_path', 'track', 'App\\Models\\Track',
            'App\\Models\\LearningPath'                               => $this->eligibilityService->forLearningPath($relatedId, ['certificate_type' => $certificateType]),
            default                                                   => [],
        };

        $eligible      = collect($eligibility)->where('is_eligible', true)->count();
        $alreadyIssued = collect($eligibility)->where('already_issued', true)->count();
        $ineligible    = count($eligibility) - $eligible;

        return response()->json([
            'success' => true,
            'data'    => [
                'summary'  => ['total' => count($eligibility), 'eligible' => $eligible, 'ineligible' => $ineligible, 'already_issued' => $alreadyIssued],
                'students' => collect($eligibility)->map(fn ($item) => [
                    'user_id'               => $item['user_id'] ?? $item['user']?->id,
                    'name'                  => $item['name'] ?? $item['user']?->name,
                    'email'                 => $item['email'] ?? $item['user']?->email,
                    'user'                  => $item['user'] ? ['id' => $item['user']->id, 'name' => $item['user']->name, 'email' => $item['user']->email] : null,
                    'progress'              => $item['progress'],
                    'progress_percentage'   => $item['progress'],
                    'progress_pct'          => $item['progress'],
                    'attendance'            => $item['attendance'],
                    'attendance_percentage' => $item['attendance'],
                    'attendance_pct'        => $item['attendance'],
                    'assignments'           => $item['assignments'],
                    'assignments_completed' => $item['assignments_completed'] ?? 0,
                    'assignments_total'     => $item['assignments_total'] ?? 0,
                    'is_eligible'           => $item['is_eligible'],
                    'eligible'              => $item['is_eligible'],
                    'reason'                => $item['reason'],
                    'already_issued'        => $item['already_issued'] ?? false,
                    'certificate_status'    => $item['certificate_status'] ?? null,
                    'existing_certificate'  => ($item['existing_certificate'] ?? null)
                        ? ['id' => $item['existing_certificate']->id, 'status' => $item['existing_certificate']->status, 'certificate_code' => $item['existing_certificate']->certificate_code]
                        : null,
                ])->values(),
            ],
        ]);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function stats(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);

        $total           = Certificate::count();
        $byStatus        = Certificate::selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');
        $byType          = Certificate::selectRaw('certificate_type, count(*) as count')->groupBy('certificate_type')->pluck('count', 'certificate_type');
        $recentCount     = Certificate::where('created_at', '>=', now()->subDays(30))->count();
        $issuedToday     = Certificate::whereDate('issued_at', today())->count();
        $issuedThisMonth = Certificate::where('status', 'issued')->whereMonth('issued_at', now()->month)->whereYear('issued_at', now()->year)->count();
        $pdfFailed       = Certificate::where('status', 'generation_failed')->count();
        $generatedMonth  = Certificate::whereNotNull('pdf_generated_at')->whereMonth('pdf_generated_at', now()->month)->whereYear('pdf_generated_at', now()->year)->count();

        $recent = Certificate::with(['user', 'course', 'workshop', 'track', 'template'])->latest()->limit(10)->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'              => $total,
                'issued_this_month'  => $issuedThisMonth,
                'pending'            => (int) ($byStatus['pending'] ?? 0) + (int) ($byStatus['approved'] ?? 0) + (int) ($byStatus['pending_generation'] ?? 0),
                'revoked'            => (int) ($byStatus['revoked'] ?? 0),
                'rejected'           => (int) ($byStatus['rejected'] ?? 0),
                'pdf_failed'         => $pdfFailed,
                'generated_this_month' => $generatedMonth,
                'by_type'            => $byType,
                'recent'             => CertificateResource::collection($recent),
                'by_status'          => $byStatus,
                'recent_30d'         => $recentCount,
                'issued_today'       => $issuedToday,
            ],
        ]);
    }

    // ── Export ───────────────────────────────────────────────────────────────

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', Certificate::class);

        $request->validate([
            'status'           => 'nullable|string',
            'certificate_type' => 'nullable|string',
            'approval_status'  => 'nullable|string',
            'template_id'      => 'nullable|integer',
            'issued_from'      => 'nullable|date',
            'issued_to'        => 'nullable|date',
            'search'           => 'nullable|string|max:200',
        ]);

        ActivityLogger::log('certificate.exported', null, [], $request, null, [
            'filters' => $request->only(['status', 'certificate_type', 'approval_status', 'issued_from', 'issued_to', 'search', 'template_id']),
        ]);

        $query = Certificate::with(['user', 'course', 'workshop', 'track', 'template'])
            ->when($request->status,           fn ($q) => $q->where('status', $request->status))
            ->when($request->certificate_type, fn ($q) => $q->where('certificate_type', $request->certificate_type))
            ->when($request->approval_status,  fn ($q) => $q->where('approval_status', $request->approval_status))
            ->when($request->template_id,      fn ($q) => $q->where('certificate_template_id', $request->template_id))
            ->when($request->issued_from,      fn ($q) => $q->whereDate('issued_at', '>=', $request->issued_from))
            ->when($request->issued_to,        fn ($q) => $q->whereDate('issued_at', '<=', $request->issued_to))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($q2) use ($request) {
                    $q2->where('certificate_code', 'like', "%{$request->search}%")
                        ->orWhere('recipient_name', 'like', "%{$request->search}%")
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$request->search}%"));
                });
            })
            ->latest();

        $filename = 'certificates_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'رقم الشهادة',
                'اسم الطالب',
                'البريد الإلكتروني',
                'نوع الشهادة',
                'العنوان',
                'الدورة / الورشة / المسار',
                'القالب',
                'الحالة',
                'حالة الموافقة',
                'تاريخ الإصدار',
                'تاريخ الإنشاء',
            ]);

            $query->chunk(200, function ($certificates) use ($handle) {
                foreach ($certificates as $cert) {
                    $related = $cert->course?->title
                        ?? $cert->workshop?->title
                        ?? $cert->track?->name
                        ?? '';

                    fputcsv($handle, [
                        $cert->certificate_code  ?? '',
                        $cert->recipient_name ?? $cert->user?->name ?? '',
                        $cert->recipient_email ?? $cert->user?->email ?? '',
                        $cert->certificate_type  ?? '',
                        $cert->title             ?? '',
                        $related,
                        $cert->template?->name   ?? '',
                        $cert->status            ?? '',
                        $cert->approval_status   ?? '',
                        $cert->issued_at?->format('Y-m-d') ?? '',
                        $cert->created_at?->format('Y-m-d') ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    // ── Certificate Audit Logs ───────────────────────────────────────────────

    public function logs(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $certificate);

        $logs = ActivityLog::where(function ($q) use ($certificate) {
                $q->where('entity_type', Certificate::class)
                  ->where('entity_id', $certificate->id);
            })
            ->orWhere(function ($q) use ($certificate) {
                // Legacy model_type/model_id columns
                $q->where('model_type', Certificate::class)
                  ->where('model_id', $certificate->id);
            })
            ->with('user')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($log) => [
                'id'         => $log->id,
                'action'     => $log->action,
                'actor'      => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values ?? $log->changes,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }

    // ── Legacy ────────────────────────────────────────────────────────────────

    public function legacyList(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        if (in_array($user->role ?? '', ['admin', 'super_admin', 'tech_admin', 'programs_manager'], true)) {
            return $this->index($request);
        }

        // Everyone else sees their OWN certificates; learning is not a role.
        return app(\App\Http\Controllers\Student\StudentCertificateController::class)->index($request);
    }

    // ── Approvals ─────────────────────────────────────────────────────────────

    public function approvals(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $certificate);

        $certificate->loadMissing(['approvals.approvedBy']);

        $statuses = [];
        foreach ($certificate->approvals as $approval) {
            $statuses[$approval->type] = [
                'status'      => $approval->status,
                'type_label'  => $approval->typeLabel(),
                'approver'    => $approval->approvedBy?->name,
                'notes'       => $approval->notes,
                'actioned_at' => $approval->actioned_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'success'         => true,
            'approval_status' => $certificate->approval_status,
            'approvals'       => $statuses,
        ]);
    }

    public function recordApproval(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        $this->authorize('recordApproval', $certificate);

        $data = $request->validate([
            'type'           => 'required|in:administrative,programs',
            'status'         => 'required|in:approved,rejected',
            'notes'          => 'required_if:status,rejected|nullable|string|max:1000',
            'signature_path' => 'nullable|string|max:500',
            'stamp_path'     => 'nullable|string|max:500',
        ]);

        $oldApprovalStatus = $certificate->approval_status;

        $approvalService = app(\App\Services\CertificateApprovalService::class);
        $approvalService->decide(
            certificate: $certificate,
            type: $data['type'],
            status: $data['status'],
            approver: $request->user(),
            notes: $data['notes'] ?? null,
            signaturePath: $data['signature_path'] ?? null,
            stampPath: $data['stamp_path'] ?? null,
        );

        $certificate->refresh();

        ActivityLogger::log(
            $data['status'] === 'approved' ? 'certificate.approval_granted' : 'certificate.approval_rejected',
            $certificate,
            [],
            $request,
            ['approval_status' => $oldApprovalStatus],
            ['approval_status' => $certificate->approval_status, 'type' => $data['type'], 'notes' => $data['notes'] ?? null],
        );

        return response()->json([
            'success'         => true,
            'message'         => $data['status'] === 'approved' ? 'تمت الموافقة.' : 'تم الرفض.',
            'approval_status' => $certificate->approval_status,
        ]);
    }

    // ── Designer generate ─────────────────────────────────────────────────────

    public function generateDesigner(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        $this->authorize('regenerate', $certificate);

        $path = app(CertificateRenderService::class)->storePdf($certificate);

        ActivityLogger::log('certificate.pdf_generated', $certificate, [], $request, null, [
            'template_id' => $certificate->certificate_template_id,
            'pdf_path'    => $path,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'تم إنشاء ملف الشهادة (PDF).',
            'pdf_path' => null, // never expose the storage path in API responses
        ]);
    }

    // ── Retry failed PDF ──────────────────────────────────────────────────────

    public function retryPdf(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        $this->authorize('regenerate', $certificate);

        if ($certificate->status !== 'generation_failed') {
            return response()->json([
                'success' => false,
                'message' => 'يمكن إعادة المحاولة فقط للشهادات التي فشل إنشاؤها.',
            ], 422);
        }

        // Prevent double-dispatch while a worker is already generating.
        if ($certificate->pdf_generation_status === 'generating') {
            return response()->json([
                'success' => false,
                'message' => 'جاري إنشاء الشهادة بالفعل. يرجى الانتظار حتى تنتهي العملية الحالية.',
            ], 422);
        }

        $certificate->update([
            'status'                => 'pending_generation',
            'pdf_generation_status' => 'pending',
            'pdf_last_error'        => null,
        ]);

        GenerateCertificatePdfJob::dispatch($certificate->fresh(), $certificate->batch_id, false)
            ->onQueue(config('certificates.queue', 'certificates'));

        ActivityLogger::log('certificate.retry_pdf', $certificate, [], $request, null, [
            'certificate_code' => $certificate->certificate_code,
            'triggered_by'     => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم جدولة إعادة محاولة إنشاء الشهادة.',
            'data'    => new CertificateResource($certificate->fresh()->load(['user', 'template'])),
        ]);
    }
}
