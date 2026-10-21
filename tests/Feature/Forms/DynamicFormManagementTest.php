<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormQuestion;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Creating and editing forms through the API the form builder uses (/operations/forms) and the fuller admin API.
 * Everything here goes through the real controllers, requests, services and database.
 */
class DynamicFormManagementTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    /** The exact shape FormBuilder sends: form_type, required, temporary client ids, sort_order. */
    private function builderPayload(array $over = []): array
    {
        return array_merge([
            'title'       => 'استبيان رضا الطلاب',
            'description' => 'وصف',
            'form_type'   => 'suggestion',
            'slug'        => 'student-satisfaction',
            'questions'   => [
                ['id' => 1, 'label' => 'الاسم الكامل', 'type' => 'text', 'required' => true, 'sort_order' => 1],
                ['id' => 2, 'label' => 'التقييم', 'type' => 'radio', 'required' => false, 'sort_order' => 2, 'options' => ['ممتاز', 'جيد']],
            ],
        ], $over);
    }

    // ── Creating ─────────────────────────────────────────────────────────────

    public function test_creating_a_form_saves_its_questions_type_and_required_flags(): void
    {
        $user = $this->makeUser('operations_manager');

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/operations/forms', $this->builderPayload());

        $res->assertCreated()
            ->assertJsonPath('data.title', 'استبيان رضا الطلاب')
            ->assertJsonPath('data.type', 'suggestion')
            ->assertJsonPath('data.form_type', 'suggestion')
            ->assertJsonPath('data.created_by.id', $user->id)
            ->assertJsonCount(2, 'data.questions')
            ->assertJsonPath('data.questions.0.label', 'الاسم الكامل')
            ->assertJsonPath('data.questions.0.required', true)
            ->assertJsonPath('data.questions.0.is_required', true)
            ->assertJsonPath('data.questions.1.options', ['ممتاز', 'جيد']);

        $form = DynamicForm::where('slug', 'student-satisfaction')->firstOrFail();
        $this->assertSame('suggestion', $form->type);
        $this->assertSame($user->id, (int) $form->created_by);
        $this->assertSame(['الاسم الكامل', 'التقييم'], $form->questions->pluck('label')->all());
        $this->assertSame([true, false], $form->questions->pluck('is_required')->all());
        $this->assertSame([1, 2], $form->questions->pluck('sort_order')->all());
    }

    public function test_the_admin_api_creates_the_same_way(): void
    {
        $this->actingAs($this->makeUser('admin'), 'sanctum')->postJson('/api/admin/forms', $this->builderPayload(['slug' => 'via-admin']))->assertCreated();

        $this->assertSame(2, DynamicForm::where('slug', 'via-admin')->firstOrFail()->questions()->count());
    }

    public function test_a_taken_slug_on_create_gets_a_free_one_instead_of_failing(): void
    {
        $user = $this->makeUser('admin');
        $this->makeForm(['slug' => 'student-satisfaction']);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/operations/forms', $this->builderPayload())->assertCreated();

        $slug = $res->json('data.slug');
        $this->assertNotSame('student-satisfaction', $slug);
        $this->assertStringStartsWith('student-satisfaction-', $slug);
        $this->assertSame('student-satisfaction', DynamicForm::orderBy('id')->first()->slug, 'the existing form keeps its link');
    }

    public function test_a_form_created_without_a_slug_gets_one_from_its_title(): void
    {
        $res = $this->actingAs($this->makeUser('admin'), 'sanctum')->postJson('/api/operations/forms', ['title' => 'Course Evaluation'])->assertCreated();

        $this->assertSame('course-evaluation', $res->json('data.slug'));
        $this->assertSame('general', $res->json('data.type'));
    }

    // ── Draft first, publish on purpose ──────────────────────────────────────

    public function test_a_new_form_is_a_draft_whose_public_link_does_not_work_yet(): void
    {
        $res = $this->actingAs($this->makeUser('admin'), 'sanctum')->postJson('/api/operations/forms', $this->builderPayload())->assertCreated();

        $res->assertJsonPath('data.status', 'draft');
        $this->getJson('/api/forms/student-satisfaction')->assertNotFound();
        $this->postJson('/api/forms/student-satisfaction/submit', ['answers' => []])->assertNotFound();
    }

    public function test_publishing_makes_the_link_work_and_unpublishing_closes_it_again(): void
    {
        $admin = $this->makeUser('admin');
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/operations/forms', $this->builderPayload())->json('data.id');

        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$id}", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/forms/student-satisfaction')->assertOk();

        // Phase 1: a closed form answers with its state (so people are told it is closed) and takes no answers.
        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$id}", ['status' => 'closed'])->assertOk();
        $this->getJson('/api/forms/student-satisfaction')->assertOk()->assertJsonPath('data.availability.state', 'closed')->assertJsonPath('data.questions', []);
        $this->postJson('/api/forms/student-satisfaction/submit', ['answers' => []])->assertForbidden()->assertJsonPath('code', 'closed');
    }

    public function test_a_form_can_be_created_already_published_when_it_asks_to_be(): void
    {
        $this->actingAs($this->makeUser('admin'), 'sanctum')->postJson('/api/operations/forms', $this->builderPayload(['status' => 'active']))->assertCreated()->assertJsonPath('data.status', 'active');

        $this->getJson('/api/forms/student-satisfaction')->assertOk();
    }

    public function test_a_form_with_no_questions_cannot_be_published(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')->postJson('/api/operations/forms', ['title' => 'فارغ', 'status' => 'active'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(0, DynamicForm::count(), 'the refused form is not left behind');

        $draft = $this->makeForm(['status' => 'draft']);
        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$draft->id}", ['status' => 'active'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('draft', $draft->fresh()->status);

        // ...but adding a question in the same save makes it publishable
        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$draft->id}", ['status' => 'active', 'questions' => [['label' => 'س', 'type' => 'text']]])->assertOk();
        $this->assertSame('active', $draft->fresh()->status);
    }

    public function test_forms_that_are_already_published_keep_working_exactly_as_before(): void
    {
        $admin = $this->makeUser('admin');
        $old = $this->makeForm(['status' => 'active', 'title' => 'قديم']); // published before drafts existed, with no questions

        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$old->id}", ['title' => 'قديم معدّل'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$old->id}", ['title' => 'مرة أخرى', 'status' => 'active'])->assertOk();
        $this->getJson("/api/forms/{$old->slug}")->assertOk();
    }

    public function test_closing_or_archiving_never_needs_questions(): void
    {
        $form = $this->makeForm(['status' => 'draft']);
        $admin = $this->makeUser('admin');

        foreach (['closed', 'archived', 'draft'] as $status) {
            $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['status' => $status])->assertOk();
        }
    }

    // ── Validation errors ────────────────────────────────────────────────────

    public function test_invalid_payloads_return_422_with_field_keyed_errors(): void
    {
        $user = $this->makeUser('admin');

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/operations/forms', [
            'title'     => '',
            'questions' => [
                ['label' => '', 'type' => 'text'],
                ['label' => 'سؤال', 'type' => 'telepathy'],
                ['label' => 'اختر', 'type' => 'select', 'options' => []],
            ],
        ]);

        $res->assertStatus(422)->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['title', 'questions.0.label', 'questions.1.type', 'questions.2.options']);
        $this->assertSame(0, DynamicForm::count(), 'nothing is saved when any part is invalid');
    }

    public function test_a_failed_question_validation_does_not_create_a_half_saved_form(): void
    {
        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->postJson('/api/operations/forms', $this->builderPayload(['questions' => [['label' => 'ok', 'type' => 'text'], ['label' => 'bad', 'type' => 'nope']]]))
            ->assertStatus(422);

        $this->assertSame(0, DynamicForm::count());
        $this->assertSame(0, FormQuestion::count());
    }

    // ── Updating ─────────────────────────────────────────────────────────────

    public function test_update_edits_adds_reorders_and_removes_questions(): void
    {
        $user = $this->makeUser('admin');
        $form = $this->makeForm([], [
            ['label' => 'أ', 'type' => 'text'],
            ['label' => 'ب', 'type' => 'text'],
            ['label' => 'ج', 'type' => 'text'],
        ]);
        [$a, $b, $c] = $form->questions->all();

        $res = $this->actingAs($user, 'sanctum')->putJson("/api/operations/forms/{$form->id}", [
            'title'     => 'عنوان جديد',
            'form_type' => 'complaint',
            'questions' => [
                ['id' => $c->id, 'label' => 'ج معدّل', 'type' => 'text', 'required' => true],
                ['id' => 9999, 'label' => 'سؤال جديد', 'type' => 'textarea', 'required' => false],
                ['id' => $a->id, 'label' => 'أ', 'type' => 'text', 'required' => false],
            ],
        ])->assertOk();

        $this->assertSame(['ج معدّل', 'سؤال جديد', 'أ'], collect($res->json('data.questions'))->pluck('label')->all());
        $form->refresh();
        $this->assertSame('عنوان جديد', $form->title);
        $this->assertSame('complaint', $form->type);

        $live = $form->questions()->get();
        $this->assertSame([1, 2, 3], $live->pluck('sort_order')->all());
        $this->assertSame($c->id, $live[0]->id, 'an existing question keeps its id, so its answers stay attached');
        $this->assertTrue($live[0]->is_required);
        $this->assertSame($a->id, $live[2]->id);
        $this->assertNotContains($b->id, $live->pluck('id')->all());
        $this->assertSoftDeleted('form_questions', ['id' => $b->id]);
    }

    public function test_updating_without_questions_leaves_the_questions_alone(): void
    {
        $form = $this->makeForm([], [['label' => 'أ', 'type' => 'text'], ['label' => 'ب', 'type' => 'text']]);

        $this->actingAs($this->makeUser('admin'), 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['title' => 'فقط العنوان'])->assertOk()
            ->assertJsonCount(2, 'data.questions');

        $this->assertSame(2, $form->questions()->count());
    }

    public function test_an_id_that_belongs_to_another_form_never_touches_that_form(): void
    {
        $mine = $this->makeForm([], [['label' => 'سؤالي', 'type' => 'text']]);
        $other = $this->makeForm([], [['label' => 'سؤال غيري', 'type' => 'text']]);
        $foreign = $other->questions->first();

        $this->actingAs($this->makeUser('admin'), 'sanctum')->putJson("/api/operations/forms/{$mine->id}", [
            'questions' => [['id' => $foreign->id, 'label' => 'محاولة اختراق', 'type' => 'text']],
        ])->assertOk();

        $this->assertSame('سؤال غيري', $foreign->fresh()->label);
        $this->assertSame($other->id, (int) $foreign->fresh()->form_id);
        $this->assertSame(['محاولة اختراق'], $mine->questions()->pluck('label')->all(), 'it became a new question of this form');
    }

    public function test_title_only_updates_and_nulls_never_erase_required_columns(): void
    {
        $form = $this->makeForm(['slug' => 'keep-me', 'title' => 'العنوان', 'type' => 'complaint']);

        $this->actingAs($this->makeUser('admin'), 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['slug' => null, 'type' => null, 'description' => 'جديد'])->assertOk();

        $form->refresh();
        $this->assertSame('keep-me', $form->slug);
        $this->assertSame('العنوان', $form->title);
        $this->assertSame('general', $form->type);
        $this->assertSame('جديد', $form->description);
    }

    public function test_a_form_cannot_take_another_forms_link(): void
    {
        $this->makeForm(['slug' => 'taken']);
        $form = $this->makeForm(['slug' => 'mine']);

        $this->actingAs($this->makeUser('admin'), 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['slug' => 'taken'])
            ->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->assertSame('mine', $form->fresh()->slug);

        $this->actingAs($this->makeUser('admin'), 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['slug' => 'mine'])->assertOk();
    }

    public function test_required_and_optional_both_persist_under_either_name(): void
    {
        $form = $this->makeForm([], [['label' => 'أ', 'type' => 'text', 'is_required' => true]]);
        $q = $form->questions->first();
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['questions' => [['id' => $q->id, 'label' => 'أ', 'type' => 'text', 'required' => false]]])->assertOk();
        $this->assertFalse($q->fresh()->is_required);

        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['questions' => [['id' => $q->id, 'label' => 'أ', 'type' => 'text', 'is_required' => true]]])->assertOk();
        $this->assertTrue($q->fresh()->is_required);
    }

    public function test_options_are_cleaned_and_only_kept_for_choice_questions(): void
    {
        $res = $this->actingAs($this->makeUser('admin'), 'sanctum')->postJson('/api/operations/forms', [
            'title'     => 'خيارات',
            'questions' => [
                ['label' => 'اختر', 'type' => 'select', 'options' => [' أ ', 'ب', 'أ', '', 'ب']],
                ['label' => 'نص', 'type' => 'text', 'options' => ['x']],
            ],
        ])->assertCreated();

        $this->assertSame(['أ', 'ب'], $res->json('data.questions.0.options'));
        $this->assertNull($res->json('data.questions.1.options'));
    }

    // ── Data safety ──────────────────────────────────────────────────────────

    private function formWithAnswer(): array
    {
        $form = $this->makeForm([], [['label' => 'الاسم', 'type' => 'text'], ['label' => 'الرأي', 'type' => 'textarea']]);
        $submission = FormSubmission::create(['form_id' => $form->id, 'status' => 'new', 'submitted_at' => now()]);
        foreach ($form->questions as $q) {
            FormAnswer::create(['submission_id' => $submission->id, 'question_id' => $q->id, 'value' => 'جواب '.$q->id]);
        }

        return [$form, $submission];
    }

    public function test_removing_a_question_keeps_its_answers_and_they_stay_readable(): void
    {
        [$form, $submission] = $this->formWithAnswer();
        [$keep, $drop] = $form->questions->all();
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['questions' => [['id' => $keep->id, 'label' => 'الاسم', 'type' => 'text']]])->assertOk();

        $this->assertSame(2, FormAnswer::where('submission_id', $submission->id)->count(), 'no answer was deleted');
        $res = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertOk();
        $answers = collect($res->json('data.data.0.answers'));
        $removed = $answers->firstWhere('question_id', $drop->id);
        $this->assertSame('الرأي', $removed['question_label']);
        $this->assertTrue($removed['question_deleted']);
        $this->assertSame('جواب '.$drop->id, $removed['value']);
    }

    public function test_deleting_a_question_through_the_admin_api_keeps_its_answers(): void
    {
        [$form, $submission] = $this->formWithAnswer();
        $q = $form->questions->first();

        $this->actingAs($this->makeUser('admin'), 'sanctum')->deleteJson("/api/admin/forms/{$form->id}/questions/{$q->id}")->assertOk();

        $this->assertSoftDeleted('form_questions', ['id' => $q->id]);
        $this->assertSame(2, FormAnswer::where('submission_id', $submission->id)->count());
    }

    public function test_deleting_a_form_keeps_its_submissions_and_answers_and_closes_its_link(): void
    {
        [$form, $submission] = $this->formWithAnswer();

        $this->actingAs($this->makeUser('admin'), 'sanctum')->deleteJson("/api/admin/forms/{$form->id}")->assertOk();

        $this->assertSoftDeleted('dynamic_forms', ['id' => $form->id]);
        $this->assertDatabaseHas('form_submissions', ['id' => $submission->id]);
        $this->assertSame(2, FormAnswer::where('submission_id', $submission->id)->count());
        $this->getJson("/api/forms/{$form->slug}")->assertNotFound();
        $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson('/api/operations/forms')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_the_type_of_a_question_with_answers_cannot_change_but_an_unanswered_one_can(): void
    {
        [$form] = $this->formWithAnswer();
        $answered = $form->questions->first();
        $fresh = $this->makeForm([], [['label' => 'جديد', 'type' => 'text']])->questions->first();
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['questions' => [['id' => $answered->id, 'label' => 'الاسم', 'type' => 'number']]])
            ->assertStatus(422)->assertJsonValidationErrors('questions.0.type');
        $this->assertSame('text', $answered->fresh()->type);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/forms/{$form->id}/questions/{$answered->id}", ['type' => 'number'])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/forms/{$fresh->form_id}/questions/{$fresh->id}", ['type' => 'number'])->assertOk();
        $this->assertSame('number', $fresh->fresh()->type);
    }

    // ── The single-question admin endpoints ──────────────────────────────────

    public function test_the_question_endpoints_validate_and_append(): void
    {
        $form = $this->makeForm([], [['label' => 'أول', 'type' => 'text', 'sort_order' => 4]]);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/forms/{$form->id}/questions", ['label' => 'ثاني', 'type' => 'select'])
            ->assertStatus(422)->assertJsonValidationErrors('options');

        $res = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/forms/{$form->id}/questions", ['label' => 'ثاني', 'type' => 'checkbox', 'options' => ['x', 'y'], 'required' => true])
            ->assertCreated()->assertJsonPath('data.is_required', true)->assertJsonPath('data.sort_order', 5);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/forms/{$form->id}/questions/{$res->json('data.id')}", ['label' => 'ثاني معدّل'])->assertOk()
            ->assertJsonPath('data.label', 'ثاني معدّل')->assertJsonPath('data.type', 'checkbox')->assertJsonPath('data.options', ['x', 'y']);

        $other = $this->makeForm([], [['label' => 'x', 'type' => 'text']]);
        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/forms/{$form->id}/questions/{$other->questions->first()->id}", ['label' => 'اختراق'])->assertNotFound();
    }

    public function test_the_form_detail_and_list_carry_what_the_screens_read(): void
    {
        $form = $this->makeForm(['title' => 'قائمة'], [['label' => 'س', 'type' => 'text']]);
        FormSubmission::create(['form_id' => $form->id, 'status' => 'new', 'submitted_at' => now()]);
        $admin = $this->makeUser('admin');

        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/operations/forms')->assertOk();
        $row = $list->json('data.data.0');
        $this->assertSame(1, $row['submissions_count']);
        $this->assertSame('general', $row['form_type']);
        $this->assertArrayHasKey('meta', $list->json('data'));

        $this->actingAs($admin, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertOk()
            ->assertJsonPath('data.questions.0.required', false)->assertJsonPath('data.submissions_count', 1)
            ->assertJsonPath('data.slug', $form->slug);
    }
}
