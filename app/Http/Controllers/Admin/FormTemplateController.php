<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicForm;
use App\Models\FormTemplate;
use App\Services\DepartmentAccessService;
use App\Services\Forms\FormAccessService;
use App\Services\Forms\Templates\FormTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The template library (Smart Forms Phase 6): browse, preview, use a template, save a form as a template, manage your own
 * templates, and duplicate a form. Visibility and edit rules live in FormTemplateService; creating a form still goes through the
 * `create` ability of DynamicFormPolicy, exactly like a form started from scratch.
 */
class FormTemplateController extends Controller
{
    public function __construct(
        private readonly FormTemplateService $templates,
        private readonly FormAccessService $access,
        private readonly DepartmentAccessService $departments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DynamicForm::class);
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(FormTemplate::CATEGORIES)],
            'search'   => ['nullable', 'string', 'max:100'],
            'source'   => ['nullable', Rule::in(['system', 'saved', 'mine'])],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->templates->library($request->user(), $filters),
            'meta'    => [
                'categories'  => FormTemplate::CATEGORIES,
                'can_create'  => $this->access->canManageAny($request->user()),
                'can_share_organization' => $this->access->isPlatformWide($request->user()),
                // The departments this person may place a form or share a template in (the same rule the server applies).
                'departments' => $this->departments->manifest($request->user())['readable_departments'],
            ],
        ]);
    }

    /** One template with its structure, for the preview. */
    public function show(Request $request, string $template): JsonResponse
    {
        $this->authorize('viewAny', DynamicForm::class);
        $resolved = $this->templates->resolve($request->user(), $template);
        abort_if($resolved === null, 404);

        return response()->json(['success' => true, 'data' => $resolved['summary'] + ['definition' => $resolved['definition']]]);
    }

    /** A new private draft from a template. */
    public function use(Request $request, string $template): JsonResponse
    {
        $this->authorize('create', DynamicForm::class);
        $data = $request->validate([
            'title'         => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        $resolved = $this->templates->resolve($request->user(), $template);
        abort_if($resolved === null, 404);

        $form = $this->templates->createFromTemplate($request->user(), $resolved, $data, $request);

        return response()->json(['success' => true, 'data' => ['id' => $form->id, 'title' => $form->title, 'status' => $form->status]], 201);
    }

    /** Save a form's current draft as a template. Anyone who may see the form and build forms may keep a copy of its structure. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', DynamicForm::class);
        $data = $this->validateTemplate($request) + $request->validate(['form_id' => ['required', 'integer']]);

        $form = DynamicForm::query()->find($data['form_id']);
        abort_if($form === null || ! $this->access->canView($request->user(), $form), 404);

        $template = $this->templates->saveFromForm($request->user(), $form, $data, $request);

        return response()->json(['success' => true, 'data' => $this->templates->presentSaved($template->load(['owner:id,name', 'department:id,name,name_ar']), $request->user())], 201);
    }

    public function update(Request $request, int $template): JsonResponse
    {
        $this->authorize('viewAny', DynamicForm::class);
        $model = $this->editable($request, $template);
        $data = $this->validateTemplate($request, partial: true) + $request->validate(['from_form_id' => ['nullable', 'integer']]);

        $from = null;
        if (! empty($data['from_form_id'])) {
            $from = DynamicForm::query()->find($data['from_form_id']);
            abort_if($from === null || ! $this->access->canView($request->user(), $from), 404);
        }

        $model = $this->templates->update($request->user(), $model, $data, $from);

        return response()->json(['success' => true, 'data' => $this->templates->presentSaved($model->load(['owner:id,name', 'department:id,name,name_ar']), $request->user())]);
    }

    /** Soft delete. Forms created from the template are independent copies and are not touched. */
    public function destroy(Request $request, int $template): JsonResponse
    {
        $this->authorize('viewAny', DynamicForm::class);
        $model = $this->editable($request, $template);
        $model->forceFill(['updated_by' => $request->user()->id])->save();
        $model->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف القالب.']);
    }

    /** A copy of a form as a new private draft. */
    public function duplicate(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('view', $form);
        $this->authorize('create', DynamicForm::class);
        $data = $request->validate([
            'title'         => ['nullable', 'string', 'max:255'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
        ]);

        $copy = $this->templates->duplicate($request->user(), $form, $data, $request);

        return response()->json(['success' => true, 'data' => ['id' => $copy->id, 'title' => $copy->title, 'status' => $copy->status]], 201);
    }

    /** A saved template the user may change: invisible ones are 404, visible but not theirs is 403. */
    private function editable(Request $request, int $id): FormTemplate
    {
        $model = $this->templates->visibleQuery($request->user())->find($id);
        abort_if($model === null, 404);
        abort_unless($this->templates->canEdit($request->user(), $model), 403, 'لا تملك صلاحية تعديل هذا القالب.');

        return $model;
    }

    /** @return array<string, mixed> */
    private function validateTemplate(Request $request, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name'          => [$presence, 'string', 'max:150'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'category'      => ['sometimes', Rule::in(FormTemplate::CATEGORIES)],
            'visibility'    => ['sometimes', Rule::in(FormTemplate::VISIBILITIES)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ], [
            'name.required' => 'اكتب اسماً للقالب.',
        ]);
    }
}
