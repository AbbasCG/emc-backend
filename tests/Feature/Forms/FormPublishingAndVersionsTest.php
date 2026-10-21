<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Services\Forms\FormSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * The draft-versus-published workflow: what respondents see only changes when a draft is published, every publication is
 * an immutable version, and every answer stays tied to the version it was given for.
 */
class FormPublishingAndVersionsTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private $owner;

    private $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('operations_manager');
        $res = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/forms', [
            'title' => 'استبيان', 'description' => 'الوصف الأول',
            'questions' => [
                ['label' => 'الاسم', 'type' => 'text', 'required' => true],
                ['label' => 'التقييم', 'type' => 'radio', 'options' => ['جيد', 'ممتاز']],
            ],
        ])->assertCreated();
        $this->form = DynamicForm::findOrFail($res->json('data.id'));
    }

    private function api($as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum');
    }

    private function publish(?string $note = null)
    {
        return $this->api()->postJson("/api/operations/forms/{$this->form->id}/publish", $note ? ['note' => $note] : []);
    }

    private function edit(array $payload)
    {
        return $this->api()->putJson("/api/operations/forms/{$this->form->id}", $payload);
    }

    private function questions()
    {
        return $this->form->fresh()->questions()->get();
    }

    private function publicForm()
    {
        return $this->getJson("/api/forms/{$this->form->slug}");
    }

    // ── Publishing creates an immutable version ─────────────────────────────

    public function test_publishing_freezes_the_draft_into_version_one_and_records_who_and_when(): void
    {
        $res = $this->publish('أول نشر')->assertOk();

        $res->assertJsonPath('data.status', 'active')->assertJsonPath('data.current_version', 1)->assertJsonPath('data.publisher.id', $this->owner->id)
            ->assertJsonPath('data.has_unpublished_changes', false);
        $this->assertNotNull($res->json('data.published_at'));
        $version = FormVersion::where('form_id', $this->form->id)->firstOrFail();
        $this->assertSame(1, $version->version_number);
        $this->assertSame('أول نشر', $version->note);
        $this->assertSame($this->owner->id, (int) $version->created_by);
        $this->assertSame(2, $version->question_count);
        $this->assertSame(['الاسم', 'التقييم'], array_column($version->schema['questions'], 'label'));
        $this->assertSame(['جيد', 'ممتاز'], $version->schema['questions'][1]['options']);
        $this->assertTrue($version->schema['questions'][0]['required']);
        $this->assertSame('الوصف الأول', $version->schema['form']['description']);
        $this->assertSame(FormSnapshotService::hash($version->schema), $version->schema_hash);
    }

    public function test_a_published_version_can_never_be_changed_or_removed(): void
    {
        $this->publish()->assertOk();
        $version = FormVersion::firstOrFail();

        $this->expectException(\LogicException::class);
        $version->update(['note' => 'تلاعب']);
    }

    public function test_a_published_version_cannot_be_deleted(): void
    {
        $this->publish()->assertOk();

        $this->expectException(\LogicException::class);
        FormVersion::firstOrFail()->delete();
    }

    public function test_publishing_needs_something_to_answer_and_no_form_is_half_published(): void
    {
        $empty = $this->api()->postJson('/api/operations/forms', ['title' => 'فارغ'])->assertCreated()->json('data.id');

        $this->api()->postJson("/api/operations/forms/{$empty}/publish")->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('draft', DynamicForm::find($empty)->status);
        $this->assertSame(0, FormVersion::where('form_id', $empty)->count());
    }

    public function test_a_draft_with_a_choice_question_that_has_no_options_cannot_be_published(): void
    {
        $q = $this->questions()->last();
        // a question left without options by some other route
        $q->forceFill(['options' => null])->save();

        $this->publish()->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(0, FormVersion::count());
    }

    // ── Drafts do not touch the published form ──────────────────────────────

    public function test_editing_the_draft_changes_nothing_for_respondents_until_it_is_published(): void
    {
        $this->publish()->assertOk();
        $name = $this->questions()->first();

        $this->edit(['title' => 'عنوان جديد', 'description' => 'وصف جديد', 'questions' => [
            ['id' => $name->id, 'label' => 'الاسم الكامل', 'type' => 'text', 'required' => true],
            ['label' => 'سؤال جديد إلزامي', 'type' => 'text', 'required' => true],
        ]])->assertOk()->assertJsonPath('data.has_unpublished_changes', true)->assertJsonPath('data.current_version', 1);

        $public = $this->publicForm()->assertOk();
        $public->assertJsonPath('data.title', 'استبيان')->assertJsonPath('data.description', 'الوصف الأول')->assertJsonPath('data.version', 1);
        $this->assertSame(['الاسم', 'التقييم'], array_column($public->json('data.questions'), 'label'));

        // and a respondent is validated against what they were shown: the new required question does not exist for them yet
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$name->id => 'سارة']])->assertCreated();
    }

    public function test_publishing_the_edits_creates_version_two_and_new_respondents_get_it(): void
    {
        $this->publish()->assertOk();
        $name = $this->questions()->first();
        $this->edit(['title' => 'عنوان جديد', 'questions' => [['id' => $name->id, 'label' => 'الاسم الكامل', 'type' => 'text', 'required' => true], ['label' => 'سؤال جديد', 'type' => 'text']]])->assertOk();

        $this->publish('تحديث')->assertOk()->assertJsonPath('data.current_version', 2)->assertJsonPath('data.has_unpublished_changes', false);

        $public = $this->publicForm()->assertOk()->assertJsonPath('data.title', 'عنوان جديد')->assertJsonPath('data.version', 2);
        $this->assertSame(['الاسم الكامل', 'سؤال جديد'], array_column($public->json('data.questions'), 'label'));
        $this->assertSame(2, FormVersion::count());
        $this->assertSame(['الاسم', 'التقييم'], array_column(FormVersion::where('version_number', 1)->first()->schema['questions'], 'label'), 'version 1 is exactly what it was');
    }

    public function test_publishing_with_nothing_new_is_refused_instead_of_making_an_identical_copy(): void
    {
        $this->publish()->assertOk();

        $this->publish()->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(1, FormVersion::count());
    }

    // ── Responses stay tied to the version they used ─────────────────────────

    public function test_each_submission_is_tied_to_the_version_it_was_answered_on_and_reads_back_with_its_old_labels(): void
    {
        $this->publish()->assertOk();
        [$name, $rating] = $this->questions()->all();
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$name->id => 'قبل', $rating->id => 'جيد']])->assertCreated();

        $this->edit(['questions' => [['id' => $name->id, 'label' => 'اسمك الكريم', 'type' => 'text', 'required' => true], ['id' => $rating->id, 'label' => 'رأيك', 'type' => 'radio', 'options' => ['جيد', 'ممتاز', 'رائع']]]])->assertOk();
        $this->publish()->assertOk();
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$name->id => 'بعد', $rating->id => 'رائع']])->assertCreated();

        [$old, $new] = [FormSubmission::orderBy('id')->first(), FormSubmission::orderBy('id')->skip(1)->first()];
        $this->assertSame(FormVersion::where('version_number', 1)->value('id'), (int) $old->form_version_id);
        $this->assertSame(FormVersion::where('version_number', 2)->value('id'), (int) $new->form_version_id);

        $rows = $this->api()->getJson("/api/operations/forms/{$this->form->id}/submissions")->assertOk()->json('data.data');
        $byVersion = collect($rows)->keyBy('form_version');
        $this->assertSame(['الاسم', 'التقييم'], array_column($byVersion[1]['answers'], 'question_label'), 'the old response still reads with the labels it was given under');
        $this->assertSame(['اسمك الكريم', 'رأيك'], array_column($byVersion[2]['answers'], 'question_label'));
        $this->assertStringContainsString('الاسم: قبل', $byVersion[1]['answers_preview']);
    }

    public function test_an_old_answer_value_that_the_new_version_no_longer_offers_is_still_shown(): void
    {
        $this->publish()->assertOk();
        [$name, $rating] = $this->questions()->all();
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$name->id => 'س', $rating->id => 'جيد']])->assertCreated();
        $this->edit(['questions' => [['id' => $name->id, 'label' => 'الاسم', 'type' => 'text', 'required' => true], ['id' => $rating->id, 'label' => 'التقييم', 'type' => 'radio', 'options' => ['ممتاز']]]])->assertOk();
        $this->publish()->assertOk();

        $answers = $this->api()->getJson("/api/operations/forms/{$this->form->id}/submissions")->json('data.data.0.answers');
        $this->assertSame('جيد', collect($answers)->firstWhere('question_label', 'التقييم')['value']);
        // while new respondents can only pick what version 2 offers
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$name->id => 'ص', $rating->id => 'جيد']])->assertStatus(422);
    }

    public function test_publishing_and_restoring_never_rewrite_or_remove_a_single_answer(): void
    {
        $this->publish()->assertOk();
        [$name, $rating] = $this->questions()->all();
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$name->id => 'ثابت', $rating->id => 'ممتاز']])->assertCreated();
        $before = FormAnswer::orderBy('id')->get(['id', 'question_id', 'value'])->toArray();

        $this->edit(['questions' => [['id' => $name->id, 'label' => 'جديد', 'type' => 'text']]])->assertOk();
        $this->publish()->assertOk();
        $v1 = FormVersion::where('version_number', 1)->first();
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/versions/{$v1->id}/restore")->assertOk();

        $this->assertSame($before, FormAnswer::orderBy('id')->get(['id', 'question_id', 'value'])->toArray());
        $this->assertSame(1, FormSubmission::count());
    }

    // ── Version history ──────────────────────────────────────────────────────

    public function test_the_version_list_and_a_single_version_show_what_respondents_saw(): void
    {
        $this->publish('أول')->assertOk();
        $this->edit(['title' => 'ثان', 'questions' => [['id' => $this->questions()->first()->id, 'label' => 'الاسم', 'type' => 'text']]])->assertOk();
        $this->publish('ثان')->assertOk();

        $list = $this->api()->getJson("/api/operations/forms/{$this->form->id}/versions")->assertOk()->json('data');
        $this->assertSame([2, 1], array_column($list['data'], 'version_number'), 'newest first');
        $this->assertSame([true, false], array_column($list['data'], 'is_current'));
        $this->assertSame($this->owner->id, $list['data'][0]['publisher']['id']);
        $this->assertArrayNotHasKey('questions', $list['data'][0], 'the list is the facts; the questions come with one version');

        $v1 = FormVersion::where('version_number', 1)->first();
        $one = $this->api()->getJson("/api/operations/forms/{$this->form->id}/versions/{$v1->id}")->assertOk();
        $one->assertJsonPath('data.title', 'استبيان');
        $this->assertSame(['الاسم', 'التقييم'], array_column($one->json('data.questions'), 'label'));
    }

    public function test_a_version_of_another_form_cannot_be_read_or_restored_through_this_one(): void
    {
        $other = $this->api()->postJson('/api/operations/forms', ['title' => 'آخر', 'questions' => [['label' => 'خاص', 'type' => 'text']]])->json('data.id');
        $this->api()->postJson("/api/operations/forms/{$other}/publish")->assertOk();
        $foreign = FormVersion::where('form_id', $other)->firstOrFail();

        $this->api()->getJson("/api/operations/forms/{$this->form->id}/versions/{$foreign->id}")->assertNotFound();
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/versions/{$foreign->id}/restore")->assertNotFound();
        $this->assertSame(['الاسم', 'التقييم'], $this->questions()->pluck('label')->all());
    }

    public function test_the_version_history_is_closed_to_people_who_cannot_open_the_form(): void
    {
        $this->publish()->assertOk();
        $stranger = $this->makeUser('community_manager');
        $v = FormVersion::firstOrFail();

        $this->api($stranger)->getJson("/api/operations/forms/{$this->form->id}/versions")->assertForbidden();
        $this->api($stranger)->getJson("/api/operations/forms/{$this->form->id}/versions/{$v->id}")->assertForbidden();
        $this->api($stranger)->postJson("/api/operations/forms/{$this->form->id}/versions/{$v->id}/restore")->assertForbidden();
    }

    // ── Restoring ────────────────────────────────────────────────────────────

    public function test_restoring_an_old_version_rebuilds_the_draft_and_leaves_what_is_published_alone(): void
    {
        $this->publish()->assertOk();
        [$name, $rating] = $this->questions()->all();
        $this->edit(['title' => 'نسخة ثانية', 'questions' => [['id' => $name->id, 'label' => 'الاسم المعدّل', 'type' => 'text'], ['label' => 'إضافي', 'type' => 'text']]])->assertOk();
        $this->publish()->assertOk();
        $v1 = FormVersion::where('version_number', 1)->first();

        $res = $this->api()->postJson("/api/operations/forms/{$this->form->id}/versions/{$v1->id}/restore")->assertOk();

        $res->assertJsonPath('data.title', 'استبيان')->assertJsonPath('data.current_version', 2)->assertJsonPath('data.has_unpublished_changes', true)->assertJsonPath('data.status', 'active');
        $this->assertSame(['الاسم', 'التقييم'], array_column($res->json('data.questions'), 'label'));
        $this->assertSame(['جيد', 'ممتاز'], $res->json('data.questions.1.options'));
        $this->assertSame($rating->id, $res->json('data.questions.1.id'), 'a question the version has keeps its id, so its old answers stay attached');
        $this->assertSame(2, FormVersion::count(), 'restoring publishes nothing');
        $this->publicForm()->assertJsonPath('data.title', 'نسخة ثانية')->assertJsonPath('data.version', 2);
    }

    public function test_publishing_a_restored_draft_creates_a_new_version_that_remembers_where_it_came_from(): void
    {
        $this->publish()->assertOk();
        $name = $this->questions()->first();
        $this->edit(['questions' => [['id' => $name->id, 'label' => 'تغيير', 'type' => 'text']]])->assertOk();
        $this->publish()->assertOk();
        $v1 = FormVersion::where('version_number', 1)->first();
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/versions/{$v1->id}/restore")->assertOk();

        $this->publish('عودة إلى النسخة 1')->assertOk()->assertJsonPath('data.current_version', 3);

        $this->assertSame(3, FormVersion::count());
        $this->assertSame(['الاسم', 'التقييم'], array_column(FormVersion::where('version_number', 3)->first()->schema['questions'], 'label'));
        $this->assertSame(['تغيير'], array_column(FormVersion::where('version_number', 2)->first()->schema['questions'], 'label'), 'version 2 is untouched');
    }

    public function test_restoring_a_question_whose_type_changed_after_it_was_answered_adds_it_as_a_new_question(): void
    {
        $this->publish()->assertOk();
        $name = $this->questions()->first();
        // version 1 has a text question; later it is replaced by a number question, which then gets answers
        $this->edit(['questions' => [['id' => $name->id, 'label' => 'العمر', 'type' => 'number']]])->assertOk();
        $this->publish()->assertOk();
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$name->id => '30']])->assertCreated();
        $v1 = FormVersion::where('version_number', 1)->first();

        $this->api()->postJson("/api/operations/forms/{$this->form->id}/versions/{$v1->id}/restore")->assertOk();

        $live = $this->questions();
        $this->assertSame(['الاسم', 'التقييم'], $live->pluck('label')->all());
        $this->assertNotSame($name->id, $live->first()->id, 'the answered number question was not rewritten into a text question');
        $this->assertSame('number', \App\Models\FormQuestion::withTrashed()->findOrFail($name->id)->type);
        $this->assertSame('30', FormAnswer::where('question_id', $name->id)->value('value'));
    }

    public function test_a_respondent_cannot_pick_the_version_or_answer_questions_that_exist_only_in_the_draft(): void
    {
        $this->publish()->assertOk();
        $name = $this->questions()->first();
        $this->edit(['questions' => [['id' => $name->id, 'label' => 'الاسم', 'type' => 'text', 'required' => true], ['label' => 'سؤال المسودة', 'type' => 'text']]])->assertOk();
        $draftOnly = $this->questions()->last();

        $this->postJson("/api/forms/{$this->form->slug}/submit", [
            'answers' => [$name->id => 'سارة', $draftOnly->id => 'لن يُحفظ'], 'form_version_id' => 999, 'version' => 7, 'form_id' => 123,
        ])->assertCreated();

        $submission = FormSubmission::firstOrFail();
        $this->assertSame(FormVersion::value('id'), (int) $submission->form_version_id, 'the version is the published one, whatever the request says');
        $this->assertSame($this->form->id, (int) $submission->form_id);
        $this->assertSame(0, FormAnswer::where('question_id', $draftOnly->id)->count());
        $this->assertSame(2, FormAnswer::where('submission_id', $submission->id)->count(), 'one row per question of the published version');
    }

    public function test_the_answers_of_a_response_are_always_the_questions_of_its_version(): void
    {
        $this->publish()->assertOk();
        [$name, $rating] = $this->questions()->all();
        $this->edit(['questions' => [['id' => $name->id, 'label' => 'الاسم', 'type' => 'text', 'required' => true]]])->assertOk();
        $this->publish()->assertOk();

        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$name->id => 'س', $rating->id => 'جيد']])->assertCreated();

        $this->assertSame(0, FormAnswer::where('question_id', $rating->id)->count(), 'a question removed in version 2 is not part of a version 2 response');
    }

    // ── Status moves ─────────────────────────────────────────────────────────

    public function test_closing_reopening_and_archiving_never_create_or_change_a_version(): void
    {
        $this->publish()->assertOk();
        $hash = FormVersion::first()->schema_hash;

        $this->api()->postJson("/api/operations/forms/{$this->form->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->publicForm()->assertOk()->assertJsonPath('data.availability.state', 'closed');
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/reopen")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.current_version', 1);
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->publicForm()->assertNotFound();
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/unarchive")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertSame(1, FormVersion::count());
        $this->assertSame($hash, FormVersion::first()->schema_hash);
    }

    public function test_reopening_does_not_publish_the_unpublished_edits(): void
    {
        $this->publish()->assertOk();
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/close")->assertOk();
        $this->edit(['title' => 'تعديل غير منشور'])->assertOk();

        $this->api()->postJson("/api/operations/forms/{$this->form->id}/reopen")->assertOk()->assertJsonPath('data.has_unpublished_changes', true);

        $this->publicForm()->assertJsonPath('data.title', 'استبيان');
    }

    public function test_an_unpublished_form_goes_back_to_a_draft_keeping_its_versions_and_publishing_again_reuses_the_same_version(): void
    {
        $this->publish()->assertOk();

        $this->api()->postJson("/api/operations/forms/{$this->form->id}/unpublish")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->publicForm()->assertNotFound();

        $this->publish()->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.current_version', 1);
        $this->assertSame(1, FormVersion::count(), 'nothing changed, so no second copy');
    }

    public function test_transitions_that_make_no_sense_are_refused(): void
    {
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/close")->assertStatus(422);
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/reopen")->assertStatus(422);
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/unpublish")->assertStatus(422);
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/unarchive")->assertStatus(422);
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/archive")->assertOk();
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/archive")->assertStatus(422);
        $this->assertSame('archived', $this->form->fresh()->status);
    }

    public function test_an_archived_form_that_was_never_published_comes_back_as_a_draft_not_live(): void
    {
        $this->api()->postJson("/api/operations/forms/{$this->form->id}/archive")->assertOk();

        $this->api()->postJson("/api/operations/forms/{$this->form->id}/unarchive")->assertOk()->assertJsonPath('data.status', 'draft');
    }

    // ── Preview, link, authorization ─────────────────────────────────────────

    public function test_the_preview_shows_the_draft_and_cannot_be_answered(): void
    {
        $this->publish()->assertOk();
        $this->edit(['title' => 'عنوان المسودة'])->assertOk();

        $this->api()->getJson("/api/operations/forms/{$this->form->id}/preview")->assertOk()
            ->assertJsonPath('data.title', 'عنوان المسودة')->assertJsonPath('data.preview', true)->assertJsonCount(2, 'data.questions');
        $this->publicForm()->assertJsonPath('data.title', 'استبيان');
    }

    public function test_the_public_link_of_a_published_or_answered_form_never_changes(): void
    {
        $slug = $this->form->slug;
        $this->edit(['slug' => 'before-publishing'])->assertOk();
        $this->publish()->assertOk();

        $this->edit(['slug' => 'after-publishing'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->assertSame('before-publishing', $this->form->fresh()->slug);
        $this->assertNotSame($slug, $this->form->fresh()->slug);
        $this->getJson('/api/forms/before-publishing')->assertOk();
    }

    public function test_publishing_and_the_status_buttons_follow_the_forms_authorization(): void
    {
        $outsider = $this->makeUser('community_manager');
        $student = $this->makeUser('student');

        foreach (['publish', 'close', 'reopen', 'unpublish', 'archive', 'unarchive'] as $action) {
            $this->api($outsider)->postJson("/api/operations/forms/{$this->form->id}/{$action}")->assertForbidden();
            $this->api($student)->postJson("/api/operations/forms/{$this->form->id}/{$action}")->assertForbidden();
        }
        $this->assertSame('draft', $this->form->fresh()->status);
        $this->assertSame(0, FormVersion::count());
    }

    public function test_the_older_status_field_cannot_skip_the_publishing_rules(): void
    {
        $this->edit(['status' => 'active'])->assertOk()->assertJsonPath('data.current_version', 1);
        $this->assertSame(1, FormVersion::count());
        $this->edit(['status' => 'active', 'title' => 'x'])->assertOk();
        $this->assertSame(1, FormVersion::count(), 'already live: no extra version');

        $empty = $this->api()->postJson('/api/operations/forms', ['title' => 'فارغ'])->json('data.id');
        $this->api()->putJson("/api/operations/forms/{$empty}", ['status' => 'active'])->assertStatus(422);
        $this->assertSame('draft', DynamicForm::find($empty)->status);
    }

    public function test_a_form_that_went_live_before_versions_existed_is_still_served_until_it_is_backfilled(): void
    {
        $legacy = $this->makeForm(['status' => 'active', 'slug' => 'before-versions'], [['label' => 'س', 'type' => 'text']]);

        $this->getJson('/api/forms/before-versions')->assertOk()->assertJsonCount(1, 'data.questions');
        $this->postJson('/api/forms/before-versions/submit', ['answers' => [$legacy->questions->first()->id => 'ok']])->assertCreated();
        $this->assertNull(FormSubmission::latest('id')->first()->form_version_id, 'no version to link; the response is still stored');
    }
}
