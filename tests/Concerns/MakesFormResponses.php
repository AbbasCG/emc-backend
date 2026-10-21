<?php

namespace Tests\Concerns;

use App\Models\DynamicForm;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\Forms\FormSnapshotService;
use App\Services\Forms\FormSubmissionService;
use Illuminate\Support\Arr;

/** Published forms with versions, and responses stored the way a respondent's would be, for the response-management tests. */
trait MakesFormResponses
{
    /** @param  list<array<string, mixed>>  $questions */
    protected function createPublishedForm(User $owner, array $questions, array $attributes = []): DynamicForm
    {
        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/operations/forms', ['title' => 'استبيان الردود', 'status' => 'active', 'questions' => $questions] + $attributes)
            ->assertCreated()->json('data.id');

        return DynamicForm::findOrFail($id);
    }

    /**
     * Changes the form's questions (the callback receives the current ones, with ids, and returns the new list) and publishes the
     * result as a new version.
     */
    protected function republish(User $owner, DynamicForm $form, callable $change): void
    {
        $current = $this->actingAs($owner, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertOk()->json('data.questions');
        $this->actingAs($owner, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['questions' => $change($current)])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson("/api/operations/forms/{$form->id}/publish")->assertOk();
    }

    /**
     * Stores a response against the form's CURRENT published version. Answers are keyed by the question's label as it is in that version.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $attributes  user_id, share_link_id, or any submission column (status, submitted_at...)
     */
    protected function respond(DynamicForm $form, array $answers, array $attributes = []): FormSubmission
    {
        $form->refresh();
        $version = $form->publishedVersion;
        $questions = FormSnapshotService::questionModels($version->schema);
        $byLabel = $questions->keyBy('label');
        $byId = [];
        foreach ($answers as $label => $value) {
            $byId[$byLabel[$label]->id] = $value;
        }

        $service = app(FormSubmissionService::class);
        $validated = $service->validate($form, ['answers' => $byId], $questions);
        $submission = $service->store($form, $validated, $attributes['user_id'] ?? null, $questions, $version->id, $attributes['share_link_id'] ?? null);

        $rest = Arr::except($attributes, ['user_id', 'share_link_id']);
        if ($rest !== []) {
            $submission->forceFill($rest)->save();
        }

        return $submission->refresh();
    }

    /** The id of the question with this label in the form's current published version. */
    protected function questionId(DynamicForm $form, string $label): int
    {
        return (int) FormSnapshotService::questionModels($form->refresh()->publishedVersion->schema)->firstWhere('label', $label)->id;
    }
}
