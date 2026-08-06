<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\LearningPath;
use App\Models\ProgramFinanceApproval;
use App\Services\ProgramFinanceApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProgramFinanceApprovalController extends Controller
{
    public function __construct(private ProgramFinanceApprovalService $service) {}

    // ── GET /api/admin/finance/program-approvals ──────────────────────────────
    // Finance manager: list all pending (or filtered) program approval requests
    public function index(Request $request): JsonResponse
    {
        $this->ensureCanReview($request);

        $status = $request->query('status', 'pending');

        $query = ProgramFinanceApproval::with([
            'submitter:id,name,email,role',
            'reviewer:id,name,role',
        ])->when($status !== 'all', fn ($q) => $q->where('status', $status));

        if ($request->filled('approvable_type')) {
            $query->where('approvable_type', 'App\\Models\\' . $request->query('approvable_type'));
        }
        if ($request->filled('search')) {
            // We need to join to polymorphic targets — handled below via subquery IDs
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $items   = $query->latest('submitted_at')->paginate($perPage);

        // Eager-load the program details for each approval
        $grouped = $items->getCollection()->groupBy('approvable_type');
        $programs = collect();

        foreach ($grouped as $type => $approvals) {
            $ids   = $approvals->pluck('approvable_id')->unique();
            $model = $this->resolveModel($type);
            if (! $model) {
                continue;
            }
            $rows = $model::with(['creator:id,name,email,role'])
                ->whereIn('id', $ids)
                ->withTrashed()
                ->get()
                ->keyBy('id');
            $programs = $programs->merge($rows->map(fn ($r) => ['type' => $type, 'program' => $r])->keyBy(fn ($v) => $type . ':' . $v['program']->id));
        }

        $data = $items->getCollection()->map(function (ProgramFinanceApproval $a) use ($programs) {
            $key  = $a->approvable_type . ':' . $a->approvable_id;
            $entry = $programs->get($key); $prog = $entry ? $entry['program'] : null;

            return $this->formatApproval($a, $prog);
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'total'        => $items->total(),
                'per_page'     => $items->perPage(),
            ],
            'summary' => [
                'pending'  => ProgramFinanceApproval::where('status', 'pending')->count(),
                'approved' => ProgramFinanceApproval::where('status', 'approved')->count(),
                'rejected' => ProgramFinanceApproval::where('status', 'rejected')->count(),
            ],
        ]);
    }

    // ── POST /api/admin/courses/{course}/submit-for-finance ───────────────────
    public function submitCourse(Request $request, Course $course): JsonResponse
    {
        $this->ensureCanSubmit($request);

        if (! $course->isPaid()) {
            return response()->json(['success' => false, 'message' => 'البرنامج مجاني ولا يحتاج لمراجعة مالية.'], 422);
        }
        if ($course->finance_approval_status === 'approved') {
            return response()->json(['success' => false, 'message' => 'البرنامج معتمد بالفعل.'], 422);
        }

        $approval = $this->service->submit($course, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال البرنامج للمراجعة المالية.',
            'data'    => $this->formatApproval($approval, $course),
        ]);
    }

    // ── POST /api/admin/learning-paths/{path}/submit-for-finance ─────────────
    public function submitLearningPath(Request $request, LearningPath $learningPath): JsonResponse
    {
        $this->ensureCanSubmit($request);

        if (! $learningPath->isPaid()) {
            return response()->json(['success' => false, 'message' => 'المسار التعليمي مجاني ولا يحتاج لمراجعة مالية.'], 422);
        }
        if ($learningPath->finance_approval_status === 'approved') {
            return response()->json(['success' => false, 'message' => 'المسار التعليمي معتمد بالفعل.'], 422);
        }

        $approval = $this->service->submit($learningPath, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال المسار التعليمي للمراجعة المالية.',
            'data'    => $this->formatApproval($approval, $learningPath),
        ]);
    }

    // ── POST /api/admin/finance/program-approvals/{approval}/approve ──────────
    public function approve(Request $request, ProgramFinanceApproval $programFinanceApproval): JsonResponse
    {
        $this->ensureCanReview($request);

        $data = $request->validate([
            'review_note' => 'nullable|string|max:1000',
        ]);

        if ($programFinanceApproval->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'هذا الطلب ليس في حالة انتظار.'], 422);
        }

        $program  = $programFinanceApproval->approvable;
        $approval = $this->service->approve($program, $request->user(), $data['review_note'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'تم اعتماد البرنامج مالياً.',
            'data'    => $this->formatApproval($approval, $program),
        ]);
    }

    // ── POST /api/admin/finance/program-approvals/{approval}/reject ───────────
    public function reject(Request $request, ProgramFinanceApproval $programFinanceApproval): JsonResponse
    {
        $this->ensureCanReview($request);

        $data = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
            'review_note'      => 'nullable|string|max:1000',
        ]);

        if ($programFinanceApproval->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'هذا الطلب ليس في حالة انتظار.'], 422);
        }

        $program  = $programFinanceApproval->approvable;
        $approval = $this->service->reject($program, $request->user(), $data['rejection_reason'], $data['review_note'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'تم رفض البرنامج.',
            'data'    => $this->formatApproval($approval, $program),
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function ensureCanReview(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'finance_manager', 'super_admin', 'tech_admin'])) {
            abort(403, 'يجب أن تكون مديراً مالياً للقيام بهذا الإجراء.');
        }
    }

    private function ensureCanSubmit(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, [
            'super_admin', 'tech_admin', 'admin', 'executive_admin',
            'programs_manager', 'finance_manager',
        ])) {
            abort(403, 'غير مصرح لك بإرسال البرامج للمراجعة المالية.');
        }
    }

    private function resolveModel(string $fqn): ?string
    {
        return match ($fqn) {
            'App\\Models\\Course'       => Course::class,
            'App\\Models\\LearningPath' => LearningPath::class,
            default                     => null,
        };
    }

    private function formatApproval(ProgramFinanceApproval $a, mixed $program): array
    {
        $programData = null;
        if ($program) {
            $creator = $program->relationLoaded('creator') || $program->relationLoaded('createdBy')
                ? ($program->creator ?? $program->createdBy)
                : null;
            $programData = [
                'id'    => $program->id,
                'title' => $program->title,
                'type'  => class_basename($program),
                'program_type' => $program->program_type ?? null,
                'price' => (float) ($program->price ?? 0),
                'currency' => strtoupper($program->currency ?? 'EUR'),
                'status' => $program->status,
                'created_by' => $creator ? [
                    'id'    => $creator->id,
                    'name'  => $creator->name,
                    'email' => $creator->email,
                    'role'  => $creator->role,
                ] : null,
            ];
        }

        return [
            'id'               => $a->id,
            'status'           => $a->status,
            'approvable_type'  => class_basename($a->approvable_type),
            'approvable_id'    => $a->approvable_id,
            'submitted_at'     => $a->submitted_at?->toIso8601String(),
            'reviewed_at'      => $a->reviewed_at?->toIso8601String(),
            'review_note'      => $a->review_note,
            'rejection_reason' => $a->rejection_reason,
            'price_snapshot'   => $a->price_snapshot ? (float) $a->price_snapshot : null,
            'currency_snapshot' => $a->currency_snapshot,
            'submitter'        => $a->relationLoaded('submitter') && $a->submitter ? [
                'id'    => $a->submitter->id,
                'name'  => $a->submitter->name,
                'email' => $a->submitter->email,
                'role'  => $a->submitter->role,
            ] : null,
            'reviewer'         => $a->relationLoaded('reviewer') && $a->reviewer ? [
                'id'   => $a->reviewer->id,
                'name' => $a->reviewer->name,
                'role' => $a->reviewer->role,
            ] : null,
            'program'          => $programData,
        ];
    }
}
