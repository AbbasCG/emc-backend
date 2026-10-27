<?php

namespace App\Services\Forms\Templates;

use App\Models\DynamicForm;
use App\Models\FormTemplate;
use App\Models\User;
use App\Services\DepartmentAccessService;
use App\Services\Forms\FormAccessService;
use App\Services\Forms\FormActivityService;
use App\Services\Forms\FormQuestionSyncService;
use App\Services\Forms\FormSnapshotService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The template library of Smart Forms (Phase 6): the built-in templates (SystemFormTemplates) and the ones employees save.
 *
 * Who sees a saved template follows the same organisation rules as forms:
 *   private       its owner (and platform-wide form managers);
 *   department    the people of that department (DepartmentAccessService);
 *   organization  everyone who may open the forms screens. Only platform-wide managers publish to the whole organisation.
 * A template the user cannot see does not exist for them (404, never 403), so ids cannot be probed.
 *
 * Using a template or duplicating a form always produces a NEW PRIVATE DRAFT owned by the person who did it, built from the
 * structure only: never responses, sharing, collaborators, versions, dates or limits. From there it follows the normal rules.
 */
class FormTemplateService
{
    public function __construct(
        private readonly FormAccessService $access,
        private readonly DepartmentAccessService $departments,
    ) {}

    // ── Reading the library ──────────────────────────────────────────────────

    /** The saved templates this user may see. */
    public function visibleQuery(User $user): Builder
    {
        $query = FormTemplate::query();
        if ($this->access->isPlatformWide($user)) {
            return $query;
        }

        $departmentIds = $this->departments->getAccessibleDepartmentIds($user)->all();

        return $query->where(function (Builder $q) use ($user, $departmentIds) {
            $q->where('owner_id', $user->id)
                ->orWhere('visibility', 'organization')
                ->orWhere(fn (Builder $d) => $d->where('visibility', 'department')->whereIn('department_id', $departmentIds ?: [0]));
        });
    }

    public function canSee(User $user, FormTemplate $template): bool
    {
        return $this->visibleQuery($user)->whereKey($template->id)->exists();
    }

    /** Changing or deleting a saved template: its owner, or a platform-wide manager. Both must still be allowed to build forms. */
    public function canEdit(User $user, FormTemplate $template): bool
    {
        if (! $this->access->canManageAny($user)) {
            return false;
        }

        return (int) $template->owner_id === (int) $user->id || $this->access->isPlatformWide($user);
    }

    /**
     * The library as the screens list it: system templates first, then the saved ones, filtered by category and search text.
     *
     * @param  array{category?: string|null, search?: string|null, source?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function library(User $user, array $filters = []): array
    {
        $category = $filters['category'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $source = $filters['source'] ?? null;
        $matches = fn (string ...$texts) => $search === '' || collect($texts)->contains(fn ($t) => mb_stripos($t, $search) !== false);

        $out = [];
        if ($source === null || $source === 'system') {
            foreach (SystemFormTemplates::all() as $t) {
                if (($category === null || $t['category'] === $category) && $matches($t['name'], (string) $t['description'])) {
                    $out[] = $this->presentSystem($t);
                }
            }
        }

        if ($source === null || $source === 'saved' || $source === 'mine') {
            $saved = $this->visibleQuery($user)
                ->with(['owner:id,name', 'department:id,name,name_ar'])
                ->when($category, fn ($q, $c) => $q->where('category', $c))
                ->when($source === 'mine', fn ($q) => $q->where('owner_id', $user->id))
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->orWhere('description', 'like', '%'.addcslashes($search, '%_\\').'%')))
                ->latest('updated_at')
                ->limit(200)
                ->get();
            foreach ($saved as $template) {
                $out[] = $this->presentSaved($template, $user);
            }
        }

        return $out;
    }

    /**
     * A template by the id the screens use: `system:<key>` or a saved template's number. Null when it does not exist for this user.
     *
     * @return array{summary: array<string, mixed>, definition: array<string, mixed>, model: FormTemplate|null}|null
     */
    public function resolve(User $user, string $id): ?array
    {
        if (str_starts_with($id, SystemFormTemplates::PREFIX)) {
            $t = SystemFormTemplates::find(substr($id, strlen(SystemFormTemplates::PREFIX)));

            return $t === null ? null : ['summary' => $this->presentSystem($t), 'definition' => $t['definition'], 'model' => null];
        }
        if (! ctype_digit($id)) {
            return null;
        }

        $template = $this->visibleQuery($user)->with(['owner:id,name', 'department:id,name,name_ar'])->find((int) $id);

        return $template === null ? null : ['summary' => $this->presentSaved($template, $user), 'definition' => (array) $template->definition, 'model' => $template];
    }

    // ── Forms from templates ─────────────────────────────────────────────────

    /**
     * A new private draft from a template's structure.
     *
     * @param  array{title?: string|null, department_id?: int|null}  $data
     */
    public function createFromTemplate(User $actor, array $resolved, array $data, ?Request $request = null): DynamicForm
    {
        $definition = $resolved['definition'];
        $summary = $resolved['summary'];

        return DB::transaction(function () use ($actor, $definition, $summary, $resolved, $data, $request) {
            $form = $this->newDraft($actor, $definition, $data);
            FormActivityService::record($form, $actor, 'created_from_template', [
                'template'       => mb_substr((string) $summary['name'], 0, 150),
                'question_count' => $form->questions()->count(),
            ], $request);

            if ($resolved['model'] instanceof FormTemplate) {
                FormTemplate::query()->whereKey($resolved['model']->id)->increment('uses_count');
            }

            return $form;
        });
    }

    /**
     * A copy of a form's CURRENT DRAFT as a new private draft. The source keeps everything; the copy carries no responses,
     * sharing, collaborators, versions, dates or limits.
     *
     * @param  array{title?: string|null, department_id?: int|null}  $data
     */
    public function duplicate(User $actor, DynamicForm $source, array $data, ?Request $request = null): DynamicForm
    {
        $definition = $this->definitionFromForm($source);
        if (! array_key_exists('department_id', $data)) {
            // The copy stays in the source's department when the person may build forms there; otherwise it has none.
            $data['department_id'] = $source->department_id !== null && $this->access->canUseDepartment($actor, (int) $source->department_id)
                ? (int) $source->department_id
                : null;
        }
        $data['title'] = trim((string) ($data['title'] ?? '')) !== '' ? $data['title'] : mb_substr('نسخة من '.$source->title, 0, 255);

        return DB::transaction(function () use ($actor, $source, $definition, $data, $request) {
            $form = $this->newDraft($actor, $definition, $data);
            FormActivityService::record($form, $actor, 'duplicated', [
                'source'         => mb_substr((string) $source->title, 0, 150),
                'question_count' => $form->questions()->count(),
            ], $request);

            return $form;
        });
    }

    /** The insert both paths share: the same defaults as a form created from scratch (DynamicFormController::store). */
    private function newDraft(User $actor, array $definition, array $data): DynamicForm
    {
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;
        if (! $this->access->canUseDepartment($actor, $departmentId)) {
            throw ValidationException::withMessages(['department_id' => 'لا تملك صلاحية إنشاء نماذج لهذا القسم.']);
        }

        $title = trim((string) ($data['title'] ?? '')) !== '' ? trim((string) $data['title']) : (string) ($definition['title'] ?? 'نموذج جديد');

        $form = DynamicForm::create([
            'title'                => mb_substr($title, 0, 255),
            'slug'                 => DynamicForm::freeSlug(null, $title),
            'description'          => $definition['description'] ?? null,
            'type'                 => ($definition['type'] ?? null) ?: 'general',
            'flow'                 => FormSnapshotService::flowOf($definition['flow'] ?? null) ?: null,
            'department_id'        => $departmentId,
            'status'               => 'draft',
            'visibility'           => 'private',
            'responses_visibility' => 'restricted',
            'created_by'           => $actor->id,
            'owner_id'             => $actor->id,
            'last_edited_by'       => $actor->id,
        ]);

        $questions = array_values((array) ($definition['questions'] ?? []));
        if ($questions !== []) {
            app(FormQuestionSyncService::class)->sync($form, $questions);
        }

        return $form->refresh();
    }

    // ── Saving templates ─────────────────────────────────────────────────────

    /**
     * @param  array{name: string, description?: string|null, category?: string|null, visibility?: string|null, department_id?: int|null}  $data
     */
    public function saveFromForm(User $actor, DynamicForm $form, array $data, ?Request $request = null): FormTemplate
    {
        $placement = $this->placement($actor, $data, null);

        return DB::transaction(function () use ($actor, $form, $data, $placement, $request) {
            $template = FormTemplate::create([
                'name'           => $data['name'],
                'description'    => $data['description'] ?? $form->description,
                'category'       => $data['category'] ?? 'general',
                'form_type'      => $form->type !== 'general' ? $form->type : null,
                'definition'     => $this->definitionFromForm($form),
                'owner_id'       => $actor->id,
                'created_by'     => $actor->id,
                'updated_by'     => $actor->id,
                'source_form_id' => $form->id,
            ] + $placement);

            FormActivityService::record($form, $actor, 'saved_as_template', ['template' => mb_substr($template->name, 0, 150)], $request);

            return $template;
        });
    }

    /**
     * Name, description, category and who sees it; with `from_form_id`, the structure is replaced by that form's current draft.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, FormTemplate $template, array $data, ?DynamicForm $from = null): FormTemplate
    {
        $placement = (array_key_exists('visibility', $data) || array_key_exists('department_id', $data))
            ? $this->placement($actor, $data + ['visibility' => $template->visibility, 'department_id' => $template->department_id], $template)
            : [];

        $fields = array_intersect_key($data, array_flip(['name', 'description', 'category'])) + $placement;
        if ($from !== null) {
            $fields['definition'] = $this->definitionFromForm($from);
            $fields['form_type'] = $from->type !== 'general' ? $from->type : null;
            $fields['source_form_id'] = $from->id;
        }
        $template->fill($fields + ['updated_by' => $actor->id])->save();

        return $template->refresh();
    }

    /**
     * Where a template lives, checked against what the person may do.
     *
     * @return array{visibility: string, department_id: int|null}
     */
    private function placement(User $actor, array $data, ?FormTemplate $current): array
    {
        $visibility = $data['visibility'] ?? $current?->visibility ?? 'private';
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;

        if ($visibility === 'organization' && ! $this->access->isPlatformWide($actor)) {
            throw ValidationException::withMessages(['visibility' => 'مشاركة القالب مع المؤسسة كلها متاحة لمديري المنصة فقط. شاركه مع قسمك بدلاً من ذلك.']);
        }
        if ($visibility === 'department') {
            if ($departmentId === null) {
                throw ValidationException::withMessages(['department_id' => 'اختر القسم الذي سيظهر له القالب.']);
            }
            if (! $this->access->canUseDepartment($actor, $departmentId)) {
                throw ValidationException::withMessages(['department_id' => 'لا يمكنك مشاركة قالب مع قسم لا تنتمي إليه.']);
            }
        }

        return ['visibility' => $visibility, 'department_id' => $visibility === 'department' ? $departmentId : null];
    }

    /**
     * A form's current draft as a self-contained definition: blocks numbered by negative refs in order, rules rewritten to those
     * refs. A condition on a block that no longer exists is dropped (a rule left with no condition is no rule).
     *
     * @return array{title: string, description: ?string, type: ?string, flow: ?array, questions: list<array<string, mixed>>}
     */
    public function definitionFromForm(DynamicForm $form): array
    {
        $questions = $form->questions()->get();
        $refs = [];
        foreach ($questions->values() as $i => $q) {
            $refs[$q->id] = -($i + 1);
        }

        $blocks = [];
        foreach ($questions->values() as $i => $q) {
            $block = [
                'ref'         => -($i + 1),
                'type'        => $q->type,
                'label'       => (string) $q->label,
                'options'     => $q->options,
                'required'    => (bool) $q->is_required,
                'description' => $q->description,
                'placeholder' => $q->placeholder,
                'help_text'   => $q->help_text,
                'config'      => $q->config,
            ];
            $logic = is_array($q->logic) ? $q->logic : null;
            if ($logic !== null) {
                $conditions = [];
                foreach ((array) ($logic['conditions'] ?? []) as $c) {
                    $sourceId = (int) ($c['question'] ?? 0);
                    if (isset($refs[$sourceId])) {
                        $conditions[] = ['question' => $refs[$sourceId]] + $c;
                    }
                }
                if ($conditions !== []) {
                    $block['logic'] = ['action' => $logic['action'] ?? 'show', 'match' => $logic['match'] ?? 'all', 'conditions' => $conditions];
                }
            }
            $blocks[] = $block;
        }

        return [
            'title'       => (string) $form->title,
            'description' => $form->description,
            'type'        => $form->type,
            'flow'        => FormSnapshotService::flowOf($form->flow) ?: null,
            'questions'   => $blocks,
        ];
    }

    // ── Presenting ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function presentSystem(array $t): array
    {
        return [
            'id'             => SystemFormTemplates::PREFIX.$t['key'],
            'source'         => 'system',
            'key'            => $t['key'],
            'name'           => $t['name'],
            'description'    => $t['description'],
            'category'       => $t['category'],
            'form_type'      => $t['form_type'],
            'domain_notice'  => $t['domain_notice'],
            'visibility'     => 'organization',
            'department'     => null,
            'owner'          => null,
            'question_count' => self::countAnswerable($t['definition']['questions']),
            'block_count'    => count($t['definition']['questions']),
            'uses_count'     => null,
            'updated_at'     => null,
            'abilities'      => ['edit' => false, 'delete' => false],
        ];
    }

    /** @return array<string, mixed> */
    public function presentSaved(FormTemplate $template, User $user): array
    {
        $questions = (array) (($template->definition ?? [])['questions'] ?? []);
        $canEdit = $this->canEdit($user, $template);

        return [
            'id'             => (string) $template->id,
            'source'         => 'saved',
            'key'            => null,
            'name'           => $template->name,
            'description'    => $template->description,
            'category'       => $template->category,
            'form_type'      => $template->form_type,
            'domain_notice'  => null,
            'visibility'     => $template->visibility,
            'department'     => $template->department ? ['id' => $template->department->id, 'name' => $template->department->name_ar ?: $template->department->name] : null,
            'owner'          => $template->owner ? ['id' => $template->owner->id, 'name' => $template->owner->name] : null,
            'is_mine'        => (int) $template->owner_id === (int) $user->id,
            'question_count' => self::countAnswerable($questions),
            'block_count'    => count($questions),
            'uses_count'     => (int) $template->uses_count,
            'updated_at'     => $template->updated_at?->toIso8601String(),
            'abilities'      => ['edit' => $canEdit, 'delete' => $canEdit],
        ];
    }

    private static function countAnswerable(array $questions): int
    {
        return count(array_filter($questions, fn ($q) => ! in_array($q['type'] ?? '', \App\Models\FormQuestion::STRUCTURAL, true)));
    }
}
