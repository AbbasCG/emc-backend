<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormQuestion;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\Logic\FormLogic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishing and the status of a form.
 *
 * The working draft (the form row and its questions) is edited freely; respondents are served from the PUBLISHED VERSION
 * only. Publishing freezes the draft into a new immutable version and makes it the one in use; nothing else ever changes
 * what a respondent sees. Close, reopen, unpublish and archive change availability and never touch a version.
 *
 * Every transition takes a row lock on the form, so two people publishing, or a publish racing a close, are serialised and
 * the second one sees the first one's result. Every transition writes its history entry inside the same transaction: if the
 * change does not persist, neither does the entry.
 */
class FormLifecycleService
{
    public function __construct(private readonly FormSnapshotService $snapshots) {}

    /**
     * Publish the draft. Creates a new version when the draft differs from the published one; when it does not, a form that
     * is not live simply goes live again on the version it already has (no duplicate copy), and a form that is already
     * live with nothing new to publish is refused.
     */
    public function publish(DynamicForm $form, User $actor, ?string $note = null, ?FormVersion $restoredFrom = null, ?Request $request = null): DynamicForm
    {
        return DB::transaction(function () use ($form, $actor, $note, $restoredFrom, $request) {
            $locked = $this->lock($form);
            $questions = $locked->questions()->get();
            $this->assertPublishable($questions);

            $content = FormSnapshotService::content($locked->getAttributes(), $questions);
            $hash = FormSnapshotService::hash($content);
            $current = $locked->publishedVersion;
            $previousStatus = $locked->status;

            if ($current !== null && $current->schema_hash === $hash) {
                if ($previousStatus === 'active') {
                    throw ValidationException::withMessages(['status' => 'لا توجد تغييرات جديدة لنشرها. النسخة المنشورة مطابقة للمسودة.']);
                }
                $locked->forceFill(['status' => 'active'])->save();
                FormActivityService::record($locked, $actor, $previousStatus === 'closed' ? 'reopened' : 'published', ['version' => $current->version_number, 'status_from' => $previousStatus, 'status_to' => 'active'], $request);

                return $locked->refresh();
            }

            $number = ((int) FormVersion::query()->where('form_id', $locked->id)->max('version_number')) + 1;
            $version = FormVersion::create([
                'form_id'                  => $locked->id,
                'version_number'           => $number,
                'schema'                   => FormSnapshotService::build($locked->getAttributes(), $questions, FormSnapshotService::settingsRecord($locked->getAttributes())),
                'schema_hash'              => $hash,
                'question_count'           => $questions->count(),
                'origin'                   => $restoredFrom ? 'restore' : 'publish',
                'note'                     => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                'created_by'               => $actor->id,
                'restored_from_version_id' => $restoredFrom?->id,
                'published_at'             => now(),
            ]);

            $locked->forceFill([
                'status'               => 'active',
                'published_version_id' => $version->id,
                'published_by'         => $actor->id,
                'published_at'         => $version->published_at,
            ])->save();

            FormActivityService::record($locked, $actor, 'published', [
                'version' => $number, 'question_count' => $questions->count(), 'note' => $version->note, 'status_from' => $previousStatus, 'status_to' => 'active',
            ], $request);

            return $locked->refresh();
        });
    }

    public function close(DynamicForm $form, User $actor, ?Request $request = null): DynamicForm
    {
        return $this->change($form, $actor, 'closed', 'closed', fn (DynamicForm $f) => $f->status === 'active', 'النموذج غير منشور حالياً، فلا يمكن إغلاقه.', $request);
    }

    /** Reopens a closed form on the version it already has. Unpublished edits stay in the draft; publishing them is a separate step. */
    public function reopen(DynamicForm $form, User $actor, ?Request $request = null): DynamicForm
    {
        return $this->change(
            $form, $actor, 'active', 'reopened',
            fn (DynamicForm $f) => $f->status === 'closed' && $f->published_version_id !== null,
            'لا يمكن إعادة فتح هذا النموذج: ليس مغلقاً أو لم يُنشر من قبل.',
            $request,
        );
    }

    public function unpublish(DynamicForm $form, User $actor, ?Request $request = null): DynamicForm
    {
        return $this->change($form, $actor, 'draft', 'unpublished', fn (DynamicForm $f) => in_array($f->status, ['active', 'closed'], true), 'النموذج ليس منشوراً.', $request);
    }

    public function archive(DynamicForm $form, User $actor, ?Request $request = null): DynamicForm
    {
        return $this->change($form, $actor, 'archived', 'archived', fn (DynamicForm $f) => $f->status !== 'archived', 'النموذج مؤرشف بالفعل.', $request);
    }

    /** An archived form comes back closed (if it was ever published) or as a draft; it never silently goes live. */
    public function unarchive(DynamicForm $form, User $actor, ?Request $request = null): DynamicForm
    {
        return DB::transaction(function () use ($form, $actor, $request) {
            $locked = $this->lock($form);
            if ($locked->status !== 'archived') {
                throw ValidationException::withMessages(['status' => 'النموذج ليس مؤرشفاً.']);
            }
            $to = $locked->published_version_id !== null ? 'closed' : 'draft';
            $locked->forceFill(['status' => $to])->save();
            FormActivityService::record($locked, $actor, 'unarchived', ['status_from' => 'archived', 'status_to' => $to], $request);

            return $locked->refresh();
        });
    }

    /**
     * The Phase 0 way of changing status (a `status` field on the update request), mapped onto the same transitions so
     * nothing can reach `active` without publishing, and nothing leaves it without a recorded action.
     */
    public function applyStatus(DynamicForm $form, User $actor, string $target, ?Request $request = null): DynamicForm
    {
        if ($form->status === $target) {
            return $form;
        }

        return match ($target) {
            'active'   => $this->publish($form, $actor, null, null, $request),
            'closed'   => $form->status === 'active' ? $this->close($form, $actor, $request) : $this->forceStatus($form, $actor, 'closed', 'closed', $request),
            'draft'    => in_array($form->status, ['active', 'closed'], true) ? $this->unpublish($form, $actor, $request) : $this->forceStatus($form, $actor, 'draft', 'unpublished', $request),
            'archived' => $this->archive($form, $actor, $request),
            default    => throw ValidationException::withMessages(['status' => 'حالة النموذج غير صالحة.']),
        };
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function change(DynamicForm $form, User $actor, string $to, string $action, callable $allowed, string $refusal, ?Request $request): DynamicForm
    {
        return DB::transaction(function () use ($form, $actor, $to, $action, $allowed, $refusal, $request) {
            $locked = $this->lock($form);
            if (! $allowed($locked)) {
                throw ValidationException::withMessages(['status' => $refusal]);
            }
            $from = $locked->status;
            $locked->forceFill(['status' => $to])->save();
            FormActivityService::record($locked, $actor, $action, ['status_from' => $from, 'status_to' => $to], $request);

            return $locked->refresh();
        });
    }

    private function forceStatus(DynamicForm $form, User $actor, string $to, string $action, ?Request $request): DynamicForm
    {
        return $this->change($form, $actor, $to, $action, fn () => true, '', $request);
    }

    private function lock(DynamicForm $form): DynamicForm
    {
        return DynamicForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();
    }

    /** @param  \Illuminate\Support\Collection<int, FormQuestion>  $questions */
    private function assertPublishable($questions): void
    {
        if ($questions->isEmpty()) {
            throw ValidationException::withMessages(['status' => 'أضف سؤالاً واحداً على الأقل قبل نشر النموذج.']);
        }

        // Headings, descriptions and page breaks alone are not a form: somebody must be able to answer something.
        if ($questions->every(fn ($q) => $q->isStructural())) {
            throw ValidationException::withMessages(['status' => 'أضف سؤالاً واحداً على الأقل يمكن الإجابة عنه قبل نشر النموذج.']);
        }

        foreach ($questions as $q) {
            if (in_array($q->type, ['select', 'radio', 'multiselect'], true) && $q->allowedOptions() === []) {
                throw ValidationException::withMessages(['status' => "السؤال «{$q->label}» يحتاج خياراً واحداً على الأقل قبل النشر."]);
            }
        }

        $this->assertLogicSound($questions);
    }

    /**
     * A form with visibility rules is published only when every rule still means something and the rules do not tangle: each one
     * reads a block that exists and that comes BEFORE it, with an operator and a value that suit that block as it is now (its
     * options may have changed since the rule was written), and no chain of rules leads back to itself.
     *
     * @param  \Illuminate\Support\Collection<int, FormQuestion>  $questions  in document order
     */
    private function assertLogicSound($questions): void
    {
        $blocks = $questions->map(fn (FormQuestion $q) => ['id' => (int) $q->id, 'type' => $q->type, 'label' => $q->label, 'options' => $q->allowedOptions(), 'logic' => $q->logic])->values()->all();
        $sources = [];
        foreach ($blocks as $b) {
            $sources[$b['id']] = ['type' => $b['type'], 'options' => $b['options']];
        }

        foreach ($blocks as $b) {
            if (empty($b['logic']['conditions'])) {
                continue;
            }
            [, $errors] = FormLogic::normalize($b['logic'], $sources);
            if ($errors !== []) {
                $name = trim((string) $b['label']) !== '' ? $b['label'] : $b['type'];
                throw ValidationException::withMessages(['status' => "شرط «{$name}» غير صالح: ".reset($errors).' عدّل الشرط ثم انشر.']);
            }
        }

        $issues = FormLogic::issues($blocks);
        if ($issues !== []) {
            throw ValidationException::withMessages(['status' => $issues[0]['message'].(count($issues) > 1 ? ' (و'.(count($issues) - 1).' مشكلة أخرى في الشروط)' : '')]);
        }
    }
}
