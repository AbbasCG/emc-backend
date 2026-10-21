<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormQuestion;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Services\Forms\FormSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Every block the builder offers, followed from the draft the builder saves to the answer a manager reads:
 * save → publish → public form → submission → stored answer → response detail.
 */
class FormBuilderQuestionTypesTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        // The submission limiter has its own test (PublicFormSubmissionTest); these loops would trip it.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->owner = $this->makeUser('operations_manager');
    }

    private function api()
    {
        return $this->actingAs($this->owner, 'sanctum');
    }

    /** One of every block, with the configuration the builder can produce. */
    private function everyBlock(): array
    {
        return [
            ['type' => 'section', 'label' => 'البيانات الأساسية', 'description' => 'يرجى تعبئة البيانات'],
            ['type' => 'text', 'label' => 'الاسم', 'is_required' => true, 'placeholder' => 'الاسم الكامل', 'help_text' => 'كما في الهوية', 'config' => ['validation' => ['min_length' => 2, 'max_length' => 40]]],
            ['type' => 'textarea', 'label' => 'ملاحظات'],
            ['type' => 'email', 'label' => 'البريد'],
            ['type' => 'phone', 'label' => 'الجوال'],
            ['type' => 'number', 'label' => 'العمر', 'config' => ['validation' => ['min' => 16, 'max' => 70, 'integer_only' => true]]],
            ['type' => 'url', 'label' => 'الموقع'],
            ['type' => 'description', 'label' => '', 'description' => 'اقرأ الأسئلة التالية بعناية'],
            ['type' => 'page_break', 'label' => ''],
            ['type' => 'section', 'label' => 'التفضيلات'],
            ['type' => 'date', 'label' => 'تاريخ الميلاد', 'config' => ['validation' => ['max_date' => '2020-01-01']]],
            ['type' => 'time', 'label' => 'وقت التواصل'],
            ['type' => 'radio', 'label' => 'المدينة', 'options' => ['جدة', 'الرياض']],
            ['type' => 'checkbox', 'label' => 'الاهتمامات', 'options' => ['برمجة', 'تصميم', 'تسويق'], 'config' => ['validation' => ['min_choices' => 1, 'max_choices' => 2]]],
            ['type' => 'select', 'label' => 'المستوى', 'options' => ['مبتدئ', 'متقدم'], 'placeholder' => 'اختر'],
            ['type' => 'yes_no', 'label' => 'هل لديك خبرة؟'],
            ['type' => 'star_rating', 'label' => 'التقييم', 'config' => ['scale' => ['max' => 5]]],
            ['type' => 'linear_scale', 'label' => 'احتمال التوصية', 'config' => ['scale' => ['min' => 1, 'max' => 10, 'min_label' => 'لا أوصي', 'max_label' => 'أوصي بشدة']]],
            ['type' => 'file', 'label' => 'السيرة الذاتية'],
            ['type' => 'image', 'label' => 'صورة شخصية'],
            ['type' => 'consent', 'label' => 'أوافق على الشروط', 'is_required' => true],
        ];
    }

    private function createAndPublish(?array $blocks = null): array
    {
        $res = $this->api()->postJson('/api/operations/forms', ['title' => 'نموذج شامل', 'questions' => $blocks ?? $this->everyBlock()])->assertCreated();
        $id = $res->json('data.id');
        $this->api()->postJson("/api/operations/forms/$id/publish")->assertOk();
        $form = DynamicForm::findOrFail($id);

        return [$form, $form->questions()->get()->keyBy('label')];
    }

    private function validAnswers($q): array
    {
        return [
            $q['الاسم']->id => 'سارة العتيبي',
            $q['ملاحظات']->id => 'سطر',
            $q['البريد']->id => 'sara@example.com',
            $q['الجوال']->id => '+966501234567',
            $q['العمر']->id => '29',
            $q['الموقع']->id => 'https://example.com/me',
            $q['تاريخ الميلاد']->id => '1996-04-12',
            $q['وقت التواصل']->id => '14:30',
            $q['المدينة']->id => 'جدة',
            $q['الاهتمامات']->id => ['برمجة', 'تصميم'],
            $q['المستوى']->id => 'متقدم',
            $q['هل لديك خبرة؟']->id => 'yes',
            $q['التقييم']->id => '4',
            $q['احتمال التوصية']->id => '9',
            $q['أوافق على الشروط']->id => 'yes',
        ];
    }

    public function test_every_block_survives_save_publish_the_public_form_submission_and_the_response_detail(): void
    {
        [$form, $q] = $this->createAndPublish();

        // Saved: one row per block, in order, with their settings.
        $this->assertSame(21, $form->questions()->count());
        $this->assertSame(['section', 'text', 'textarea', 'email', 'phone', 'number', 'url', 'description', 'page_break', 'section'], $form->questions()->get()->take(10)->pluck('type')->all());
        $this->assertSame(['validation' => ['min_length' => 2, 'max_length' => 40]], $q['الاسم']->config);
        $this->assertSame('الاسم الكامل', $q['الاسم']->placeholder);
        $this->assertSame('كما في الهوية', $q['الاسم']->help_text);

        // Public: everything a respondent needs, nothing about ownership.
        $public = $this->getJson("/api/forms/{$form->slug}")->assertOk()->json('data');
        $this->assertCount(21, $public['questions']);
        $byLabel = collect($public['questions'])->keyBy('label');
        $this->assertSame(['validation' => ['min_length' => 2, 'max_length' => 40]], $byLabel['الاسم']['config']);
        $this->assertSame('اختر', $byLabel['المستوى']['placeholder']);
        $this->assertSame('اقرأ الأسئلة التالية بعناية', collect($public['questions'])->firstWhere('type', 'description')['description']);
        $this->assertSame('لا أوصي', $byLabel['احتمال التوصية']['config']['scale']['min_label']);

        // Submit.
        $answers = $this->validAnswers($q);
        $answers[$q['السيرة الذاتية']->id] = $this->pdfUpload('cv.pdf');
        $answers[$q['صورة شخصية']->id] = $this->pngUpload('me.png');
        $res = $this->post("/api/forms/{$form->slug}/submit", ['answers' => $answers], ['Accept' => 'application/json'])->assertCreated();

        // Stored: one row per answerable block, none for headings, descriptions and page breaks.
        $submission = FormSubmission::findOrFail($res->json('data.submission_id'));
        $stored = FormAnswer::where('submission_id', $submission->id)->get()->keyBy('question_id');
        $this->assertCount(17, $stored);
        foreach (['section', 'description', 'page_break'] as $structural) {
            foreach (FormQuestion::where('form_id', $form->id)->where('type', $structural)->get() as $block) {
                $this->assertFalse($stored->has($block->id), "$structural never stores an answer");
            }
        }
        $this->assertSame('yes', $stored[$q['هل لديك خبرة؟']->id]->value);
        $this->assertSame('yes', $stored[$q['أوافق على الشروط']->id]->value);
        $this->assertSame('4', $stored[$q['التقييم']->id]->value);
        $this->assertSame('14:30', $stored[$q['وقت التواصل']->id]->value);
        $this->assertSame(['برمجة', 'تصميم'], json_decode($stored[$q['الاهتمامات']->id]->value, true));
        $this->assertNotNull($stored[$q['صورة شخصية']->id]->file_path);
        Storage::disk('private')->assertExists($stored[$q['صورة شخصية']->id]->file_path);

        // Response detail: the manager reads each answer in words, against the question as it was published.
        $row = $this->api()->getJson("/api/operations/forms/{$form->id}/submissions")->assertOk()->json('data.data.0');
        $answersByLabel = collect($row['answers'])->keyBy('question_label');
        $this->assertSame('نعم', $answersByLabel['هل لديك خبرة؟']['display_value']);
        $this->assertSame('موافق', $answersByLabel['أوافق على الشروط']['display_value']);
        $this->assertSame('4 / 5', $answersByLabel['التقييم']['display_value']);
        $this->assertSame('برمجة، تصميم', $answersByLabel['الاهتمامات']['display_value']);
        $this->assertTrue($answersByLabel['صورة شخصية']['has_file']);
        $this->assertFalse(collect($row['answers'])->contains(fn ($a) => in_array($a['question_type'], ['section', 'description', 'page_break'], true)));
    }

    // ── What each type refuses ───────────────────────────────────────────────

    public function test_each_type_rejects_values_that_do_not_belong_to_it(): void
    {
        [$form, $q] = $this->createAndPublish();
        $base = $this->validAnswers($q);
        $base[$q['السيرة الذاتية']->id] = $this->pdfUpload();

        $bad = [
            'الاسم'          => ['x', str_repeat('ا', 41)],   // below the minimum, above the maximum
            'البريد'         => ['not-an-email'],
            'الجوال'         => ['abc'],
            'العمر'          => ['15', '71', '20.5', 'abc'],  // bounds and integers only
            'الموقع'         => ['javascript:alert(1)', 'ftp://x.y', 'example'],
            'تاريخ الميلاد'  => ['2021-01-01', '12/04/1996', 'غداً'],
            'وقت التواصل'    => ['25:00', '2pm'],
            'المدينة'        => ['دبي'],
            'الاهتمامات'     => [['برمجة', 'تصميم', 'تسويق'], ['غير موجود']],   // more than the maximum; not an option
            'المستوى'        => ['خبير'],
            'هل لديك خبرة؟'  => ['maybe', '2'],
            'التقييم'        => ['0', '6', '4.5', 'five'],
            'احتمال التوصية' => ['0', '11'],
            'أوافق على الشروط' => ['no', 'false', '', 'maybe', '2'],
        ];

        foreach ($bad as $label => $values) {
            foreach ($values as $value) {
                $key = $q[$label]->id;
                $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => array_replace($base, [$key => $value])]);
                $res->assertStatus(422, "$label should refuse ".json_encode($value, JSON_UNESCAPED_UNICODE));
                $this->assertNotEmpty(array_filter(array_keys($res->json('errors')), fn ($k) => $k === "answers.$key" || str_starts_with($k, "answers.$key.")), $label);
            }
        }
        $this->assertSame(0, FormSubmission::count(), 'nothing was stored by a refused submission');
    }

    public function test_an_image_question_takes_images_only_and_a_file_question_does_not_take_scripts(): void
    {
        [$form, $q] = $this->createAndPublish();
        $base = $this->validAnswers($q);
        $base[$q['السيرة الذاتية']->id] = $this->pdfUpload();
        $image = $q['صورة شخصية']->id;

        $this->post("/api/forms/{$form->slug}/submit", ['answers' => $base + [$image => $this->pdfUpload('not-an-image.pdf')]], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors("answers.$image");
        $this->post("/api/forms/{$form->slug}/submit", ['answers' => $base + [$image => $this->phpUpload('x.php')]], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors("answers.$image");
        $this->post("/api/forms/{$form->slug}/submit", ['answers' => array_replace($base, [$q['السيرة الذاتية']->id => $this->phpUpload('shell.php')])], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/forms/{$form->slug}/submit", ['answers' => $base + [$image => $this->pngUpload()]], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_required_state_is_enforced_for_every_answerable_type_and_never_for_blocks_that_are_not_answered(): void
    {
        $blocks = [
            ['type' => 'section', 'label' => 'عنوان', 'is_required' => true],
            ['type' => 'description', 'label' => '', 'description' => 'نص', 'is_required' => true],
            ['type' => 'page_break', 'label' => '', 'is_required' => true],
        ];
        foreach (['text', 'textarea', 'email', 'phone', 'number', 'url', 'date', 'time', 'yes_no', 'star_rating', 'linear_scale', 'consent'] as $type) {
            $blocks[] = ['type' => $type, 'label' => "إلزامي $type", 'is_required' => true];
        }
        $blocks[] = ['type' => 'radio', 'label' => 'إلزامي radio', 'options' => ['أ', 'ب'], 'is_required' => true];
        $blocks[] = ['type' => 'checkbox', 'label' => 'إلزامي checkbox', 'options' => ['أ', 'ب'], 'is_required' => true];
        $blocks[] = ['type' => 'select', 'label' => 'إلزامي select', 'options' => ['أ', 'ب'], 'is_required' => true];

        [$form, $q] = $this->createAndPublish($blocks);

        foreach (FormQuestion::where('form_id', $form->id)->whereIn('type', FormQuestion::STRUCTURAL)->get() as $block) {
            $this->assertFalse((bool) $block->is_required, 'a heading, text block or page break is never required');
        }

        $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => []])->assertStatus(422);
        $errors = array_keys($res->json('errors'));
        $this->assertCount(15, $errors, 'one error per required answerable question: '.json_encode($errors));
        foreach ($q->filter(fn ($row) => str_starts_with($row->label, 'إلزامي')) as $row) {
            $this->assertContains("answers.{$row->id}", $errors, $row->label);
        }
    }

    // ── What the builder may save ────────────────────────────────────────────

    public function test_settings_that_make_no_sense_are_refused_with_the_field_named(): void
    {
        $bad = [
            ['type' => 'text', 'label' => 'س', 'config' => ['validation' => ['min_length' => 10, 'max_length' => 5]]],
            ['type' => 'number', 'label' => 'س', 'config' => ['validation' => ['min' => 10, 'max' => 5]]],
            ['type' => 'number', 'label' => 'س', 'config' => ['default_value' => 'abc']],
            ['type' => 'date', 'label' => 'س', 'config' => ['validation' => ['min_date' => '2030-01-01', 'max_date' => '2020-01-01']]],
            ['type' => 'radio', 'label' => 'س', 'options' => ['أ', 'ب'], 'config' => ['default_value' => 'ج']],
            ['type' => 'star_rating', 'label' => 'س', 'config' => ['scale' => ['max' => 20]]],
            ['type' => 'linear_scale', 'label' => 'س', 'config' => ['scale' => ['min' => 1, 'max' => 1]]],
            ['type' => 'checkbox', 'label' => 'س', 'options' => ['أ', 'ب'], 'config' => ['validation' => ['min_choices' => 2, 'max_choices' => 1]]],
            ['type' => 'description', 'label' => ''],
            ['type' => 'text', 'label' => ''],
            ['type' => 'radio', 'label' => 'بلا خيارات', 'options' => []],
            ['type' => 'nonsense', 'label' => 'س'],
        ];

        foreach ($bad as $i => $block) {
            $res = $this->api()->postJson('/api/operations/forms', ['title' => 'x', 'questions' => [$block]]);
            $res->assertStatus(422);
            $this->assertNotEmpty(array_filter(array_keys($res->json('errors')), fn ($k) => str_starts_with($k, 'questions.0.')), "case $i names the question: ".json_encode($res->json('errors'), JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame(0, DynamicForm::count(), 'a refused save creates nothing');
    }

    public function test_properties_that_do_not_apply_to_a_type_are_dropped_not_stored(): void
    {
        [$form, $q] = $this->createAndPublish([
            ['type' => 'section', 'label' => 'عنوان', 'placeholder' => 'x', 'help_text' => 'y', 'config' => ['validation' => ['max_length' => 5]]],
            ['type' => 'yes_no', 'label' => 'سؤال', 'placeholder' => 'سيُحذف', 'config' => ['validation' => ['max_length' => 5], 'scale' => ['max' => 5]]],
            ['type' => 'text', 'label' => 'نص', 'options' => ['أ', 'ب']],
        ]);

        $this->assertNull($q['عنوان']->placeholder);
        $this->assertNull($q['عنوان']->help_text);
        $this->assertNull($q['عنوان']->config);
        $this->assertNull($q['سؤال']->placeholder);
        $this->assertNull($q['سؤال']->config, 'unknown settings never reach storage');
        $this->assertNull($q['نص']->options, 'options only exist for choice questions');
    }

    public function test_the_stored_limits_are_enforced_by_the_server_not_just_the_form(): void
    {
        [$form, $q] = $this->createAndPublish([
            ['type' => 'text', 'label' => 'قصير', 'config' => ['validation' => ['max_length' => 5]]],
            ['type' => 'star_rating', 'label' => 'ثلاث نجوم', 'config' => ['scale' => ['max' => 3]]],
        ]);

        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$q['قصير']->id => 'ستة أحرف']])->assertStatus(422);
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$q['ثلاث نجوم']->id => '4']])->assertStatus(422);
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$q['قصير']->id => 'خمسة', $q['ثلاث نجوم']->id => '3']])->assertCreated();
    }

    public function test_consent_and_yes_no_accept_the_spellings_a_checkbox_or_an_api_sends(): void
    {
        [$form, $q] = $this->createAndPublish([
            ['type' => 'yes_no', 'label' => 'سؤال'],
            ['type' => 'consent', 'label' => 'موافقة'],
        ]);

        foreach (['yes' => 'yes', 'true' => 'yes', 'on' => 'yes', '1' => 'yes', 'no' => 'no', 'false' => 'no', '0' => 'no'] as $sent => $stored) {
            $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$q['سؤال']->id => $sent, $q['موافقة']->id => 'on']])->assertCreated();
            $this->assertSame($stored, FormAnswer::where('submission_id', $res->json('data.submission_id'))->where('question_id', $q['سؤال']->id)->value('value'), $sent);
        }
        // An unticked consent is simply not an answer: allowed when optional, and never stored as "no".
        $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$q['موافقة']->id => 'false']])->assertCreated();
        $this->assertNull(FormAnswer::where('submission_id', $res->json('data.submission_id'))->where('question_id', $q['موافقة']->id)->value('value'));
    }

    // ── Publishing ───────────────────────────────────────────────────────────

    public function test_a_form_of_only_headings_text_and_page_breaks_cannot_be_published(): void
    {
        $id = $this->api()->postJson('/api/operations/forms', ['title' => 'بلا أسئلة', 'questions' => [
            ['type' => 'section', 'label' => 'عنوان'],
            ['type' => 'description', 'label' => '', 'description' => 'نص فقط'],
            ['type' => 'page_break', 'label' => ''],
        ]])->assertCreated()->json('data.id');

        $this->api()->postJson("/api/operations/forms/$id/publish")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(0, FormVersion::where('form_id', $id)->count());

        $this->api()->putJson("/api/operations/forms/$id", ['questions' => [['type' => 'section', 'label' => 'عنوان'], ['type' => 'text', 'label' => 'سؤال']]])->assertOk();
        $this->api()->postJson("/api/operations/forms/$id/publish")->assertOk();
    }

    public function test_the_published_snapshot_holds_the_new_settings_and_legacy_snapshots_keep_their_hash(): void
    {
        // A question with none of the new properties hashes exactly as it did before the builder existed.
        $legacy = ['id' => 7, 'label' => 'الاسم', 'type' => 'text', 'options' => null, 'is_required' => true, 'sort_order' => 1, 'description' => null, 'placeholder' => null, 'help_text' => null, 'config' => null];
        $before = ['id' => 7, 'label' => 'الاسم', 'type' => 'text', 'options' => null, 'required' => true, 'sort_order' => 1];
        $this->assertSame($before, FormSnapshotService::normalizeQuestion($legacy));

        [$form] = $this->createAndPublish();
        $schema = FormVersion::where('form_id', $form->id)->firstOrFail()->schema;
        $name = collect($schema['questions'])->firstWhere('label', 'الاسم');
        $this->assertSame('الاسم الكامل', $name['placeholder']);
        $this->assertSame('كما في الهوية', $name['help_text']);
        $this->assertSame(['validation' => ['min_length' => 2, 'max_length' => 40]], $name['config']);
        $this->assertSame(FormSnapshotService::hash($schema), FormVersion::where('form_id', $form->id)->value('schema_hash'));
    }

    public function test_editing_the_draft_never_changes_what_respondents_see_until_it_is_published(): void
    {
        [$form, $q] = $this->createAndPublish([['type' => 'text', 'label' => 'الاسم'], ['type' => 'yes_no', 'label' => 'سؤال']]);
        $before = $this->getJson("/api/forms/{$form->slug}")->json('data.questions');

        $this->api()->putJson("/api/operations/forms/{$form->id}", ['questions' => [
            ['id' => $q['الاسم']->id, 'type' => 'text', 'label' => 'الاسم (معدّل)', 'placeholder' => 'جديد'],
            ['type' => 'star_rating', 'label' => 'جديد كلياً'],
        ]])->assertOk()->assertJsonPath('data.has_unpublished_changes', true);

        $this->assertSame($before, $this->getJson("/api/forms/{$form->slug}")->json('data.questions'));

        $this->api()->postJson("/api/operations/forms/{$form->id}/publish")->assertOk();
        $after = $this->getJson("/api/forms/{$form->slug}")->json('data.questions');
        $this->assertSame(['الاسم (معدّل)', 'جديد كلياً'], array_column($after, 'label'));
    }

    public function test_restoring_an_older_version_brings_back_its_settings(): void
    {
        [$form, $q] = $this->createAndPublish([
            ['type' => 'text', 'label' => 'الاسم', 'placeholder' => 'القديم', 'config' => ['validation' => ['max_length' => 10]]],
            ['type' => 'star_rating', 'label' => 'التقييم', 'config' => ['scale' => ['max' => 7]]],
        ]);
        $v1 = FormVersion::where('form_id', $form->id)->firstOrFail();

        $this->api()->putJson("/api/operations/forms/{$form->id}", ['questions' => [
            ['id' => $q['الاسم']->id, 'type' => 'text', 'label' => 'الاسم', 'placeholder' => 'الجديد'],
        ]])->assertOk();
        $this->api()->postJson("/api/operations/forms/{$form->id}/publish")->assertOk();

        $res = $this->api()->postJson("/api/operations/forms/{$form->id}/versions/{$v1->id}/restore")->assertOk();
        $restored = collect($res->json('data.questions'));
        $this->assertSame(['الاسم', 'التقييم'], $restored->pluck('label')->all());
        $this->assertSame('القديم', $restored[0]['placeholder']);
        $this->assertSame(['validation' => ['max_length' => 10]], $restored[0]['config']);
        $this->assertSame(7, $restored[1]['config']['scale']['max']);
        $this->assertSame($q['التقييم']->id, $restored[1]['id'], 'the question that was removed comes back under its own id');
    }

    // ── History is never damaged ─────────────────────────────────────────────

    public function test_deleting_a_question_keeps_its_answers_and_bringing_it_back_reattaches_them(): void
    {
        [$form, $q] = $this->createAndPublish([['type' => 'text', 'label' => 'الاسم'], ['type' => 'star_rating', 'label' => 'التقييم']]);
        $res = $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$q['الاسم']->id => 'ليلى', $q['التقييم']->id => '5']])->assertCreated();
        $rating = $q['التقييم'];

        $this->api()->putJson("/api/operations/forms/{$form->id}", ['questions' => [['id' => $q['الاسم']->id, 'type' => 'text', 'label' => 'الاسم']]])->assertOk();
        $this->assertSoftDeleted('form_questions', ['id' => $rating->id]);
        $this->assertSame('5', FormAnswer::where('submission_id', $res->json('data.submission_id'))->where('question_id', $rating->id)->value('value'));

        // Undo in the builder = send the block again with its id.
        $back = $this->api()->putJson("/api/operations/forms/{$form->id}", ['questions' => [
            ['id' => $q['الاسم']->id, 'type' => 'text', 'label' => 'الاسم'],
            ['id' => $rating->id, 'type' => 'star_rating', 'label' => 'التقييم'],
        ]])->assertOk();
        $this->assertSame([$q['الاسم']->id, $rating->id], array_column($back->json('data.questions'), 'id'));
        $this->assertSame(1, FormQuestion::where('form_id', $form->id)->where('label', 'التقييم')->count(), 'restored, not duplicated');
        $this->assertSame('5', FormAnswer::where('question_id', $rating->id)->value('value'));
    }

    public function test_the_type_of_an_answered_question_cannot_change_and_the_editor_is_told_so(): void
    {
        [$form, $q] = $this->createAndPublish([['type' => 'text', 'label' => 'الاسم'], ['type' => 'text', 'label' => 'بلا إجابات']]);
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$q['الاسم']->id => 'ليلى']])->assertCreated();
        // The second question has an (empty) answer row too, so use a fresh unanswered one for the contrast.
        $fresh = $this->api()->putJson("/api/operations/forms/{$form->id}", ['questions' => [
            ['id' => $q['الاسم']->id, 'type' => 'text', 'label' => 'الاسم'],
            ['id' => $q['بلا إجابات']->id, 'type' => 'text', 'label' => 'بلا إجابات'],
            ['type' => 'email', 'label' => 'جديد'],
        ]])->assertOk();
        $flags = collect($fresh->json('data.questions'))->keyBy('label');
        $this->assertTrue($flags['الاسم']['has_answers']);
        $this->assertFalse($flags['جديد']['has_answers']);

        $this->api()->putJson("/api/operations/forms/{$form->id}", ['questions' => [
            ['id' => $q['الاسم']->id, 'type' => 'email', 'label' => 'الاسم'],
        ]])->assertStatus(422)->assertJsonValidationErrors('questions.0.type');
        $this->assertSame('text', $q['الاسم']->fresh()->type);
    }
}
