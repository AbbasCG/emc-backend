<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrRequest;
use App\Models\VolunteerOpportunity;
use App\Services\HrRequestAccessService;
use App\Services\VolunteerApplicantService;
use App\Services\VolunteerOpportunityDraftService;
use App\Services\VolunteerOpportunityPublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administrative management of volunteer opportunities (draft, edit, publish, unpublish, close,
 * applicants).
 *
 * Authorization is HrRequestAccessService's: managing and publishing need `manage_team`;
 * listing is read-only for the wider management roles; applicant data additionally needs
 * `view_volunteers`. The public site never calls this controller.
 */
class VolunteerOpportunityController extends Controller
{
    public function __construct(
        private readonly HrRequestAccessService $access,
        private readonly VolunteerOpportunityDraftService $drafts,
        private readonly VolunteerOpportunityPublishingService $publishing,
        private readonly VolunteerApplicantService $applicants,
    ) {}

    // GET /api/admin/volunteer-opportunities?status=&q=&department_id=&page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->access->canViewAll($request->user())) {
            return $this->forbidden('ليس لديك صلاحية لعرض الفرص التطوعية.');
        }

        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(array_keys(VolunteerOpportunity::STATUS_LABELS))],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = VolunteerOpportunity::query()
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', (int) $request->query('department_id')))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.trim((string) $request->query('q')).'%'));

        $counts = (clone $base)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $page = (clone $base)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->with(['department:id,name,name_ar', 'incomingRequest:id,request_type,department_id'])
            ->withCount('applications')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($o) => $this->present($o))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'stats' => [
                'total' => (int) $counts->sum(),
                'draft' => (int) ($counts['draft'] ?? 0),
                'published' => (int) ($counts['published'] ?? 0),
                'closed' => (int) ($counts['closed'] ?? 0),
            ],
            'capabilities' => $this->access->capabilities($request->user()),
        ]);
    }

    // GET /api/admin/volunteer-opportunities/{opportunity}
    public function show(Request $request, VolunteerOpportunity $opportunity): JsonResponse
    {
        if (! $this->access->canViewAll($request->user())) {
            return $this->forbidden('ليس لديك صلاحية لعرض الفرص التطوعية.');
        }

        $opportunity->load(['department:id,name,name_ar', 'incomingRequest:id,request_type,department_id'])->loadCount('applications');

        return response()->json(['success' => true, 'data' => $this->present($opportunity), 'capabilities' => $this->access->capabilities($request->user())]);
    }

    // POST /api/admin/volunteer-opportunities — an opportunity created on its own (no incoming request)
    public function store(Request $request): JsonResponse
    {
        if (! $this->access->canManage($request->user())) {
            return $this->forbidden('ليس لديك صلاحية لإنشاء فرصة تطوعية.');
        }

        $created = $this->drafts->createIndependent($this->validated($request), $request->user());

        return response()->json(['success' => true, 'message' => 'تم حفظ الفرصة كمسودة.', 'data' => $this->present($created->loadCount('applications'))], 201);
    }

    // PUT /api/admin/volunteer-opportunities/{opportunity}
    public function update(Request $request, VolunteerOpportunity $opportunity): JsonResponse
    {
        if (! $this->access->canManage($request->user())) {
            return $this->forbidden('ليس لديك صلاحية لتعديل الفرصة.');
        }
        if ($opportunity->status === VolunteerOpportunity::STATUS_ARCHIVED) {
            return response()->json(['success' => false, 'message' => 'لا يمكن تعديل فرصة مؤرشفة.'], 409);
        }

        $before = $this->snapshot($opportunity);
        $updated = $this->drafts->update($opportunity, $this->validated($request), $request->user());

        // An edit must never leave a PUBLISHED opportunity without its mandatory content.
        if ($updated->status === VolunteerOpportunity::STATUS_PUBLISHED) {
            $missing = $this->publishing->missing($updated);
            if ($missing !== []) {
                $this->drafts->update($updated, $before, $request->user());

                return response()->json(['success' => false, 'message' => 'لا يمكن حفظ التعديل لأن الفرصة منشورة وبياناتها الأساسية ناقصة.', 'errors' => array_map(fn ($m) => [$m], $missing)], 422);
            }
        }

        return response()->json(['success' => true, 'message' => 'تم تحديث الفرصة.', 'data' => $this->present($updated->loadCount('applications'))]);
    }

    public function publish(Request $request, VolunteerOpportunity $opportunity): JsonResponse
    {
        return $this->transition($request, $opportunity, 'نشر الفرصة', fn ($o, $u) => $this->publishing->publish($o, $u), 'تم نشر الفرصة. أصبحت ظاهرة للزوار.');
    }

    public function unpublish(Request $request, VolunteerOpportunity $opportunity): JsonResponse
    {
        return $this->transition($request, $opportunity, 'إلغاء النشر', fn ($o, $u) => $this->publishing->unpublish($o, $u), 'تم إلغاء النشر. عادت الفرصة مسودة.');
    }

    public function close(Request $request, VolunteerOpportunity $opportunity): JsonResponse
    {
        return $this->transition($request, $opportunity, 'إغلاق الفرصة', fn ($o, $u) => $this->publishing->close($o, $u), 'تم إغلاق الفرصة. لن تُقبل طلبات جديدة وتبقى الطلبات الحالية متاحة للمراجعة.');
    }

    // GET /api/admin/volunteer-opportunities/{opportunity}/applicants
    public function applicantsOf(Request $request, VolunteerOpportunity $opportunity): JsonResponse
    {
        if (! $this->access->canMatch($request->user())) {
            return $this->forbidden('ليس لديك صلاحية لعرض المتقدمين.');
        }

        return response()->json(['success' => true, 'data' => $this->applicants->forOpportunities([$opportunity->id])]);
    }

    // GET /api/admin/hr-requests/{hrRequest}/applicants — incoming request -> its opportunity -> applications
    public function applicantsOfRequest(Request $request, HrRequest $hrRequest): JsonResponse
    {
        $user = $request->user();
        if (! $this->access->canSee($user, $hrRequest) || ! $this->access->canMatch($user)) {
            return $this->forbidden('ليس لديك صلاحية لعرض المتقدمين.');
        }

        // The opportunity is found through the stored foreign key, never by comparing text.
        $opportunity = VolunteerOpportunity::query()->where('incoming_request_id', $hrRequest->id)->first();

        return response()->json([
            'success' => true,
            'data' => $this->applicants->forOpportunities($opportunity ? [$opportunity->id] : []) + [
                'opportunity' => $opportunity ? [
                    'id' => $opportunity->id,
                    'title' => $opportunity->title,
                    'status' => $opportunity->status,
                    'status_label' => VolunteerOpportunity::STATUS_LABELS[$opportunity->status] ?? 'غير محدد',
                    'accepts_applications' => $opportunity->acceptsApplications(),
                ] : null,
            ],
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param callable(VolunteerOpportunity, \App\Models\User): VolunteerOpportunity $do */
    private function transition(Request $request, VolunteerOpportunity $opportunity, string $what, callable $do, string $message): JsonResponse
    {
        if (! $this->access->canManage($request->user())) {
            return $this->forbidden("ليس لديك صلاحية: {$what}.");
        }

        $result = $do($opportunity, $request->user());

        return response()->json(['success' => true, 'message' => $message, 'data' => $this->present($result->load(['department:id,name,name_ar', 'incomingRequest:id,request_type,department_id'])->loadCount('applications'))]);
    }

    /** @return array<string, mixed> */
    private function present(VolunteerOpportunity $o): array
    {
        $base = $this->drafts->present($o);
        $request = $o->relationLoaded('incomingRequest') ? $o->incomingRequest : null;
        $dept = $o->relationLoaded('department') ? $o->department : null;

        return $base + [
            'department_name' => $dept ? ($dept->name_ar ?: $dept->name) : null,
            'incoming_request' => $o->incoming_request_id ? ['id' => $o->incoming_request_id, 'title' => $request?->request_type] : null,
            'applicants_count' => (int) ($o->applications_count ?? 0),
            'accepts_applications' => $o->acceptsApplications(),
            'missing_for_publish' => $this->publishing->missing($o),
        ];
    }

    /** The editable values as they were before an edit, to roll back a rejected edit. @return array<string, mixed> */
    private function snapshot(VolunteerOpportunity $o): array
    {
        return $o->only(['title', 'summary', 'responsibilities', 'required_skills', 'preferred_skills', 'volunteers_needed', 'availability', 'duration', 'location_mode', 'start_date', 'closes_at', 'application_instructions', 'department_id']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'summary' => ['required', 'string', 'min:10', 'max:2000'],
            'responsibilities' => ['nullable', 'array', 'max:15'],
            'responsibilities.*' => ['nullable', 'string', 'max:300'],
            'required_skills' => ['nullable', 'array', 'max:20'],
            'required_skills.*' => ['nullable', 'string', 'max:100'],
            'preferred_skills' => ['nullable', 'array', 'max:20'],
            'preferred_skills.*' => ['nullable', 'string', 'max:100'],
            'volunteers_needed' => ['nullable', 'integer', 'min:1', 'max:500'],
            'availability' => ['nullable', 'string', 'max:255'],
            'duration' => ['nullable', 'string', 'max:100'],
            'location_mode' => ['nullable', Rule::in(VolunteerOpportunity::LOCATION_MODES)],
            'start_date' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date'],
            'application_instructions' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ], [
            'title.required' => 'عنوان الفرصة مطلوب.',
            'title.min' => 'عنوان الفرصة قصير جدًا.',
            'summary.required' => 'الوصف المختصر مطلوب.',
            'summary.min' => 'الوصف المختصر قصير جدًا.',
            'volunteers_needed.min' => 'العدد المطلوب يجب ألا يقل عن 1.',
            'location_mode.in' => 'نمط المكان غير صالح.',
            'start_date.date' => 'تاريخ البدء غير صالح.',
            'closes_at.date' => 'آخر موعد للتقديم غير صالح.',
            'department_id.exists' => 'القسم المحدد غير موجود.',
        ]);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
