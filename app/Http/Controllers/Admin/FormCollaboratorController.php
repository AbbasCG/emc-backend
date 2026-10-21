<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FormActivityResource;
use App\Http\Resources\FormCollaboratorResource;
use App\Models\ActivityLog;
use App\Models\DynamicForm;
use App\Models\FormCollaborator;
use App\Services\Forms\FormCollaborationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Collaborators and the history of one form.
 *
 * Seeing who a form is shared with needs the right to change the form; changing it needs the right to manage access (the
 * owner and platform-wide admins). The history describes the form, so whoever can open the form can read it.
 */
class FormCollaboratorController extends Controller
{
    public function __construct(private readonly FormCollaborationService $collaboration) {}

    public function index(DynamicForm $form): JsonResponse
    {
        $this->authorize('update', $form);

        $rows = $form->collaborators()->with(['user.departments:departments.id,departments.name', 'grantedBy'])->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data'    => FormCollaboratorResource::collection($rows),
        ]);
    }

    /** Employees who could still be added, found by name (at least two characters). */
    public function candidates(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('manageAccess', $form);
        $q = trim((string) $request->query('q', ''));

        $users = mb_strlen($q) >= 2 ? $this->collaboration->candidates($form, $q) : collect();

        return response()->json([
            'success' => true,
            'data'    => $users->map(fn ($u) => FormCollaboratorResource::employee($u))->values(),
        ]);
    }

    public function store(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('manageAccess', $form);
        $data = $this->validated($request, creating: true);

        $collaborator = $this->collaboration->add($form, $request->user(), (int) $data['user_id'], $data['access_level'], (bool) ($data['can_view_responses'] ?? false), $request, (bool) ($data['can_correct_responses'] ?? false));

        return response()->json([
            'success' => true,
            'data'    => new FormCollaboratorResource($collaborator->load(['user.departments:departments.id,departments.name', 'grantedBy'])),
        ], 201);
    }

    public function update(Request $request, DynamicForm $form, FormCollaborator $collaborator): JsonResponse
    {
        abort_if($collaborator->form_id !== $form->id, 404);
        $this->authorize('manageAccess', $form);
        $data = $this->validated($request, creating: false);

        $updated = $this->collaboration->update($form, $collaborator, $request->user(), $data['access_level'], (bool) ($data['can_view_responses'] ?? false), $request, array_key_exists('can_correct_responses', $data) ? (bool) $data['can_correct_responses'] : null);

        return response()->json([
            'success' => true,
            'data'    => new FormCollaboratorResource($updated->load(['user.departments:departments.id,departments.name', 'grantedBy'])),
        ]);
    }

    public function destroy(Request $request, DynamicForm $form, FormCollaborator $collaborator): JsonResponse
    {
        abort_if($collaborator->form_id !== $form->id, 404);
        $this->authorize('manageAccess', $form);

        $this->collaboration->remove($form, $collaborator, $request->user(), $request);

        return response()->json(['success' => true, 'message' => 'Collaborator removed.']);
    }

    public function activity(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('viewActivity', $form);

        $entries = ActivityLog::query()
            ->where('entity_type', DynamicForm::class)->where('entity_id', $form->id)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json([
            'success' => true,
            'data'    => [
                'data' => $entries->getCollection()->map(fn ($e) => (new FormActivityResource($e))->resolve($request))->values(),
                'meta' => ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'total' => $entries->total()],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'user_id'            => [$creating ? 'required' : 'prohibited', 'integer'],
            'access_level'       => ['required', Rule::in(FormCollaborator::LEVELS)],
            'can_view_responses' => ['sometimes', 'boolean'],
            'can_correct_responses' => ['sometimes', 'boolean'],
        ], [
            'user_id.required'  => 'اختر الموظف.',
            'access_level.required' => 'اختر مستوى الوصول.',
            'access_level.in'   => 'مستوى الوصول غير صالح.',
        ]);
    }
}
