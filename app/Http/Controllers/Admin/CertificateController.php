<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Jobs\GenerateCertificatePdfJob;
use App\Models\Certificate;
use App\Services\CertificateEligibilityService;
use App\Services\CertificateService;
use App\Support\CertificateTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CertificateController extends Controller
{
    private const ADMIN_ROLES = ['admin', 'super_admin', 'tech_admin', 'programs_manager'];

    public function __construct(
        private readonly CertificateService $certificateService,
        private readonly CertificateEligibilityService $eligibilityService,
    ) {}

    // ── Existing endpoints (preserved) ────────────────────────────────────────

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $certificates = Certificate::with(['user', 'course', 'track', 'workshop', 'approvedBy', 'template', 'issuedBy'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->certificate_type, fn ($q) => $q->where('certificate_type', $request->certificate_type))
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->batch_id, fn ($q) => $q->where('batch_id', $request->batch_id))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($q2) use ($request) {
                    $q2->where('title', 'like', "%{$request->search}%")
                        ->orWhere('certificate_code', 'like', "%{$request->search}%")
                        ->orWhere('verification_code', 'like', "%{$request->search}%")
                        ->orWhere('recipient_name', 'like', "%{$request->search}%")
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$request->search}%"));
                });
            })
            ->latest()
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data'    => CertificateResource::collection($certificates)->response()->getData(true),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'user_id'                 => 'required|exists:users,id',
            'course_id'               => 'nullable|exists:courses,id',
            'track_id'                => 'nullable|exists:tracks,id',
            'workshop_id'             => 'nullable|exists:workshops,id',
            'certificate_type'        => 'required|string',
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

        return response()->json([
            'success' => true,
            'data'    => new CertificateResource($certificate->load(['user', 'course', 'track', 'workshop', 'template'])),
        ], 201);
    }

    public function show(Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new CertificateResource(
                $certificate->load(['user', 'course', 'track', 'workshop', 'approvedBy', 'template', 'issuedBy', 'batch'])
            ),
        ]);
    }

    public function approve(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        if ($certificate->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Only pending certificates can be approved.'], 422);
        }

        $certificate = $this->certificateService->approve($certificate, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Certificate approved.',
            'data'    => new CertificateResource($certificate->load(['user', 'approvedBy'])),
        ]);
    }

    public function issue(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        if (! in_array($certificate->status, ['pending', 'approved'])) {
            return response()->json(['success' => false, 'message' => 'Certificate cannot be issued in its current state.'], 422);
        }

        $certificate = $this->certificateService->issue($certificate, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Certificate issued.',
            'data'    => new CertificateResource($certificate->load(['user', 'approvedBy'])),
        ]);
    }

    public function revoke(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        if ($certificate->status === 'revoked') {
            return response()->json(['success' => false, 'message' => 'Certificate is already revoked.'], 422);
        }

        $request->validate([
            'revoke_reason' => 'nullable|string|max:1000',
        ]);

        $certificate->update([
            'status'        => 'revoked',
            'revoked_at'    => now(),
            'revoke_reason' => $request->revoke_reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Certificate revoked.',
            'data'    => new CertificateResource($certificate),
        ]);
    }

    // ── New endpoints ──────────────────────────────────────────────────────────

    public function regenerate(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        GenerateCertificatePdfJob::dispatch($certificate, $certificate->batch_id);

        return response()->json([
            'success' => true,
            'message' => 'Certificate PDF regeneration queued.',
            'data'    => new CertificateResource($certificate),
        ]);
    }

    public function download(Request $request, Certificate $certificate): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        if ($certificate->status === 'pending_generation') {
            return response()->json([
                'success' => false,
                'message' => 'ملف الشهادة لا يزال قيد الإنشاء.',
            ], 409);
        }

        if (! $certificate->pdf_path || ! Storage::disk('local')->exists($certificate->pdf_path)) {
            return response()->json([
                'success' => false,
                'message' => 'ملف الشهادة غير جاهز بعد. حاول لاحقاً.',
            ], 404);
        }

        $content  = Storage::disk('local')->get($certificate->pdf_path);
        $filename = 'certificate-' . ($certificate->certificate_code ?? $certificate->id) . '.html';

        return response($content, 200, [
            'Content-Type'        => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    public function bulkIssue(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'user_ids'           => 'nullable|array|min:1',
            'user_ids.*'         => 'required|integer|exists:users,id',
            'recipient_ids'      => 'nullable|array|min:1',
            'recipient_ids.*'    => 'required|integer|exists:users,id',
            'type'               => 'nullable|string',
            'certificate_type'   => 'nullable|string',
            'template_id'        => 'nullable|exists:certificate_templates,id',
            'related_type'       => 'nullable|string',
            'related_id'         => 'nullable|integer',
            'title'              => 'nullable|string|max:255',
            'notes'              => 'nullable|string',
            'metadata'           => 'nullable|array',
            'override'           => 'nullable|boolean',
        ]);

        $userIds = $request->input('user_ids') ?? $request->input('recipient_ids');
        if (! is_array($userIds) || count($userIds) < 1) {
            throw ValidationException::withMessages([
                'user_ids' => ['يجب اختيار طالب واحد على الأقل.'],
            ]);
        }

        $type = $request->input('type') ?? $request->input('certificate_type');
        if (! $type || ! CertificateTypes::isValid($type)) {
            throw ValidationException::withMessages([
                'type' => ['نوع الشهادة مطلوب.'],
            ]);
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

        $batch = $this->certificateService->issueBulk(
            $data['user_ids'],
            $data,
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء طلب إصدار الشهادات بنجاح.',
            'data'    => [
                'batch_id'              => $batch->id,
                'batch_code'            => $batch->batch_code,
                'total_recipients'      => $batch->total_recipients,
                'certificates_created'  => $batch->total_recipients,
                'status'                => $batch->status,
            ],
        ], 202);
    }

    public function eligibility(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'related_type'     => 'required|string',
            'related_id'       => 'nullable|integer',
            'type'             => 'nullable|string',
            'certificate_type' => 'nullable|string',
        ]);

        $relatedType = $request->related_type;
        $relatedId   = (int) ($request->related_id ?? 0);
        $certificateType = $request->certificate_type ?? $request->type ?? 'course_completion';

        // Return early with empty state if no valid entity selected
        if ($relatedId <= 0) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'summary'  => ['total' => 0, 'eligible' => 0, 'ineligible' => 0, 'already_issued' => 0],
                    'students' => [],
                ],
            ]);
        }

        $options = ['certificate_type' => $certificateType];

        $eligibility = match ($relatedType) {
            'course', 'App\\Models\\Course'                             => $this->eligibilityService->forCourse($relatedId, $options),
            'workshop', 'App\\Models\\Workshop'                         => $this->eligibilityService->forWorkshop($relatedId, $options),
            'learning_path', 'track', 'App\\Models\\Track',
            'App\\Models\\LearningPath'                                 => $this->eligibilityService->forLearningPath($relatedId, $options),
            default                                                     => [],
        };

        $eligible      = collect($eligibility)->where('is_eligible', true)->count();
        $alreadyIssued = collect($eligibility)->where('already_issued', true)->count();
        $ineligible    = count($eligibility) - $eligible;

        return response()->json([
            'success' => true,
            'data'    => [
                'summary' => [
                    'total'          => count($eligibility),
                    'eligible'       => $eligible,
                    'ineligible'     => $ineligible,
                    'already_issued' => $alreadyIssued,
                ],
                'students' => collect($eligibility)->map(fn ($item) => [
                    'user_id'                => $item['user_id'] ?? $item['user']?->id,
                    'name'                   => $item['name'] ?? $item['user']?->name,
                    'email'                  => $item['email'] ?? $item['user']?->email,
                    'user'                   => $item['user'] ? [
                        'id'    => $item['user']->id,
                        'name'  => $item['user']->name,
                        'email' => $item['user']->email,
                    ] : ($item['user_id'] ? null : [
                        'id'    => 0,
                        'name'  => $item['name'] ?? '—',
                        'email' => $item['email'] ?? '',
                    ]),
                    'progress'               => $item['progress'],
                    'progress_percentage'    => $item['progress'],
                    'progress_pct'           => $item['progress'],
                    'attendance'             => $item['attendance'],
                    'attendance_percentage'  => $item['attendance'],
                    'attendance_pct'         => $item['attendance'],
                    'assignments'            => $item['assignments'],
                    'assignments_completed'  => $item['assignments_completed'] ?? 0,
                    'assignments_total'      => $item['assignments_total'] ?? 0,
                    'is_eligible'            => $item['is_eligible'],
                    'eligible'               => $item['is_eligible'],
                    'reason'                 => $item['reason'],
                    'already_issued'         => $item['already_issued'] ?? false,
                    'certificate_status'       => $item['certificate_status'] ?? null,
                    'existing_certificate'   => ($item['existing_certificate'] ?? null)
                        ? [
                            'id'               => $item['existing_certificate']->id,
                            'status'           => $item['existing_certificate']->status,
                            'certificate_code' => $item['existing_certificate']->certificate_code,
                        ]
                        : null,
                ])->values(),
            ],
        ]);
    }

    /** Legacy GET /api/certificates — routes to admin or student list based on role. */
    public function legacyList(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        if (in_array($user->role ?? '', self::ADMIN_ROLES, true)) {
            return $this->index($request);
        }

        if (($user->role ?? '') === 'student') {
            return app(\App\Http\Controllers\Student\StudentCertificateController::class)->index($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Use /api/student/certificates or /api/admin/certificates',
        ], 400);
    }

    public function stats(Request $request): \Illuminate\Http\JsonResponse
    {
        $total       = Certificate::count();
        $byStatus    = Certificate::selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');
        $byType      = Certificate::selectRaw('certificate_type, count(*) as count')->groupBy('certificate_type')->pluck('count', 'certificate_type');
        $recentCount = Certificate::where('created_at', '>=', now()->subDays(30))->count();
        $issuedToday = Certificate::whereDate('issued_at', today())->count();

        $issuedThisMonth = Certificate::where('status', 'issued')
            ->whereMonth('issued_at', now()->month)
            ->whereYear('issued_at', now()->year)
            ->count();

        $recent = Certificate::with(['user', 'course', 'workshop', 'track', 'template'])
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'             => $total,
                'issued_this_month' => $issuedThisMonth,
                'pending'           => (int) ($byStatus['pending'] ?? 0)
                    + (int) ($byStatus['approved'] ?? 0)
                    + (int) ($byStatus['pending_generation'] ?? 0),
                'revoked'           => (int) ($byStatus['revoked'] ?? 0),
                'by_type'           => $byType,
                'recent'            => CertificateResource::collection($recent),
                'by_status'         => $byStatus,
                'recent_30d'        => $recentCount,
                'issued_today'      => $issuedToday,
            ],
        ]);
    }
}
