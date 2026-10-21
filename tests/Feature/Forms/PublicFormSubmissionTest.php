<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * The public side: a visitor opens a form by its link and submits answers. Real controller, real validation,
 * real database; what is checked is what ends up stored and what the staff list then shows.
 */
class PublicFormSubmissionTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    /** @return array{0: DynamicForm, 1: array<string, int>} the form and its question ids by short name */
    private function fullForm(): array
    {
        $form = $this->makeForm(['slug' => 'full-form'], [
            ['label' => 'الاسم', 'type' => 'text', 'is_required' => true],
            ['label' => 'البريد', 'type' => 'email', 'is_required' => true],
            ['label' => 'الهاتف', 'type' => 'phone'],
            ['label' => 'العمر', 'type' => 'number'],
            ['label' => 'تاريخ الميلاد', 'type' => 'date'],
            ['label' => 'ملاحظات', 'type' => 'textarea'],
            ['label' => 'المدينة', 'type' => 'select', 'options' => ['الرياض', 'جدة'], 'is_required' => true],
            ['label' => 'التقييم', 'type' => 'radio', 'options' => ['ممتاز', 'جيد', 'ضعيف']],
            ['label' => 'الاهتمامات', 'type' => 'checkbox', 'options' => ['برمجة', 'تصميم', 'تسويق']],
            ['label' => 'المهارات', 'type' => 'multiselect', 'options' => ['php', 'js']],
            ['label' => 'السيرة', 'type' => 'file'],
        ]);
        $ids = [];
        foreach (['name', 'mail', 'phone', 'age', 'dob', 'notes', 'city', 'rate', 'interests', 'skills', 'cv'] as $i => $key) {
            $ids[$key] = $form->questions[$i]->id;
        }

        return [$form, $ids];
    }

    private function validAnswers(array $ids): array
    {
        return [
            $ids['name'] => 'سارة العتيبي', $ids['mail'] => 'sara@example.com', $ids['phone'] => '+31 6 1234 5678', $ids['age'] => '29',
            $ids['dob'] => '1996-04-12', $ids['notes'] => "سطر أول\nسطر ثان", $ids['city'] => 'جدة', $ids['rate'] => 'ممتاز',
            $ids['interests'] => ['برمجة', 'تسويق'], $ids['skills'] => ['php'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    // ── Success: everything is stored ────────────────────────────────────────

    public function test_a_valid_submission_stores_every_answer_correctly(): void
    {
        [$form, $ids] = $this->fullForm();
        $answers = $this->validAnswers($ids);
        $answers[$ids['cv']] = $this->pdfUpload('cv.pdf');

        $res = $this->post("/api/forms/{$form->slug}/submit", ['answers' => $answers], ['Accept' => 'application/json'])->assertCreated();

        $submission = FormSubmission::findOrFail($res->json('data.submission_id'));
        $this->assertSame($form->id, (int) $submission->form_id);
        $this->assertSame('new', $submission->status);
        $this->assertNull($submission->user_id);

        $stored = FormAnswer::where('submission_id', $submission->id)->get()->keyBy('question_id');
        $this->assertCount(11, $stored, 'one row per question');
        $this->assertSame('سارة العتيبي', $stored[$ids['name']]->value);
        $this->assertSame('sara@example.com', $stored[$ids['mail']]->value);
        $this->assertSame('29', $stored[$ids['age']]->value);
        $this->assertSame('1996-04-12', $stored[$ids['dob']]->value);
        $this->assertSame("سطر أول\nسطر ثان", $stored[$ids['notes']]->value);
        $this->assertSame('جدة', $stored[$ids['city']]->value);
        $this->assertSame(['برمجة', 'تسويق'], json_decode($stored[$ids['interests']]->value, true));
        $this->assertSame(['php'], json_decode($stored[$ids['skills']]->value, true));
        $this->assertSame('cv.pdf', $stored[$ids['cv']]->value);
        $this->assertNotNull($stored[$ids['cv']]->file_path);
        Storage::disk('private')->assertExists($stored[$ids['cv']]->file_path);
    }

    public function test_optional_questions_left_empty_are_stored_as_empty_and_the_submission_succeeds(): void
    {
        [$form, $ids] = $this->fullForm();

        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$ids['name'] => 'x', $ids['mail'] => 'a@b.co', $ids['city'] => 'جدة']])->assertCreated();

        $row = FormAnswer::where('question_id', $ids['phone'])->first();
        $this->assertNotNull($row);
        $this->assertNull($row->value);
        $this->assertNull($row->file_path);
    }

    public function test_the_submission_appears_in_the_staff_response_list_with_what_the_screen_reads(): void
    {
        [$form, $ids] = $this->fullForm();
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => $this->validAnswers($ids)])->assertCreated();

        $res = $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertOk();

        $row = $res->json('data.data.0');
        $this->assertSame('زائر', $row['submitter_label']);
        $this->assertStringContainsString('الاسم: سارة العتيبي', $row['answers_preview']);
        $this->assertSame('new', $row['status']);
        $this->assertCount(11, $row['answers']);
        $interests = collect($row['answers'])->firstWhere('question_label', 'الاهتمامات');
        $this->assertSame(['برمجة', 'تسويق'], $interests['value'], 'multi-choice answers come back as a list');
        $this->assertSame(1, $res->json('data.meta.total'));
        $this->assertArrayNotHasKey('file_path', $row['answers'][0]);
    }

    // ── Required ─────────────────────────────────────────────────────────────

    public function test_required_questions_are_enforced_with_a_message_that_names_the_question(): void
    {
        [$form, $ids] = $this->fullForm();

        $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$ids['phone'] => '+31612345678']])->assertStatus(422);

        $res->assertJsonValidationErrors(["answers.{$ids['name']}", "answers.{$ids['mail']}", "answers.{$ids['city']}"]);
        $this->assertSame('حقل «الاسم» مطلوب.', $res->json('errors')["answers.{$ids['name']}"][0]);
        $this->assertSame(0, FormSubmission::count(), 'nothing is stored for a rejected submission');
        $this->assertSame(0, FormAnswer::count());
    }

    public function test_a_required_multi_choice_needs_at_least_one_choice(): void
    {
        $form = $this->makeForm([], [['label' => 'اختيار', 'type' => 'checkbox', 'options' => ['a', 'b'], 'is_required' => true]]);
        $id = $form->questions->first()->id;

        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => []]])->assertStatus(422)->assertJsonValidationErrors("answers.$id");
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => ['a']]])->assertCreated();
    }

    public function test_a_required_question_does_not_accept_blank_text(): void
    {
        $form = $this->makeForm([], [['label' => 'اسم', 'type' => 'text', 'is_required' => true]]);
        $id = $form->questions->first()->id;

        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => '   ']])->assertStatus(422)->assertJsonValidationErrors("answers.$id");
    }

    // ── Choice values must match the configured options ──────────────────────

    public function test_a_choice_that_is_not_an_option_is_rejected_for_every_choice_type(): void
    {
        [$form, $ids] = $this->fullForm();
        $base = $this->validAnswers($ids);

        foreach ([['city', 'مكة'], ['rate', 'متوسط'], ['interests', ['برمجة', 'سحر']], ['skills', ['rust']]] as [$key, $bad]) {
            $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => array_replace($base, [$ids[$key] => $bad])])->assertStatus(422);
            $this->assertNotEmpty(array_filter(array_keys($res->json('errors')), fn ($k) => str_starts_with($k, "answers.{$ids[$key]}")), "$key should report an error");
        }
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_a_checkbox_without_configured_options_accepts_only_the_default_yes_no(): void
    {
        $form = $this->makeForm([], [['label' => 'موافق', 'type' => 'checkbox']]);
        $id = $form->questions->first()->id;

        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => ['نعم']]])->assertCreated();
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => ['ربما']]])->assertStatus(422);
    }

    public function test_a_choice_question_with_no_options_accepts_nothing_rather_than_anything(): void
    {
        $form = $this->makeForm([], [['label' => 'اختر', 'type' => 'select', 'options' => null]]);
        $id = $form->questions->first()->id;

        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => 'أي شيء']])->assertStatus(422);
    }

    // ── Shapes ───────────────────────────────────────────────────────────────

    public function test_each_value_shape_is_validated(): void
    {
        [$form, $ids] = $this->fullForm();
        $base = $this->validAnswers($ids);

        foreach ([['mail', 'not-an-email'], ['phone', 'abc'], ['age', 'twelve'], ['dob', '12/04/1996'], ['dob', '1996-13-45'], ['name', str_repeat('x', 1001)], ['notes', str_repeat('x', 10001)]] as [$key, $bad]) {
            $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => array_replace($base, [$ids[$key] => $bad])])->assertStatus(422);
            $this->assertArrayHasKey("answers.{$ids[$key]}", $res->json('errors'), "$key = ".(is_string($bad) ? mb_substr($bad, 0, 30) : json_encode($bad)));
        }
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_answers_must_be_a_list_of_answers(): void
    {
        [$form] = $this->fullForm();

        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => 'hello'])->assertStatus(422)->assertJsonValidationErrors('answers');
    }

    public function test_answers_for_questions_that_are_not_on_the_form_are_ignored_not_stored(): void
    {
        $form = $this->makeForm([], [['label' => 'س', 'type' => 'text']]);
        $other = $this->makeForm([], [['label' => 'غيري', 'type' => 'text']]);
        $id = $form->questions->first()->id;

        $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => 'ok', $other->questions->first()->id => 'leak', '999999' => 'x']])->assertCreated();

        $this->assertSame(1, FormAnswer::where('submission_id', $res->json('data.submission_id'))->count());
        $this->assertSame(0, FormAnswer::where('question_id', $other->questions->first()->id)->count());
    }

    // ── Compatibility with the first version of the public form ──────────────

    public function test_the_old_q_prefixed_keys_still_work(): void
    {
        $form = $this->makeForm([], [['label' => 'الاسم', 'type' => 'text', 'is_required' => true], ['label' => 'لون', 'type' => 'select', 'options' => ['أحمر']]]);
        [$name, $color] = $form->questions->pluck('id')->all();

        $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => ["q_$name" => 'سلمى', "q_$color" => 'أحمر']])->assertCreated();

        $stored = FormAnswer::where('submission_id', $res->json('data.submission_id'))->pluck('value', 'question_id');
        $this->assertSame('سلمى', $stored[$name]);
        $this->assertSame('أحمر', $stored[$color]);
    }

    public function test_the_old_keys_are_validated_like_any_other(): void
    {
        $form = $this->makeForm([], [['label' => 'الاسم', 'type' => 'text', 'is_required' => true]]);
        $name = $form->questions->first()->id;

        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => ['q_999' => 'x']])->assertStatus(422)->assertJsonValidationErrors("answers.$name");
    }

    // ── Reachability ─────────────────────────────────────────────────────────

    public function test_drafts_archived_and_unknown_forms_do_not_exist_for_a_visitor_and_a_closed_one_says_so(): void
    {
        foreach (['draft', 'archived'] as $status) {
            $form = $this->makeForm(['status' => $status], [['label' => 'س', 'type' => 'text']]);
            $this->getJson("/api/forms/{$form->slug}")->assertNotFound();
            $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => []])->assertNotFound();
        }
        $this->getJson('/api/forms/never-existed')->assertNotFound();

        // Phase 1: a closed form is told apart from a missing one: 200 with its state, no questions, and no answers accepted.
        $closed = $this->makeForm(['status' => 'closed'], [['label' => 'س', 'type' => 'text']]);
        $this->getJson("/api/forms/{$closed->slug}")->assertOk()->assertJsonPath('data.availability.state', 'closed')->assertJsonPath('data.questions', []);
        $this->postJson("/api/forms/{$closed->slug}/submit", ['answers' => []])->assertForbidden();
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_the_public_view_exposes_only_what_filling_the_form_needs(): void
    {
        $dept = $this->makeDepartment('سري');
        $form = $this->makeForm(['department_id' => $dept->id], [['label' => 'س', 'type' => 'select', 'options' => ['أ'], 'is_required' => true]]);

        $res = $this->getJson("/api/forms/{$form->slug}")->assertOk();

        $res->assertJsonPath('data.title', $form->title)->assertJsonPath('data.questions.0.required', true)->assertJsonPath('data.questions.0.options', ['أ']);
        foreach (['created_by', 'department', 'department_id', 'submissions_count', 'created_at'] as $leak) {
            $this->assertArrayNotHasKey($leak, $res->json('data'));
        }
    }

    public function test_an_existing_public_link_keeps_working_after_the_form_is_edited(): void
    {
        $form = $this->makeForm(['slug' => 'my-old-link'], [['label' => 'س', 'type' => 'text']]);
        $this->actingAs($this->makeUser('admin'), 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['title' => 'عنوان مختلف', 'slug' => 'my-old-link'])->assertOk();

        $this->getJson('/api/forms/my-old-link')->assertOk()->assertJsonPath('data.title', 'عنوان مختلف');
    }

    // ── Who submitted ────────────────────────────────────────────────────────

    public function test_a_signed_in_respondent_is_recorded_and_a_guest_is_not(): void
    {
        $form = $this->makeForm([], [['label' => 'س', 'type' => 'text']]);
        $id = $form->questions->first()->id;
        $user = $this->makeUser('student');
        $token = $user->createToken('t')->plainTextToken;

        // One test process serves every request here, so the guest requests go first and the guards are reset between
        // requests: in production each request starts with no user, which is the state being checked.
        $guest = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => 'b']])->assertCreated();
        $badToken = $this->withToken('999|not-a-real-token')->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => 'c']])->assertCreated();
        $this->app['auth']->forgetGuards();
        $signed = $this->withToken($token)->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => 'a']])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->assertSame($user->id, (int) FormSubmission::find($signed->json('data.submission_id'))->user_id);
        $this->assertNull(FormSubmission::find($guest->json('data.submission_id'))->user_id);
        $this->assertNull(FormSubmission::find($badToken->json('data.submission_id'))->user_id);

        $list = $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertOk();
        $labels = collect($list->json('data.data'))->pluck('submitter_label')->all();
        $this->assertContains($user->name, $labels);
        $this->assertContains('زائر', $labels);
    }

    // ── Metadata ─────────────────────────────────────────────────────────────

    public function test_small_flat_metadata_is_kept(): void
    {
        $form = $this->makeForm([], [['label' => 'س', 'type' => 'text']]);

        $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [], 'metadata' => ['source' => 'qr', 'locale' => 'ar', 'step' => 2]])->assertCreated();

        $this->assertSame(['source' => 'qr', 'locale' => 'ar', 'step' => 2], FormSubmission::find($res->json('data.submission_id'))->metadata);
    }

    public function test_oversized_nested_or_oddly_keyed_metadata_is_rejected(): void
    {
        $form = $this->makeForm([], [['label' => 'س', 'type' => 'text']]);
        $bad = [
            ['k' => str_repeat('x', 256)],
            ['nested' => ['a' => 'b']],
            array_combine(array_map(fn ($i) => "k$i", range(1, 11)), range(1, 11)),
            ['bad key!' => 'x'],
            ['a' => str_repeat('y', 200), 'b' => str_repeat('y', 200), 'c' => str_repeat('y', 200), 'd' => str_repeat('y', 200), 'e' => str_repeat('y', 200), 'f' => str_repeat('y', 200), 'g' => str_repeat('y', 200), 'h' => str_repeat('y', 200), 'i' => str_repeat('y', 200), 'j' => str_repeat('y', 200), 'k' => str_repeat('y', 200)],
            'a string',
        ];

        foreach ($bad as $metadata) {
            $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [], 'metadata' => $metadata])->assertStatus(422)->assertJsonValidationErrors('metadata');
        }
        $this->assertSame(0, FormSubmission::count());
    }

    // ── Rate limit ───────────────────────────────────────────────────────────

    public function test_submissions_are_rate_limited_per_form_and_do_not_block_reading(): void
    {
        $form = $this->makeForm([], [['label' => 'س', 'type' => 'text']]);
        $other = $this->makeForm([], [['label' => 'س', 'type' => 'text']]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => []])->assertCreated();
        }

        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => []])->assertStatus(429);
        $this->getJson("/api/forms/{$form->slug}")->assertOk();
        $this->postJson("/api/forms/{$other->slug}/submit", ['answers' => []])->assertCreated();
        $this->assertSame(11, FormSubmission::count(), '10 + 1 on the other form; the rejected one stored nothing');
    }
}
