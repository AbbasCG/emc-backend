<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormSubmission;
use App\Models\FormSubmissionEvent;
use App\Models\FormSubmissionNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The internal life of a response: its review status, who it is assigned to, and the notes staff leave on it. Every change that
 * matters writes a line to the response's own history in the same transaction, so what the history says is what happened.
 *
 * Callers have already been authorized (FormAccessService::canManageResponses). The rules that hold regardless of the caller are
 * here: an assignee must be someone who could review the response themselves, a status must be a known one, and notes are
 * plain bounded text that is never edited afterwards.
 */
class FormResponseWorkflowService
{
    public function __construct(private readonly FormAccessService $access) {}

    /** @return array{changed: bool} */
    public function changeStatus(FormSubmission $submission, User $actor, string $status): array
    {
        $status = FormSubmission::LEGACY_STATUS[$status] ?? $status;
        if (! in_array($status, FormSubmission::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'حالة الرد غير صالحة.']);
        }

        return DB::transaction(function () use ($submission, $actor, $status) {
            $locked = FormSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $from = FormSubmission::LEGACY_STATUS[$locked->status] ?? $locked->status;
            if ($from === $status) {
                return ['changed' => false];
            }

            $locked->forceFill(['status' => $status])->save();
            $this->event($locked, $actor, FormSubmissionEvent::STATUS_CHANGED, $from, $status);
            $submission->setRawAttributes($locked->getAttributes(), true);

            return ['changed' => true];
        });
    }

    /** @return array{changed: bool} */
    public function assign(FormSubmission $submission, User $actor, ?int $assigneeId): array
    {
        $assignee = null;
        if ($assigneeId !== null) {
            $form = $submission->form ?? DynamicForm::query()->findOrFail($submission->form_id);
            $assignee = $this->access->assignableEmployees($form)->firstWhere('id', $assigneeId);
            if ($assignee === null) {
                throw ValidationException::withMessages(['assignee_id' => 'لا يمكن إسناد الرد لهذا الموظف: يجب أن يملك صلاحية مراجعة ردود هذا النموذج.']);
            }
        }

        return DB::transaction(function () use ($submission, $actor, $assignee) {
            $locked = FormSubmission::query()->whereKey($submission->id)->lockForUpdate()->with('assignee:id,name')->firstOrFail();
            if (($locked->assigned_to === null && $assignee === null) || ($assignee !== null && (int) $locked->assigned_to === (int) $assignee->id)) {
                return ['changed' => false];
            }

            $previous = $locked->assignee?->name;
            $locked->forceFill(['assigned_to' => $assignee?->id, 'assigned_at' => $assignee ? now() : null])->save();
            $this->event(
                $locked, $actor,
                $assignee ? FormSubmissionEvent::ASSIGNED : FormSubmissionEvent::UNASSIGNED,
                $previous,
                $assignee?->name,
            );
            $submission->setRawAttributes($locked->getAttributes(), true);

            return ['changed' => true];
        });
    }

    public function addNote(FormSubmission $submission, User $actor, string $body): FormSubmissionNote
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > FormSubmissionNote::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'اكتب ملاحظة لا يتجاوز طولها '.FormSubmissionNote::MAX_LENGTH.' حرف.']);
        }

        return DB::transaction(function () use ($submission, $actor, $body) {
            $note = FormSubmissionNote::create(['submission_id' => $submission->id, 'user_id' => $actor->id, 'body' => $body]);
            $this->event($submission, $actor, FormSubmissionEvent::NOTE_ADDED, null, null);

            return $note;
        });
    }

    public function event(FormSubmission $submission, User $actor, string $type, ?string $from, ?string $to, ?array $meta = null): FormSubmissionEvent
    {
        return FormSubmissionEvent::create([
            'submission_id' => $submission->id, 'user_id' => $actor->id, 'type' => $type,
            'from_value' => $from, 'to_value' => $to, 'meta' => $meta,
        ]);
    }
}
