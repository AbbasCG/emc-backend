<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormAnswerCorrection;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesFormResponses;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Correcting what a respondent submitted: who may, what is validated against, what is kept, and what can never be touched.
 */
class FormResponseCorrectionTest extends TestCase
{
    use MakesFormResponses;
    use MakesForms;
    use RefreshDatabase;

    private $deptA;

    private $deptB;

    private User $owner;

    private DynamicForm $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deptA = $this->makeDepartment('قسم أ');
        $this->deptB = $this->makeDepartment('قسم ب');
        $this->owner = $this->makeUser('operations_manager', $this->deptA);
        // Version 1 offers الرياض / جدة / مكة for the city and has a required name.
        $this->form = $this->createPublishedForm($this->owner, [
            ['type' => 'text', 'label' => 'الاسم', 'is_required' => true],
            ['type' => 'select', 'label' => 'المدينة', 'options' => ['الرياض', 'جدة', 'مكة']],
            ['type' => 'number', 'label' => 'العمر', 'config' => ['validation' => ['min' => 5, 'max' => 99, 'integer_only' => true]]],
            ['type' => 'checkbox', 'label' => 'الهوايات', 'options' => ['قراءة', 'سفر', 'رياضة']],
            ['type' => 'file', 'label' => 'السيرة'],
            ['type' => 'section', 'label' => 'قسم'],
        ]);
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function q(string $label): int
    {
        return $this->questionId($this->form, $label);
    }

    private function correct(FormSubmission $s, array $corrections, ?string $reason = null, ?User $user = null, ?DynamicForm $form = null)
    {
        return $this->as($user ?? $this->owner)->putJson('/api/operations/forms/'.($form ?? $this->form)->id."/submissions/{$s->id}/answers", ['corrections' => $corrections] + ($reason !== null ? ['reason' => $reason] : []));
    }

    private function share($user, string $level, array $grants): void
    {
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $user->id, 'access_level' => $level] + $grants)->assertCreated();
    }

    private function answer(FormSubmission $s, string $label): FormAnswer
    {
        return FormAnswer::query()->where('submission_id', $s->id)->where('question_id', $this->q($label))->firstOrFail();
    }

    // ── What is kept ─────────────────────────────────────────────────────────

    public function test_a_correction_changes_the_value_keeps_the_original_and_records_who_when_and_why(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة العتيبي', 'المدينة' => 'جدة', 'العمر' => '31']);

        $this->correct($s, [$this->q('الاسم') => '  سارة المطيري  '], 'تصحيح خطأ إملائي')->assertOk()->assertJsonPath('changed', 1)
            ->assertJsonPath('data.corrected', true);

        $answer = $this->answer($s, 'الاسم');
        $this->assertSame('سارة المطيري', $answer->value);
        $this->assertSame('سارة العتيبي', $answer->original_value);
        $this->assertSame($this->owner->id, (int) $answer->corrected_by);
        $this->assertNotNull($answer->corrected_at);
        $this->assertNotNull($s->refresh()->corrected_at);

        $row = FormAnswerCorrection::firstOrFail();
        $this->assertSame(['سارة العتيبي', 'سارة المطيري', 'تصحيح خطأ إملائي', $this->owner->id], [$row->previous_value, $row->new_value, $row->reason, (int) $row->corrected_by]);
        $this->assertNotNull($row->created_at);
        $this->assertSame($s->id, (int) $row->submission_id);
    }

    public function test_the_original_survives_further_corrections_and_every_step_is_in_the_history(): void
    {
        $other = $this->makeUser('operations_manager', $this->deptA);
        $this->share($other, 'editor', ['can_view_responses' => true, 'can_correct_responses' => true]);
        $s = $this->respond($this->form, ['الاسم' => 'أ']);

        $this->correct($s, [$this->q('الاسم') => 'ب'])->assertOk();
        $this->correct($s, [$this->q('الاسم') => 'ج'], null, $other)->assertOk();

        $answer = $this->answer($s, 'الاسم');
        $this->assertSame(['ج', 'أ'], [$answer->value, $answer->original_value]);
        $this->assertSame($other->id, (int) $answer->corrected_by);

        $history = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/submissions/{$s->id}/history")->assertOk()->json('data');
        $corrections = array_values(array_filter($history, fn ($h) => $h['type'] === 'corrected'));
        $this->assertCount(2, $corrections);
        $first = $corrections[0]['corrections'][0];
        $this->assertSame(['الاسم', 'أ', 'ب', $this->owner->id], [$first['question_label'], $first['previous'], $first['new'], $first['corrected_by']['id']]);
        $second = $corrections[1]['corrections'][0];
        $this->assertSame(['ب', 'ج', $other->id], [$second['previous'], $second['new'], $second['corrected_by']['id']]);
        $this->assertSame(['count' => 1, 'questions' => ['الاسم']], $corrections[0]['meta']);
    }

    public function test_unchanged_values_record_nothing(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة', 'العمر' => '31']);

        $this->correct($s, [$this->q('الاسم') => 'سارة', $this->q('العمر') => 31])->assertOk()->assertJsonPath('changed', 0)->assertJsonPath('data.corrected', false);

        $this->assertSame(0, FormAnswerCorrection::count());
        $this->assertNull($s->refresh()->corrected_at);
        $this->assertNull($this->answer($s, 'الاسم')->original_value);
        $this->assertSame(['submitted'], array_column($this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/submissions/{$s->id}/history")->json('data'), 'type'));
    }

    public function test_a_blank_original_is_preserved_as_blank_and_a_list_answer_is_kept_as_a_list(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);   // the city, age and hobbies were left empty

        $this->correct($s, [$this->q('المدينة') => 'مكة', $this->q('الهوايات') => ['قراءة', 'سفر']])->assertOk()->assertJsonPath('changed', 2);

        $this->assertNull($this->answer($s, 'المدينة')->original_value);
        $this->assertNotNull($this->answer($s, 'المدينة')->corrected_at, 'a correction of a blank answer is still recorded as one');
        $this->assertSame('["قراءة","سفر"]', $this->answer($s, 'الهوايات')->value);

        $history = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/submissions/{$s->id}/history")->json('data');
        $hobbies = collect($history[1]['corrections'])->firstWhere('question_label', 'الهوايات');
        $this->assertNull($hobbies['previous']);
        $this->assertSame(['قراءة', 'سفر'], $hobbies['new']);
        $this->assertSame('قراءة، سفر', $hobbies['new_display']);
    }

    // ── Validated against the version the respondent answered ────────────────

    public function test_a_correction_is_validated_against_the_exact_version_of_the_response_not_the_current_form(): void
    {
        $old = $this->respond($this->form, ['الاسم' => 'قديم', 'المدينة' => 'جدة']);
        // Version 2: «مكة» is no longer offered, «الدمام» is new, the name is optional and ages are limited differently.
        $this->republish($this->owner, $this->form, fn ($qs) => array_map(function ($q) {
            return match ($q['label']) {
                'المدينة' => ['options' => ['الرياض', 'جدة', 'الدمام']] + $q,
                'العمر'   => ['config' => ['validation' => ['min' => 18, 'max' => 40]]] + $q,
                default   => $q,
            };
        }, $qs));
        $new = $this->respond($this->form, ['الاسم' => 'جديد', 'المدينة' => 'الدمام']);

        // The response of version 1 can be corrected to what version 1 offered, even though version 2 dropped it…
        $this->correct($old, [$this->q('المدينة') => 'مكة'])->assertOk();
        $this->assertSame('مكة', $this->answer($old, 'المدينة')->value);
        // …and cannot be corrected to what only version 2 offers.
        $this->correct($old, [$this->q('المدينة') => 'الدمام'])->assertStatus(422)->assertJsonValidationErrors('answers.'.$this->q('المدينة'));
        $this->assertSame('مكة', $this->answer($old, 'المدينة')->value);
        // The age limits of version 1 apply to it (7 is fine there, not in version 2).
        $this->correct($old, [$this->q('العمر') => 7])->assertOk();
        $this->correct($new, [$this->q('العمر') => 7])->assertStatus(422);
        $this->correct($new, [$this->q('المدينة') => 'مكة'])->assertStatus(422);
        $this->correct($new, [$this->q('المدينة') => 'الدمام', $this->q('العمر') => 25])->assertOk();
    }

    public function test_the_published_version_is_never_changed_by_a_correction(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة', 'المدينة' => 'جدة']);
        $version = FormVersion::findOrFail($s->form_version_id);
        $before = [$version->schema_hash, json_encode($version->schema), $version->updated_at?->toDateTimeString()];
        $draftBefore = json_encode($this->form->refresh()->questions()->get(['id', 'label', 'options'])->toArray());

        $this->correct($s, [$this->q('المدينة') => 'مكة', $this->q('الاسم') => 'سارة المعدّلة'], 'سبب')->assertOk();

        $version->refresh();
        $this->assertSame($before, [$version->schema_hash, json_encode($version->schema), $version->updated_at?->toDateTimeString()]);
        $this->assertSame($draftBefore, json_encode($this->form->refresh()->questions()->get(['id', 'label', 'options'])->toArray()));
        $this->assertSame(1, FormVersion::where('form_id', $this->form->id)->count());

        $this->expectException(\LogicException::class);
        $version->update(['note' => 'changed']);
    }

    public function test_required_answers_cannot_be_emptied_and_bad_values_are_refused_with_the_questions_own_message(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة', 'العمر' => '30']);

        $this->correct($s, [$this->q('الاسم') => ''])->assertStatus(422)->assertJsonValidationErrors('answers.'.$this->q('الاسم'));
        $this->correct($s, [$this->q('العمر') => 200])->assertStatus(422);
        $this->correct($s, [$this->q('العمر') => 'abc'])->assertStatus(422);
        $this->correct($s, [$this->q('الهوايات') => ['طيران']])->assertStatus(422);
        // Optional answers can be cleared.
        $this->correct($s, [$this->q('العمر') => ''])->assertOk()->assertJsonPath('changed', 1);
        $this->assertNull($this->answer($s, 'العمر')->value);
        $this->assertSame('30', $this->answer($s, 'العمر')->original_value);
        // A refused correction changes nothing, even when another value in the same request was fine.
        $this->correct($s, [$this->q('الاسم') => 'جديد', $this->q('العمر') => 500])->assertStatus(422);
        $this->assertSame('سارة', $this->answer($s, 'الاسم')->value);
    }

    public function test_attachments_blocks_unknown_questions_and_an_empty_request_are_refused(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة', 'السيرة' => $this->pdfUpload('cv.pdf')]);
        $file = $this->answer($s, 'السيرة');

        $this->correct($s, [$this->q('السيرة') => 'x'])->assertStatus(422)->assertJsonValidationErrors('corrections.'.$this->q('السيرة'));
        $this->correct($s, [$this->q('قسم') => 'x'])->assertStatus(422);
        $this->correct($s, [999999 => 'x'])->assertStatus(422)->assertJsonValidationErrors('corrections');
        $this->correct($s, [])->assertStatus(422);
        $this->correct($s, [$this->q('الاسم') => 'ب'], str_repeat('ا', 501))->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame([$file->value, $file->file_path], [$this->answer($s, 'السيرة')->value, $this->answer($s, 'السيرة')->file_path]);
        $this->assertSame(0, FormAnswerCorrection::count());
    }

    // ── Which response, which form ───────────────────────────────────────────

    public function test_only_the_named_response_changes_and_a_response_of_another_form_is_not_reachable(): void
    {
        $a = $this->respond($this->form, ['الاسم' => 'أ']);
        $b = $this->respond($this->form, ['الاسم' => 'ب']);
        $other = $this->createPublishedForm($this->owner, [['type' => 'text', 'label' => 'س']]);
        $foreign = $this->respond($other, ['س' => 'خارجي']);

        $this->correct($a, [$this->q('الاسم') => 'أ-مصحّح'])->assertOk();
        $this->assertSame('ب', $this->answer($b, 'الاسم')->value);

        // The question and the response must belong to this form: a question id of another form is refused, and so is its response.
        $this->correct($foreign, [$this->q('الاسم') => 'x'])->assertNotFound();
        $this->correct($a, [$this->questionId($other, 'س') => 'x'])->assertStatus(422);
        $this->assertSame('خارجي', FormAnswer::where('submission_id', $foreign->id)->firstOrFail()->value);
        $this->assertSame(1, FormAnswerCorrection::count());
    }

    // ── Who may correct ──────────────────────────────────────────────────────

    public function test_reading_or_editing_never_implies_the_right_to_correct(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);
        $viewer = $this->makeUser('community_manager', $this->deptB);
        $editorWithResponses = $this->makeUser('operations_manager', $this->deptB);
        $editorWithoutResponses = $this->makeUser('community_manager', $this->deptB);
        $this->share($viewer, 'viewer', ['can_view_responses' => true]);
        $this->share($editorWithResponses, 'editor', ['can_view_responses' => true]);
        $this->share($editorWithoutResponses, 'editor', ['can_view_responses' => false]);
        $outsider = $this->makeUser('operations_manager', $this->deptB);

        foreach ([$viewer, $editorWithResponses, $editorWithoutResponses, $outsider] as $person) {
            $this->correct($s, [$this->q('الاسم') => 'مخترَق'], null, $person)->assertForbidden();
        }
        $this->assertSame('سارة', $this->answer($s, 'الاسم')->value);

        // The editor who may read and review still sees that they cannot correct.
        $detail = $this->as($editorWithResponses)->getJson("/api/operations/forms/{$this->form->id}/submissions/{$s->id}")->assertOk();
        $this->assertTrue($detail->json('data.abilities.review'));
        $this->assertFalse($detail->json('data.abilities.correct'));
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/operations/forms/{$this->form->id}/submissions/{$s->id}/answers", ['corrections' => [1 => 'x']])->assertUnauthorized();
    }

    public function test_the_owner_platform_admins_and_explicitly_granted_collaborators_may_correct(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);
        $admin = $this->makeUser('admin');
        $granted = $this->makeUser('community_manager', $this->deptB);
        $this->share($granted, 'viewer', ['can_view_responses' => true, 'can_correct_responses' => true]);

        foreach ([$this->owner, $admin, $granted] as $i => $person) {
            $this->correct($s, [$this->q('الاسم') => "م$i"], null, $person)->assertOk();
            $this->assertSame("م$i", $this->answer($s, 'الاسم')->value);
        }
    }

    public function test_the_correction_grant_is_separate_needs_response_access_and_can_be_withdrawn_at_once(): void
    {
        $s = $this->respond($this->form, ['الاسم' => 'سارة']);
        $person = $this->makeUser('community_manager', $this->deptB);

        // Cannot be granted without the right to read the responses.
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $person->id, 'access_level' => 'editor', 'can_view_responses' => false, 'can_correct_responses' => true])
            ->assertStatus(422)->assertJsonValidationErrors('can_correct_responses');

        $id = $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $person->id, 'access_level' => 'viewer', 'can_view_responses' => true, 'can_correct_responses' => true])
            ->assertCreated()->assertJsonPath('data.can_correct_responses', true)->json('data.id');
        $this->correct($s, [$this->q('الاسم') => 'أ'], null, $person)->assertOk();

        // Withdrawing the grant (keeping the rest) closes it immediately.
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/collaborators/$id", ['access_level' => 'viewer', 'can_view_responses' => true, 'can_correct_responses' => false])
            ->assertOk()->assertJsonPath('data.can_correct_responses', false);
        $this->correct($s, [$this->q('الاسم') => 'ب'], null, $person)->assertForbidden();

        // Taking away the right to read takes the right to correct with it, even when the grant is sent as true.
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/collaborators/$id", ['access_level' => 'viewer', 'can_view_responses' => true, 'can_correct_responses' => true])->assertOk();
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/collaborators/$id", ['access_level' => 'viewer', 'can_view_responses' => false])->assertOk()->assertJsonPath('data.can_correct_responses', false);
        $this->correct($s, [$this->q('الاسم') => 'ج'], null, $person)->assertForbidden();
        $this->assertSame('أ', $this->answer($s, 'الاسم')->value);
    }

    public function test_a_collaborator_cannot_hand_on_a_grant_they_do_not_hold(): void
    {
        // Only the owner and platform admins manage access, so an editor has no route to hand anything on…
        $editor = $this->makeUser('operations_manager', $this->deptB);
        $this->share($editor, 'editor', ['can_view_responses' => true]);
        $target = $this->makeUser('community_manager', $this->deptB);
        $this->as($editor)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $target->id, 'access_level' => 'viewer', 'can_view_responses' => true, 'can_correct_responses' => true])->assertForbidden();

        // …and an admin who can neither read nor correct cannot grant it either (the service refuses even when called directly).
        $this->assertFalse($this->app->make(\App\Services\Forms\FormAccessService::class)->canCorrectResponses($editor, $this->form));
    }
}
