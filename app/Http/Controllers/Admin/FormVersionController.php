<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DynamicFormResource;
use App\Http\Resources\FormVersionResource;
use App\Models\DynamicForm;
use App\Models\FormQuestion;
use App\Models\FormVersion;
use App\Services\Forms\FormActivityService;
use App\Services\Forms\FormSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The published versions of a form: list them, read one, and restore one into the DRAFT.
 *
 * Versions are never changed. Restoring copies an old version's questions into the working draft (so it can be reviewed and
 * edited) and touches nothing else: not the published version, not any version row, not any response or answer. Making the
 * restored draft live is the ordinary, separate, publish step.
 */
class FormVersionController extends Controller
{
    public function __construct(private readonly FormSnapshotService $snapshots) {}

    public function index(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('viewVersions', $form);

        $versions = $form->versions()->with('publisher')->paginate(min(max((int) $request->query('per_page', 20), 1), 100));
        $rows = $versions->getCollection()
            ->map(fn (FormVersion $v) => (new FormVersionResource($v))->markingCurrent($form->published_version_id)->resolve($request))
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'data' => $rows,
                'meta' => ['current_page' => $versions->currentPage(), 'last_page' => $versions->lastPage(), 'total' => $versions->total()],
            ],
        ]);
    }

    public function show(DynamicForm $form, FormVersion $version): JsonResponse
    {
        $this->authorize('viewVersions', $form);
        abort_if($version->form_id !== $form->id, 404);

        $version->load('publisher');

        return response()->json([
            'success' => true,
            'data'    => (new FormVersionResource($version))->withSchema()->markingCurrent($form->published_version_id),
        ]);
    }

    public function restore(Request $request, DynamicForm $form, FormVersion $version): JsonResponse
    {
        $this->authorize('restore', $form);
        abort_if($version->form_id !== $form->id, 404);
        $actor = $request->user();

        $form = DB::transaction(function () use ($request, $form, $version, $actor) {
            $locked = DynamicForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();
            $schema = $version->schema;

            $locked->forceFill([
                'title'          => $schema['form']['title'] ?? $locked->title,
                'description'    => $schema['form']['description'] ?? null,
                'type'           => $schema['form']['type'] ?? $locked->type,
                // The version's flow comes back with its content: restoring is restoring the whole form, steps and rules included.
                'flow'           => FormSnapshotService::flowOf($schema['form']['flow'] ?? null) ?: null,
                'last_edited_by' => $actor->id,
                'draft_revision' => (int) $locked->draft_revision + 1,
            ])->save();

            $this->restoreQuestions($locked, $schema['questions'] ?? []);

            FormActivityService::record($locked, $actor, 'version_restored', [
                'from_version' => $version->version_number, 'question_count' => count($schema['questions'] ?? []),
            ], $request);

            return $locked->refresh();
        });

        $form->load(['department', 'createdBy', 'owner', 'lastEditor', 'publisher', 'publishedVersion', 'questions'])->loadCount('submissions')->loadMax('submissions', 'submitted_at');

        return response()->json([
            'success' => true,
            'data'    => (new DynamicFormResource($form))->withDraftState($this->snapshots->hasUnpublishedChanges($form)),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $logic
     * @param  array<int, int>  $map
     * @return array<string, mixed>|null
     */
    private function remapLogic(?array $logic, array $map): ?array
    {
        $conditions = [];
        foreach ($logic['conditions'] ?? [] as $c) {
            if (isset($map[(int) ($c['question'] ?? 0)])) {
                $conditions[] = ['question' => $map[(int) $c['question']]] + $c;
            }
        }

        return $conditions === [] ? null : FormSnapshotService::canonicalLogic(['conditions' => $conditions] + $logic);
    }

    /**
     * Makes the form's live questions equal the version's, without ever deleting or rewriting history:
     *  - a question the version has and that still exists (even if it was soft-deleted since) is brought back to the
     *    version's definition, keeping its id, so its old answers stay attached to it;
     *  - if its type was changed after the version was published AND it has answers, it is not rewritten: the old row stays
     *    as it is and the version's definition is added as a NEW question;
     *  - a question that the version does not have is soft-deleted (its answers stay).
     *
     * @param  list<array<string, mixed>>  $questions
     */
    private function restoreQuestions(DynamicForm $form, array $questions): void
    {
        $all = FormQuestion::withTrashed()->where('form_id', $form->id)->get()->keyBy('id');
        $wanted = [];
        /** @var array<int, int> $map the id a block had in the version => the id of the block that now stands for it */
        $map = [];

        foreach (array_values($questions) as $index => $q) {
            $attributes = [
                'label' => $q['label'], 'type' => $q['type'], 'options' => $q['options'] ?? null,
                'is_required' => (bool) $q['required'], 'sort_order' => $index + 1,
                'description' => $q['description'] ?? null, 'placeholder' => $q['placeholder'] ?? null,
                'help_text' => $q['help_text'] ?? null, 'config' => $q['config'] ?? null,
            ];
            $row = $all->get((int) $q['id']);

            if ($row !== null && ($row->type === $q['type'] || ! $row->answers()->exists())) {
                if ($row->trashed()) {
                    $row->restore();
                }
                $row->update($attributes);
                $wanted[] = $row->id;
                $map[(int) $q['id']] = $row->id;

                continue;
            }

            $created = $form->questions()->create($attributes);
            $wanted[] = $created->id;
            $map[(int) $q['id']] = $created->id;
        }

        // The version's visibility rules, pointed at the blocks that now stand for the ones they read. (A block that had to be
        // re-created under a new id keeps its rule readable; a condition on a block that no longer exists is dropped.)
        foreach (array_values($questions) as $q) {
            $logic = $this->remapLogic($q['logic'] ?? null, $map);
            FormQuestion::query()->whereKey($map[(int) $q['id']])->first()?->forceFill(['logic' => $logic])->save();
        }

        foreach ($all as $id => $row) {
            if (! in_array($id, $wanted, true) && ! $row->trashed()) {
                $row->delete();
            }
        }
    }
}
