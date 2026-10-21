<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DynamicFormResource;
use App\Http\Resources\PublicFormResource;
use App\Models\DynamicForm;
use App\Services\Forms\FormAccessService;
use App\Services\Forms\FormActivityService;
use App\Services\Forms\FormCollaborationService;
use App\Services\Forms\FormLifecycleService;
use App\Services\Forms\FormSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Publishing, availability, ownership and the draft preview of one form.
 *
 * Every action authorizes against the form (see FormAccessService). Visibility and ownership are access decisions and
 * belong to the owner and platform-wide admins; schedule, limits and the lifecycle belong to anyone who can change the form.
 */
class FormLifecycleController extends Controller
{
    private const SETTING_LABELS = [
        'opens_at' => 'موعد الفتح', 'closes_at' => 'موعد الإغلاق', 'max_submissions' => 'الحد الأقصى للإجابات',
        'per_respondent_limit' => 'حد الإجابات لكل شخص', 'allow_anonymous' => 'السماح بالإجابة دون تسجيل دخول',
    ];

    public function __construct(
        private readonly FormAccessService $access,
        private readonly FormLifecycleService $lifecycle,
        private readonly FormSnapshotService $snapshots,
        private readonly FormCollaborationService $collaboration,
    ) {}

    public function publish(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('publish', $form);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return $this->respond($this->lifecycle->publish($form, $request->user(), $data['note'] ?? null, null, $request), $request);
    }

    public function close(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('publish', $form);

        return $this->respond($this->lifecycle->close($form, $request->user(), $request), $request);
    }

    public function reopen(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('publish', $form);

        return $this->respond($this->lifecycle->reopen($form, $request->user(), $request), $request);
    }

    public function unpublish(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('publish', $form);

        return $this->respond($this->lifecycle->unpublish($form, $request->user(), $request), $request);
    }

    public function archive(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('publish', $form);

        return $this->respond($this->lifecycle->archive($form, $request->user(), $request), $request);
    }

    public function unarchive(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('publish', $form);

        return $this->respond($this->lifecycle->unarchive($form, $request->user(), $request), $request);
    }

    /** The DRAFT as a respondent would see it, for checking before publishing. Nothing is stored and nobody can answer it. */
    public function preview(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('view', $form);

        $content = FormSnapshotService::content($form->getAttributes(), $form->questions()->get());
        $availability = ['state' => 'open', 'message' => null, 'opens_at' => null, 'closes_at' => null, 'requires_login' => false];

        return response()->json([
            'success' => true,
            'data'    => PublicFormResource::present($form, $content, $availability, null, preview: true),
        ]);
    }

    /**
     * Availability (schedule, limits, sign-in) and visibility. Availability applies immediately and is not versioned: closing
     * on a different day does not change a single question. Visibility is an access decision (owner / platform admins only).
     */
    public function settings(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('update', $form);

        $data = $request->validate([
            'visibility'           => ['sometimes', Rule::in(DynamicForm::VISIBILITIES)],
            'responses_visibility' => ['sometimes', Rule::in(DynamicForm::RESPONSE_VISIBILITIES)],
            'opens_at'             => ['sometimes', 'nullable', 'date'],
            'closes_at'            => ['sometimes', 'nullable', 'date'],
            'max_submissions'      => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'per_respondent_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'allow_anonymous'      => ['sometimes', 'boolean'],
        ], [
            'opens_at.date' => 'موعد الفتح غير صالح.', 'closes_at.date' => 'موعد الإغلاق غير صالح.',
            'max_submissions.min' => 'الحد الأقصى للإجابات يجب أن يكون 1 على الأقل.', 'max_submissions.integer' => 'الحد الأقصى للإجابات يجب أن يكون رقماً صحيحاً.',
            'per_respondent_limit.min' => 'حد الإجابات لكل شخص يجب أن يكون 1 على الأقل.', 'per_respondent_limit.integer' => 'حد الإجابات لكل شخص يجب أن يكون رقماً صحيحاً.',
            'visibility.in' => 'قيمة ظهور النموذج غير صالحة.', 'responses_visibility.in' => 'قيمة ظهور الإجابات غير صالحة.',
        ]);

        $touchesAccess = array_key_exists('visibility', $data) || array_key_exists('responses_visibility', $data);
        if ($touchesAccess) {
            $this->authorize('manageAccess', $form);
        }

        $opens = array_key_exists('opens_at', $data) ? $data['opens_at'] : $form->opens_at;
        $closes = array_key_exists('closes_at', $data) ? $data['closes_at'] : $form->closes_at;
        if ($opens !== null && $closes !== null && strtotime((string) $closes) <= strtotime((string) $opens)) {
            throw ValidationException::withMessages(['closes_at' => 'يجب أن يكون موعد الإغلاق بعد موعد الفتح.']);
        }

        $actor = $request->user();
        $form = DB::transaction(function () use ($request, $form, $data, $actor) {
            $locked = DynamicForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();
            $before = $locked->getAttributes();
            $locked->fill($data);
            $dirty = array_keys($locked->getDirty());
            if ($dirty === []) {
                return $locked;
            }

            $locked->last_edited_by = $actor->id;
            $locked->save();

            $accessFields = array_values(array_intersect($dirty, ['visibility', 'responses_visibility']));
            if ($accessFields !== []) {
                $labels = ['visibility' => 'ظهور النموذج', 'responses_visibility' => 'ظهور الإجابات'];
                FormActivityService::record($locked, $actor, 'visibility_changed', [
                    'fields' => array_map(fn ($f) => $labels[$f], $accessFields),
                    'changes' => collect($accessFields)->map(fn ($f) => $labels[$f].': '.($before[$f] ?? '—').' ← '.$locked->{$f})->all(),
                ], $request);
            }
            $settingFields = array_values(array_diff($dirty, ['visibility', 'responses_visibility', 'updated_at', 'last_edited_by']));
            if ($settingFields !== []) {
                FormActivityService::record($locked, $actor, 'settings_changed', ['fields' => array_map(fn ($f) => self::SETTING_LABELS[$f] ?? $f, $settingFields)], $request);
            }

            return $locked->refresh();
        });

        return $this->respond($form, $request);
    }

    /** Moves ownership (owner or platform-wide admin only). The creator never changes. */
    public function ownership(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('manageAccess', $form);

        $data = $request->validate([
            'owner_id'    => ['required', 'integer'],
            'keep_access' => ['nullable', Rule::in(['none', 'viewer', 'editor'])],
            'keep_responses' => ['sometimes', 'boolean'],
        ], ['owner_id.required' => 'اختر المالك الجديد.']);

        $updated = $this->collaboration->transferOwnership(
            $form, $request->user(), (int) $data['owner_id'], $data['keep_access'] ?? null, (bool) ($data['keep_responses'] ?? false), $request,
        );

        // Giving the form away can mean the giver no longer has any access to it; then nothing about it is sent back.
        if (! $this->access->canView($request->user(), $updated)) {
            return response()->json(['success' => true, 'data' => ['id' => $updated->id, 'access_removed' => true]]);
        }

        return $this->respond($updated, $request);
    }

    private function respond(DynamicForm $form, Request $request): JsonResponse
    {
        $form->load(['department', 'createdBy', 'owner', 'lastEditor', 'publisher', 'publishedVersion', 'questions'])
            ->loadCount('submissions')
            ->loadMax('submissions', 'submitted_at');

        return response()->json([
            'success' => true,
            'data'    => (new DynamicFormResource($form))->withDraftState($this->snapshots->hasUnpublishedChanges($form)),
        ]);
    }
}
