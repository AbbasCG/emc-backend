<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Http\Resources\FormAnswerResource;
use App\Models\FormAnswer;
use App\Models\FormAnswerCorrection;
use App\Models\FormQuestion;
use App\Models\FormSubmission;
use App\Models\FormSubmissionEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correcting what a respondent submitted.
 *
 * A correction is checked against the questions of the exact published version the respondent filled in (never the live draft,
 * never a later edit of the form), with the same rules a respondent was held to. The published version itself is read-only here:
 * nothing in `form_versions` is touched. The value as first submitted is kept in `form_answers.original_value`, and every
 * correction adds a protected history row (previous value, new value, who, when, why). A person may only correct a response of the
 * form they are authorized on; the controller binds the submission to the form before calling this.
 *
 * Attachments cannot be corrected (a file is evidence, not text); structural blocks and questions that are not in the response's
 * version are refused.
 */
class FormResponseCorrectionService
{
    public function __construct(
        private readonly FormSubmissionService $submissions,
        private readonly FormResponseWorkflowService $workflow,
    ) {}

    /**
     * @param  array<int|string, mixed>  $corrections  question id => new value
     * @return array{changed: int}
     *
     * @throws ValidationException
     */
    public function correct(DynamicForm $form, FormSubmission $submission, User $actor, array $corrections, ?string $reason, ?Request $request = null): array
    {
        if ($corrections === []) {
            throw ValidationException::withMessages(['corrections' => 'لم تُحدَّد أي إجابة للتصحيح.']);
        }
        $reason = $reason !== null ? mb_substr(trim($reason), 0, FormAnswerCorrection::REASON_MAX) : null;
        $reason = $reason === '' ? null : $reason;

        $questions = $this->questionsOf($form, $submission)->keyBy('id');
        $subset = $this->subset($questions, $corrections);

        // The same rules a respondent was held to, for the corrected questions only: a required answer cannot be emptied, a choice
        // must be one the version offered. Failures come back keyed by `answers.{id}` like a submission's.
        $validated = $this->submissions->validate($form, ['answers' => $this->raw($corrections)], $subset->values(), applyLogic: false);
        $this->assertStillVisible($questions, $submission, $subset, $validated['answers']);

        $changed = DB::transaction(function () use ($submission, $actor, $subset, $validated, $reason) {
            $locked = FormSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $answers = FormAnswer::query()->where('submission_id', $locked->id)->get()->keyBy('question_id');
            $event = null;
            $labels = [];

            foreach ($subset as $question) {
                $new = $this->submissions->storableValue($question, $validated['answers'][$question->id] ?? null);
                $answer = $answers->get($question->id) ?? new FormAnswer(['submission_id' => $locked->id, 'question_id' => $question->id, 'value' => null]);
                $previous = $answer->value === '' ? null : $answer->value;
                if ($previous === $new) {
                    continue;
                }

                $event ??= $this->workflow->event($locked, $actor, FormSubmissionEvent::CORRECTED, null, null);
                $labels[] = mb_substr($question->label, 0, 80);

                if (! $answer->exists) {
                    $answer->save();
                }
                if (! $answer->isCorrected()) {
                    // The value as the respondent submitted it, kept for good.
                    $answer->original_value = $previous;
                }
                $answer->forceFill(['value' => $new, 'corrected_at' => now(), 'corrected_by' => $actor->id])->save();

                FormAnswerCorrection::create([
                    'submission_id' => $locked->id, 'answer_id' => $answer->id, 'event_id' => $event->id, 'question_id' => $question->id,
                    'previous_value' => $previous, 'new_value' => $new, 'corrected_by' => $actor->id, 'reason' => $reason,
                ]);
            }

            if ($event !== null) {
                $event->forceFill(['meta' => ['count' => count($labels), 'questions' => array_slice($labels, 0, 10)]])->save();
                $locked->forceFill(['corrected_at' => now()])->save();
            }

            return count($labels);
        });

        if ($changed > 0) {
            FormActivityService::record($form, $actor, 'response_corrected', ['count' => $changed, 'submission' => (string) $submission->id], $request);
        }

        return ['changed' => $changed];
    }

    /**
     * A question the form's rules hide for this response has no answer to correct. Visibility is judged on the response's own
     * answers with the corrections applied, by the same engine that judged the submission.
     *
     * @param  Collection<int, FormQuestion>  $questions  all of the response's questions, by id
     * @param  Collection<int, FormQuestion>  $subset  the ones being corrected
     * @param  array<int|string, mixed>  $corrected  the validated new values
     */
    private function assertStillVisible(Collection $questions, FormSubmission $submission, Collection $subset, array $corrected): void
    {
        if ($questions->every(fn (FormQuestion $q) => empty($q->logic))) {
            return;
        }

        $stored = FormAnswer::query()->where('submission_id', $submission->id)->get()->keyBy('question_id');
        $answers = [];
        foreach ($questions as $q) {
            $row = $stored->get($q->id);
            if ($row !== null) {
                $answers[(int) $q->id] = $row->file_path ? true : ($q->isMulti() ? FormAnswerResource::decodeList($row->value) : $row->value);
            }
        }
        foreach ($corrected as $id => $value) {
            $answers[(int) $id] = $value;
        }

        [, , $hidden] = $this->submissions->applyVisibility($questions->values(), $answers);
        foreach ($subset as $question) {
            if (in_array((int) $question->id, $hidden, true)) {
                throw ValidationException::withMessages(["corrections.{$question->id}" => 'هذا السؤال مخفي في هذا الرد بحسب شروط النموذج، فلا إجابة لتصحيحها.']);
            }
        }
    }

    /**
     * The questions this response was answered against: its published version's, frozen. A response that predates versions and
     * could not be linked to one falls back to the form's questions (removed ones included, they still have answers).
     *
     * @return Collection<int, FormQuestion>
     */
    public function questionsOf(DynamicForm $form, FormSubmission $submission): Collection
    {
        $version = $submission->form_version_id !== null
            ? $submission->version ?? $submission->load('version')->version
            : null;

        // A version that belongs to another form is never trusted.
        if ($version !== null && (int) $version->form_id === (int) $form->id) {
            return FormSnapshotService::questionModels($version->schema);
        }

        return $form->questions()->withTrashed()->get();
    }

    /**
     * @param  Collection<int, FormQuestion>  $questions  by id
     * @param  array<int|string, mixed>  $corrections
     * @return Collection<int, FormQuestion>
     */
    private function subset(Collection $questions, array $corrections): Collection
    {
        $subset = collect();
        foreach (array_keys($corrections) as $key) {
            $id = is_int($key) || ctype_digit((string) $key) ? (int) $key : 0;
            $question = $questions->get($id);
            if ($question === null) {
                throw ValidationException::withMessages(['corrections' => 'أحد الأسئلة المحدّدة لا ينتمي إلى النموذج الذي أُرسل هذا الرد عليه.']);
            }
            if ($question->isStructural()) {
                throw ValidationException::withMessages(["corrections.{$id}" => 'هذا العنصر ليس سؤالاً ولا يحمل إجابة.']);
            }
            if (in_array($question->type, ['file', 'image'], true)) {
                throw ValidationException::withMessages(["corrections.{$id}" => 'لا يمكن تصحيح المرفقات. أبقِ الملف كما أُرسل وأضف ملاحظة داخلية إن لزم.']);
            }
            $subset->put($id, $question);
        }

        return $subset;
    }

    /** @return array<int, mixed> */
    private function raw(array $corrections): array
    {
        $out = [];
        foreach ($corrections as $id => $value) {
            $out[(int) $id] = $value;
        }

        return $out;
    }
}
