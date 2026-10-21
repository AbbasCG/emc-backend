<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FormSubmissionResource;
use App\Models\DynamicForm;
use App\Models\FormShareLink;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\Export\FormExportColumns;
use App\Services\Forms\FormAccessService;
use App\Services\Forms\FormResponseCorrectionService;
use App\Services\Forms\FormResponseHistory;
use App\Services\Forms\FormResponseQuery;
use App\Services\Forms\FormResponseWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The responses of one form for staff: the filtered, paginated table, one response in detail, and the review workflow on it
 * (status, assignment, internal notes, history, corrections).
 *
 * Every action is authorized against the FORM (DynamicFormPolicy -> FormAccessService), and every response is bound to the form
 * in the URL (404 otherwise), so a response of another form or department can never be read or changed through this one.
 *   - reading the table and a response            viewResponses
 *   - status, assignment, notes, history          manageResponses (reviewers). Viewers see the status and the assignee only.
 *   - correcting an answer                        correctResponses (an explicit, separate grant)
 */
class FormResponseController extends Controller
{
    public function __construct(
        private readonly FormAccessService $access,
        private readonly FormResponseWorkflowService $workflow,
        private readonly FormResponseCorrectionService $corrections,
    ) {}

    /** The table: server-side filters, sorting and pagination, plus how many responses each status would show. */
    public function index(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('viewResponses', $form);

        $request->validate(FormResponseQuery::rules() + [
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort'      => ['nullable', Rule::in(FormResponseQuery::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $filters = FormResponseQuery::normalize($request->query(), $request->user());

        $page = FormResponseQuery::sort(
            FormResponseQuery::build($form, $filters)->with(['user', 'version', 'shareLink', 'assignee', 'answers.question']),
            $request->query('sort'), $request->query('direction'),
        )->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        $payload = FormSubmissionResource::collection($page)->response()->getData(true);
        $payload['status_counts'] = FormResponseQuery::statusCounts($form, $filters);
        $payload['applied_filters'] = (object) $filters;

        return response()->json(['success' => true, 'data' => $payload]);
    }

    public function show(Request $request, DynamicForm $form, FormSubmission $submission): JsonResponse
    {
        $this->bind($form, $submission);
        $this->authorize('viewResponses', $form);

        return response()->json(['success' => true, 'data' => $this->present($request, $form, $submission)]);
    }

    /** What the filter and export controls offer: versions, share links, statuses, columns and the people responses can be assigned to. */
    public function options(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('viewResponses', $form);
        $version = $request->query('version');
        $versionId = is_string($version) && ctype_digit($version) ? (int) $version : null;

        $counts = FormSubmission::query()->where('form_id', $form->id)->selectRaw('form_version_id as v, count(*) as c')->groupBy('form_version_id')->pluck('c', 'v');
        $assigned = FormSubmission::query()->where('form_id', $form->id)->whereNotNull('assigned_to')->distinct()->pluck('assigned_to');
        $canReview = $this->access->canManageResponses($request->user(), $form);

        return response()->json(['success' => true, 'data' => [
            'statuses'    => collect(FormSubmission::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => FormSubmission::STATUS_LABELS[$s]])->values(),
            'versions'    => FormVersion::query()->where('form_id', $form->id)->orderByDesc('version_number')->get(['id', 'version_number', 'published_at'])
                ->map(fn ($v) => ['id' => $v->id, 'number' => (int) $v->version_number, 'published_at' => $v->published_at, 'responses' => (int) ($counts[$v->id] ?? 0)])->values(),
            'links'       => FormShareLink::query()->where('form_id', $form->id)->orderBy('id')->get(['id', 'label'])->map(fn ($l) => ['id' => $l->id, 'label' => $l->label])->values(),
            'columns'     => FormExportColumns::forForm($form, $versionId),
            'assignees'   => User::query()->whereIn('id', $assigned)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            // Only reviewers can assign, so only they are given the list of who may be assigned.
            'assignable'  => $canReview ? $this->access->assignableEmployees($form)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values() : [],
            'unversioned' => (int) ($counts[''] ?? $counts[0] ?? 0),
        ]]);
    }

    public function updateStatus(Request $request, DynamicForm $form, FormSubmission $submission): JsonResponse
    {
        $this->bind($form, $submission);
        $this->authorize('manageResponses', $form);

        $data = $request->validate(['status' => ['required', Rule::in([...FormSubmission::STATUSES, ...array_keys(FormSubmission::LEGACY_STATUS)])]], [
            'status.required' => 'اختر حالة الرد.',
            'status.in'       => 'حالة الرد غير صالحة.',
        ]);
        $result = $this->workflow->changeStatus($submission, $request->user(), $data['status']);

        return response()->json(['success' => true, 'message' => 'Status updated.', 'changed' => $result['changed'], 'data' => $this->present($request, $form, $submission)]);
    }

    public function assign(Request $request, DynamicForm $form, FormSubmission $submission): JsonResponse
    {
        $this->bind($form, $submission);
        $this->authorize('manageResponses', $form);

        $data = $request->validate(['assignee_id' => ['present', 'nullable', 'integer']], ['assignee_id.present' => 'حدّد الموظف أو أرسل null لإلغاء الإسناد.']);
        $result = $this->workflow->assign($submission->setRelation('form', $form), $request->user(), $data['assignee_id'] !== null ? (int) $data['assignee_id'] : null);

        return response()->json(['success' => true, 'changed' => $result['changed'], 'data' => $this->present($request, $form, $submission)]);
    }

    public function notes(DynamicForm $form, FormSubmission $submission): JsonResponse
    {
        $this->bind($form, $submission);
        $this->authorize('manageResponses', $form);

        return response()->json(['success' => true, 'data' => FormResponseHistory::notes($submission)]);
    }

    public function addNote(Request $request, DynamicForm $form, FormSubmission $submission): JsonResponse
    {
        $this->bind($form, $submission);
        $this->authorize('manageResponses', $form);

        $data = $request->validate(['body' => ['required', 'string']], ['body.required' => 'اكتب نص الملاحظة.']);
        $this->workflow->addNote($submission, $request->user(), $data['body']);

        return response()->json(['success' => true, 'data' => FormResponseHistory::notes($submission)], 201);
    }

    /** The response's own history, including every correction's previous and new value. Reviewers only. */
    public function history(DynamicForm $form, FormSubmission $submission): JsonResponse
    {
        $this->bind($form, $submission);
        $this->authorize('manageResponses', $form);

        return response()->json(['success' => true, 'data' => FormResponseHistory::present($submission, $this->corrections->questionsOf($form, $submission)->keyBy('id'))]);
    }

    /** Corrects answers, keeping the original and recording who, when, why and what changed. */
    public function correct(Request $request, DynamicForm $form, FormSubmission $submission): JsonResponse
    {
        $this->bind($form, $submission);
        $this->authorize('correctResponses', $form);

        $data = $request->validate([
            'corrections' => ['required', 'array', 'min:1', 'max:100'],
            'reason'      => ['nullable', 'string', 'max:500'],
        ], [
            'corrections.required' => 'لم تُحدَّد أي إجابة للتصحيح.',
            'corrections.min'      => 'لم تُحدَّد أي إجابة للتصحيح.',
            'reason.max'           => 'سبب التصحيح أطول من المسموح (500 حرف).',
        ]);
        $result = $this->corrections->correct($form, $submission, $request->user(), $data['corrections'], $data['reason'] ?? null, $request);

        return response()->json(['success' => true, 'changed' => $result['changed'], 'data' => $this->present($request, $form, $submission->refresh())]);
    }

    /** A response of another form is not found, whatever the person may do with their own forms. */
    private function bind(DynamicForm $form, FormSubmission $submission): void
    {
        abort_if((int) $submission->form_id !== (int) $form->id, 404);
    }

    /** @return array<string, mixed> */
    private function present(Request $request, DynamicForm $form, FormSubmission $submission): array
    {
        $submission->refresh()->load(['user', 'version', 'shareLink', 'assignee', 'answers.question']);
        $user = $request->user();

        return (new FormSubmissionResource($submission))->resolve($request) + ['abilities' => [
            'review'  => $this->access->canManageResponses($user, $form),
            'correct' => $this->access->canCorrectResponses($user, $form),
        ]];
    }
}
