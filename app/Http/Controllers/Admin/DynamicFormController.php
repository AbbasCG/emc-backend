<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\FormDraftConflictException;
use App\Http\Requests\SaveDynamicFormRequest;
use App\Http\Resources\DynamicFormResource;
use App\Http\Resources\FormQuestionResource;
use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormQuestion;
use App\Models\FormSubmission;
use App\Models\User;
use App\Rules\FormAttachmentFile;
use App\Services\Forms\FormAccessService;
use App\Services\Forms\FormActivityService;
use App\Services\Forms\FormAttachmentService;
use App\Services\Forms\FormLifecycleService;
use App\Services\Forms\FormQuestionSyncService;
use App\Services\Forms\FormSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Form management and response list for staff. Every action is authorized against the form itself
 * (DynamicFormPolicy / FormAccessService): the role middleware on the route only decides who may reach the
 * screen, not which forms they may touch.
 *
 * Editing changes the DRAFT (the form row and its questions). What respondents see only changes when the draft is
 * published (FormLifecycleController), which freezes it into a new immutable version.
 */
class DynamicFormController extends Controller
{
    /** Field names as the history shows them. */
    private const FIELD_LABELS = ['title' => 'العنوان', 'description' => 'الوصف', 'type' => 'النوع', 'slug' => 'الرابط', 'department_id' => 'القسم', 'flow' => 'مراحل النموذج'];

    public function __construct(
        private readonly FormAccessService $access,
        private readonly FormLifecycleService $lifecycle,
        private readonly FormSnapshotService $snapshots,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DynamicForm::class);
        $user = $request->user();

        $forms = $this->access->scope(DynamicForm::query(), $user)
            ->with(['department', 'createdBy', 'owner', 'lastEditor', 'publisher', 'publishedVersion'])
            ->withCount('submissions')
            ->withMax('submissions', 'submitted_at')
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $term) => $q->where('title', 'like', '%'.addcslashes((string) $term, '%_\\').'%'))
            ->latest()
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        $this->access->prime($user, $forms->pluck('id')->all());

        return response()->json(['success' => true, 'data' => DynamicFormResource::collection($forms)->response()->getData(true)]);
    }

    public function store(SaveDynamicFormRequest $request): JsonResponse
    {
        $this->authorize('create', DynamicForm::class);

        $data = $request->validated();
        $actor = $request->user();
        $this->assertDepartmentAllowed($request, $data['department_id'] ?? null);

        $form = DB::transaction(function () use ($request, $data, $actor) {
            $form = DynamicForm::create([
                'title'                => $data['title'],
                'slug'                 => $this->uniqueSlug($data['slug'] ?? null, $data['title']),
                'description'          => $data['description'] ?? null,
                'type'                 => $data['type'] ?? 'general',
                'flow'                 => self::flowFrom($data) ?: null,
                'department_id'        => $data['department_id'] ?? null,
                'related_type'         => $data['related_type'] ?? null,
                'related_id'           => $data['related_id'] ?? null,
                // A new form is a private draft owned by its creator: it is finished first, then shared and published
                // on purpose. The public link only works once the form is published.
                'status'               => 'draft',
                'visibility'           => $data['visibility'] ?? 'private',
                'responses_visibility' => $data['responses_visibility'] ?? 'restricted',
                'created_by'           => $actor->id,
                'owner_id'             => $actor->id,
                'last_edited_by'       => $actor->id,
            ]);

            if (! empty($data['questions'])) {
                app(FormQuestionSyncService::class)->sync($form, $data['questions']);
            }
            FormActivityService::record($form, $actor, 'created', ['question_count' => $form->questions()->count()], $request);

            // Asking for `active` on creation is a publication like any other (needs a question; creates version 1).
            if (($data['status'] ?? 'draft') === 'active') {
                $form = $this->lifecycle->publish($form, $actor, null, null, $request);
            }

            return $form;
        });

        return response()->json([
            'success' => true,
            'data'    => $this->present($form, $request, withDraftState: true),
        ], 201);
    }

    public function show(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('view', $form);

        return response()->json([
            'success' => true,
            'data'    => $this->present($form, $request, withDraftState: true),
        ]);
    }

    public function update(SaveDynamicFormRequest $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('update', $form);

        $data = $request->validated();
        $actor = $request->user();
        if (array_key_exists('department_id', $data) && (int) $data['department_id'] !== (int) $form->department_id) {
            $this->assertDepartmentAllowed($request, $data['department_id']);
        }

        try {
            $form = $this->saveDraft($request, $form, $data, $actor);
        } catch (FormDraftConflictException $e) {
            return $this->conflict($request, $form);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->present($form, $request, withDraftState: true),
        ]);
    }

    /** One locked transaction: refuse a stale editor, apply the change, bump the revision only if something was persisted. */
    private function saveDraft(SaveDynamicFormRequest $request, DynamicForm $form, array $data, User $actor): DynamicForm
    {
        return DB::transaction(function () use ($request, $form, $data, $actor) {
            $locked = DynamicForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

            // Optimistic concurrency: the editor says which revision it started from. A different revision means someone else
            // saved in between; the save is refused (409) unless the editor explicitly chose to overwrite (`force`).
            if (isset($data['base_revision']) && ! ($data['force'] ?? false) && (int) $data['base_revision'] !== (int) $locked->draft_revision) {
                throw new FormDraftConflictException((int) $locked->draft_revision);
            }

            $fields = collect($data)->only(['title', 'slug', 'description', 'type', 'department_id'])->all();
            // The review step: sent as `flow`, kept only when switched on (null otherwise), never erased by a client that omits it.
            if (array_key_exists('flow', $data)) {
                $fields['flow'] = self::flowFrom($data) ?: null;
            }
            // NOT NULL columns: a null from the client means "no value sent", never "erase it".
            foreach (['title', 'slug'] as $required) {
                if (array_key_exists($required, $fields) && ($fields[$required] === null || $fields[$required] === '')) {
                    unset($fields[$required]);
                }
            }
            if (array_key_exists('type', $fields) && ($fields['type'] === null || $fields['type'] === '')) {
                $fields['type'] = 'general';
            }
            // The public link of a form that has ever been published or answered never changes: it is printed, shared, bookmarked.
            if (isset($fields['slug']) && $fields['slug'] !== $locked->slug && $this->slugIsLocked($locked)) {
                throw ValidationException::withMessages(['slug' => 'لا يمكن تغيير رابط نموذج سبق نشره أو استلم إجابات.']);
            }

            $locked->fill($fields);
            $changedFields = array_keys($locked->getDirty());

            $diff = array_key_exists('questions', $data) ? app(FormQuestionSyncService::class)->sync($locked, $data['questions']) : null;

            // "Last edited by" and the draft revision only move when something was really persisted.
            if ($changedFields !== [] || ($diff['changed'] ?? false)) {
                $locked->last_edited_by = $actor->id;
                $locked->draft_revision = (int) $locked->draft_revision + 1;
            }
            $locked->save();

            if ($changedFields !== []) {
                FormActivityService::record($locked, $actor, 'updated', ['fields' => array_map(fn ($f) => self::FIELD_LABELS[$f] ?? $f, $changedFields)], $request);
            }
            $this->recordQuestionChanges($locked, $actor, $diff, $request, forced: (bool) ($data['force'] ?? false));

            // The Phase 0 way of publishing / closing (a `status` field) goes through the same lifecycle rules.
            if (isset($data['status']) && $data['status'] !== $locked->status) {
                $this->authorize('publish', $locked);

                return $this->lifecycle->applyStatus($locked, $actor, $data['status'], $request);
            }

            return $locked->refresh();
        });
    }

    /** @return array<string, true> the flow switches that are on in a request */
    private static function flowFrom(array $data): array
    {
        return FormSnapshotService::flowOf($data['flow'] ?? null);
    }

    /** 409: the draft moved on. Carries the current draft so the builder can load it, or overwrite it on purpose. */
    private function conflict(Request $request, DynamicForm $form): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code'    => 'draft_conflict',
            'message' => 'تم تعديل مسودة النموذج من قبل شخص آخر بعد أن فتحتها. حمّل آخر نسخة أو احفظ نسختك فوقها عن قصد.',
            'data'    => $this->present($form->refresh(), $request, withDraftState: true),
        ], 409);
    }

    /** Soft delete: the form disappears from the lists and its public link stops working; its responses are kept. */
    public function destroy(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('delete', $form);

        DB::transaction(function () use ($request, $form) {
            FormActivityService::record($form, $request->user(), 'deleted', [], $request);
            $form->delete();
        });

        return response()->json(['success' => true, 'message' => 'Form deleted.']);
    }

    // ── Questions ──────────────────────────────────────────────────────────────

    public function indexQuestions(DynamicForm $form): JsonResponse
    {
        $this->authorize('view', $form);

        return response()->json([
            'success' => true,
            'data'    => FormQuestionResource::collection($form->questions),
        ]);
    }

    public function storeQuestion(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('update', $form);

        $data = $this->validateQuestion($request);
        $attributes = FormQuestionSyncService::attributes($data, $data['sort_order'] ?? ((int) $form->questions()->max('sort_order') + 1));

        $question = DB::transaction(function () use ($request, $form, $attributes) {
            $question = $form->questions()->create($attributes);
            $this->touchEditor($form, $request->user());
            $this->recordQuestionChanges($form, $request->user(), ['added' => [$attributes['label']], 'removed' => [], 'edited' => [], 'reordered' => false, 'changed' => true], $request);

            return $question;
        });

        return response()->json(['success' => true, 'data' => new FormQuestionResource($question)], 201);
    }

    public function updateQuestion(Request $request, DynamicForm $form, FormQuestion $question): JsonResponse
    {
        abort_if($question->form_id !== $form->id, 404);
        $this->authorize('update', $form);

        $data = $this->validateQuestion($request, partial: true);
        $merged = [
            'label'       => $data['label'] ?? $question->label,
            'type'        => $data['type'] ?? $question->type,
            'options'     => array_key_exists('options', $data) ? $data['options'] : $question->options,
            'is_required' => $data['is_required'] ?? $question->is_required,
            'description' => array_key_exists('description', $data) ? $data['description'] : $question->description,
            'placeholder' => array_key_exists('placeholder', $data) ? $data['placeholder'] : $question->placeholder,
            'help_text'   => array_key_exists('help_text', $data) ? $data['help_text'] : $question->help_text,
            'config'      => array_key_exists('config', $data) ? $data['config'] : $question->config,
        ];

        if ($merged['type'] !== $question->type && $question->answers()->exists()) {
            throw ValidationException::withMessages(['type' => 'لا يمكن تغيير نوع سؤال لديه إجابات مسجّلة. أضف سؤالاً جديداً بدلاً من ذلك.']);
        }

        DB::transaction(function () use ($request, $form, $question, $merged, $data) {
            $attributes = FormQuestionSyncService::attributes($merged, $data['sort_order'] ?? $question->sort_order);
            $question->fill($attributes);
            $changed = $question->isDirty();
            $question->save();
            if ($changed) {
                $this->touchEditor($form, $request->user());
                $this->recordQuestionChanges($form, $request->user(), ['added' => [], 'removed' => [], 'edited' => [$question->label], 'reordered' => false, 'changed' => true], $request);
            }
        });

        return response()->json(['success' => true, 'data' => new FormQuestionResource($question->refresh())]);
    }

    /** Soft delete: the answers already given keep their question and stay readable. */
    public function destroyQuestion(Request $request, DynamicForm $form, FormQuestion $question): JsonResponse
    {
        abort_if($question->form_id !== $form->id, 404);
        $this->authorize('update', $form);

        DB::transaction(function () use ($request, $form, $question) {
            $label = $question->label;
            $question->delete();
            $this->touchEditor($form, $request->user());
            $this->recordQuestionChanges($form, $request->user(), ['added' => [], 'removed' => [$label], 'edited' => [], 'reordered' => false, 'changed' => true], $request);
        });

        return response()->json(['success' => true, 'message' => 'Question deleted.']);
    }

    // ── Attachments ────────────────────────────────────────────────────────────
    // The response list, details, workflow and exports live in FormResponseController / FormExportController.

    /**
     * An attachment of a response. Only someone who may read the form's responses can fetch it, the answer must
     * belong to that exact submission and form, and the file is always sent as a download (never rendered by the
     * browser) so an uploaded page or image cannot run in the application's origin.
     */
    public function downloadAnswerFile(DynamicForm $form, FormSubmission $submission, FormAnswer $answer, FormAttachmentService $files): StreamedResponse
    {
        abort_if($submission->form_id !== $form->id || $answer->submission_id !== $submission->id, 404);
        $this->authorize('viewResponses', $form);
        abort_if(! $answer->file_path, 404);

        $disk = $files->diskFor($answer->file_path);
        abort_if($disk === null, 404);

        $ext = strtolower(pathinfo($answer->file_path, PATHINFO_EXTENSION));
        $mime = FormAttachmentFile::ALLOWED[$ext][0] ?? 'application/octet-stream';
        $name = FormAttachmentService::displayName((string) ($answer->value ?: 'attachment.'.$ext));

        return Storage::disk($disk)->download($answer->file_path, $name, [
            'Content-Type'           => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** The form as the screens read it: relations loaded, counts and the draft-versus-published fact included. */
    private function present(DynamicForm $form, Request $request, bool $withDraftState = false): DynamicFormResource
    {
        $form->load(['department', 'createdBy', 'owner', 'lastEditor', 'publisher', 'publishedVersion', 'questions' => fn ($q) => $q->withExists('answers')])
            ->loadCount('submissions')
            ->loadMax('submissions', 'submitted_at');

        $resource = new DynamicFormResource($form);

        return $withDraftState ? $resource->withDraftState($this->snapshots->hasUnpublishedChanges($form)) : $resource;
    }

    /** A published or answered form keeps its link for good. */
    private function slugIsLocked(DynamicForm $form): bool
    {
        return $form->published_version_id !== null || $form->submissions()->exists();
    }

    private function touchEditor(DynamicForm $form, User $actor): void
    {
        $form->forceFill(['last_edited_by' => $actor->id, 'draft_revision' => (int) $form->draft_revision + 1])->save();
    }

    /**
     * What the history records about a question change: which questions were added, removed or edited (their labels),
     * and whether the order changed. Never an answer.
     *
     * @param  array{added: list<string>, removed: list<string>, edited: list<string>, reordered: bool, changed: bool}|null  $diff
     */
    private function recordQuestionChanges(DynamicForm $form, User $actor, ?array $diff, Request $request, bool $forced = false): void
    {
        if ($diff === null || ! $diff['changed']) {
            return;
        }

        $cut = fn (array $labels) => array_slice(array_map(fn ($l) => mb_substr((string) $l, 0, 80), $labels), 0, 10);
        if ($diff['added'] !== [] || $diff['removed'] !== [] || $diff['edited'] !== []) {
            FormActivityService::record($form, $actor, 'questions_changed', ['added' => $cut($diff['added']), 'removed' => $cut($diff['removed']), 'edited' => $cut($diff['edited'])] + ($forced ? ['forced' => true] : []), $request);
        }
        if ($diff['reordered']) {
            FormActivityService::record($form, $actor, 'questions_reordered', [], $request);
        }
    }

    /** @return array<string, mixed> */
    private function validateQuestion(Request $request, bool $partial = false): array
    {
        if (! $request->has('is_required') && $request->has('required')) {
            $request->merge(['is_required' => $request->input('required')]);
        }
        $presence = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'label'         => ['nullable', 'string', 'max:255'],
            'type'          => [$presence, Rule::in(FormQuestion::TYPES)],
            'options'       => ['nullable', 'array', 'max:100'],
            'options.*'     => ['nullable', 'string', 'max:255'],
            'is_required'   => ['sometimes', 'boolean'],
            'sort_order'    => ['sometimes', 'integer', 'min:0'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'placeholder'   => ['nullable', 'string', 'max:255'],
            'help_text'     => ['nullable', 'string', 'max:500'],
            'config'        => ['nullable', 'array'],
        ]);

        $type = $data['type'] ?? null;
        if (in_array($type, ['select', 'radio', 'multiselect'], true) && FormQuestionSyncService::normalizeOptions($type, $data['options'] ?? null) === null) {
            throw ValidationException::withMessages(['options' => 'أضف خياراً واحداً على الأقل لهذا السؤال.']);
        }

        return $data;
    }

    private function assertDepartmentAllowed(Request $request, mixed $departmentId): void
    {
        if (! $this->access->canUseDepartment($request->user(), $departmentId === null ? null : (int) $departmentId)) {
            throw ValidationException::withMessages(['department_id' => 'لا تملك صلاحية إنشاء نماذج لهذا القسم.']);
        }
    }

    /** The requested link when it is free; otherwise the same link with a short random tail. Existing links never change. */
    private function uniqueSlug(?string $requested, string $title): string
    {
        $base = $requested !== null && $requested !== '' ? $requested : (Str::slug($title) ?: 'form');

        if (! DynamicForm::withTrashed()->where('slug', $base)->exists()) {
            return $base;
        }

        do {
            $candidate = Str::limit($base, 140, '').'-'.Str::lower(Str::random(5));
        } while (DynamicForm::withTrashed()->where('slug', $candidate)->exists());

        return $candidate;
    }
}
