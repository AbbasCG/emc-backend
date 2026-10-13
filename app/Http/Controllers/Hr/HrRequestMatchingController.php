<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrRequest;
use App\Models\VolunteerOpportunity;
use App\Services\HrRequestAccessService;
use App\Services\VolunteerMatchingService;
use App\Services\VolunteerOpportunityDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Smart volunteer matching and draft opportunities for an HR incoming request.
 *
 * Decision support only: matching reads and scores, drafts are saved as drafts. Nothing here
 * assigns or contacts a volunteer, sends an email, changes a volunteer application or
 * publishes anything. Every action is checked server-side by HrRequestAccessService.
 */
class HrRequestMatchingController extends Controller
{
    public function __construct(
        private readonly HrRequestAccessService $access,
        private readonly VolunteerMatchingService $matching,
        private readonly VolunteerOpportunityDraftService $drafts,
    ) {}

    // GET /api/admin/hr-requests/{hrRequest}/volunteer-matches?min_score=45
    public function matches(Request $request, HrRequest $hrRequest): JsonResponse
    {
        $user = $request->user();
        if (! $this->access->canSee($user, $hrRequest) || ! $this->access->canMatch($user)) {
            return $this->forbidden('ليس لديك صلاحية للبحث عن مرشحين.');
        }

        $v = $request->validate(['min_score' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100']], ['min_score.*' => 'الحد الأدنى للدرجة يجب أن يكون رقمًا بين 0 و100.']);
        $result = $this->matching->match($hrRequest, (int) ($v['min_score'] ?? VolunteerMatchingService::DEFAULT_MIN_SCORE));

        return response()->json(['success' => true, 'data' => $result['candidates'], 'meta' => $result['meta']]);
    }

    // GET /api/admin/hr-requests/{hrRequest}/opportunity-draft/suggestion — auto-filled form values, nothing saved
    public function suggestion(Request $request, HrRequest $hrRequest): JsonResponse
    {
        if (! $this->canDraft($request, $hrRequest)) {
            return $this->forbidden('ليس لديك صلاحية لإنشاء مسودة فرصة.');
        }

        $existing = $this->drafts->existing($hrRequest);

        return response()->json([
            'success' => true,
            'data' => $this->drafts->suggest($hrRequest),
            'existing_draft' => $existing ? $this->drafts->present($existing) : null,
        ]);
    }

    // POST /api/admin/hr-requests/{hrRequest}/opportunity-draft
    public function store(Request $request, HrRequest $hrRequest): JsonResponse
    {
        if (! $this->canDraft($request, $hrRequest)) {
            return $this->forbidden('ليس لديك صلاحية لإنشاء مسودة فرصة.');
        }

        if ($existing = $this->drafts->existing($hrRequest)) {
            return response()->json([
                'success' => false,
                'message' => 'توجد مسودة فرصة لهذا الطلب بالفعل. عدّلها بدل إنشاء مسودة جديدة.',
                'data' => $this->drafts->present($existing),
            ], 409);
        }

        $draft = $this->drafts->create($hrRequest, $this->validated($request), $request->user());

        return response()->json(['success' => true, 'message' => 'تم حفظ مسودة الفرصة.', 'data' => $this->drafts->present($draft)], 201);
    }

    // PUT /api/admin/hr-requests/{hrRequest}/opportunity-draft
    public function update(Request $request, HrRequest $hrRequest): JsonResponse
    {
        if (! $this->canDraft($request, $hrRequest)) {
            return $this->forbidden('ليس لديك صلاحية لتعديل مسودة الفرصة.');
        }

        $draft = $this->drafts->existing($hrRequest);
        if (! $draft || ! $draft->isDraft()) {
            return response()->json(['success' => false, 'message' => 'لا توجد مسودة قابلة للتعديل لهذا الطلب.'], 404);
        }

        $updated = $this->drafts->update($draft, $this->validated($request), $request->user());

        return response()->json(['success' => true, 'message' => 'تم تحديث مسودة الفرصة.', 'data' => $this->drafts->present($updated)]);
    }

    private function canDraft(Request $request, HrRequest $hrRequest): bool
    {
        return $this->access->canSee($request->user(), $hrRequest) && $this->access->canManage($request->user());
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
            'location_mode' => ['nullable', Rule::in(VolunteerOpportunity::LOCATION_MODES)],
            'start_date' => ['nullable', 'date'],
            'application_instructions' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ], [
            'title.required' => 'عنوان الفرصة مطلوب.',
            'title.min' => 'عنوان الفرصة قصير جدًا.',
            'title.max' => 'عنوان الفرصة طويل جدًا (الحد 200 حرف).',
            'summary.required' => 'الوصف المختصر مطلوب.',
            'summary.min' => 'الوصف المختصر قصير جدًا.',
            'volunteers_needed.integer' => 'العدد المطلوب يجب أن يكون رقمًا صحيحًا.',
            'volunteers_needed.min' => 'العدد المطلوب يجب ألا يقل عن 1.',
            'location_mode.in' => 'نمط المكان غير صالح.',
            'start_date.date' => 'تاريخ البدء غير صالح.',
            'department_id.exists' => 'القسم المحدد غير موجود.',
        ]);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
