<?php

namespace Tests\Feature\Forms;

use App\Models\ActivityLog;
use App\Models\DynamicForm;
use App\Models\FormTemplate;
use App\Services\Forms\FormQuestionSyncService;
use App\Services\Forms\Templates\SystemFormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Smart Forms Phase 6: the template library, using a template, saving a form as a template, sharing templates with a department,
 * and duplicating a form.
 */
class FormTemplatesTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private $deptA;

    private $deptB;

    private $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deptA = $this->makeDepartment('قسم أ');
        $this->deptB = $this->makeDepartment('قسم ب');
        $this->owner = $this->makeUser('operations_manager', $this->deptA);
    }

    /** A form with a rule (the second block shows only when the first is "no"), built through the API. */
    private function formWithRule(array $extra = []): DynamicForm
    {
        $res = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/forms', $extra + [
            'title'         => 'استطلاع القسم',
            'department_id' => $this->deptA->id,
            'flow'          => ['review_step' => true],
            'questions'     => [
                ['ref' => -1, 'label' => 'هل أنت راضٍ؟', 'type' => 'yes_no', 'required' => true],
                ['ref' => -2, 'label' => 'لماذا؟', 'type' => 'textarea', 'logic' => ['action' => 'show', 'match' => 'all', 'conditions' => [['question' => -1, 'operator' => 'equals', 'value' => 'no']]]],
                ['ref' => -3, 'label' => 'المدينة', 'type' => 'select', 'options' => ['أمستردام', 'روتردام']],
            ],
        ])->assertCreated();

        return DynamicForm::findOrFail($res->json('data.id'));
    }

    // ── The built-in library ─────────────────────────────────────────────────

    public function test_the_library_holds_the_eighteen_built_in_templates(): void
    {
        $this->assertCount(18, SystemFormTemplates::all());

        $data = $this->actingAs($this->owner, 'sanctum')->getJson('/api/operations/form-templates?source=system')->assertOk()->json('data');
        $this->assertCount(18, $data);
        $this->assertContains('system:leave_request', array_column($data, 'id'));
        $this->assertSame('system', $data[0]['source']);
    }

    public function test_every_built_in_template_is_valid_and_publishable(): void
    {
        $admin = $this->makeUser('admin');
        foreach (SystemFormTemplates::all() as $key => $t) {
            // the same checks a builder payload goes through
            FormQuestionSyncService::normalizeAll($t['definition']['questions']);

            $id = $this->actingAs($admin, 'sanctum')->postJson("/api/operations/form-templates/system:{$key}/use")->assertCreated()->json('data.id');
            $this->actingAs($admin, 'sanctum')->postJson("/api/operations/forms/{$id}/publish")->assertOk();

            $form = DynamicForm::findOrFail($id);
            $this->assertSame(count($t['definition']['questions']), $form->questions()->count(), $key);
        }
    }

    public function test_a_template_summary_has_the_shape_the_screens_read(): void
    {
        $keys = ['id', 'source', 'key', 'name', 'description', 'category', 'form_type', 'domain_notice', 'visibility', 'department', 'owner',
            'question_count', 'block_count', 'uses_count', 'updated_at', 'abilities'];
        $res = $this->actingAs($this->owner, 'sanctum')->getJson('/api/operations/form-templates?source=system')->assertOk();
        $this->assertSame($keys, array_keys($res->json('data.0')));
        $this->assertSame(['categories', 'can_create', 'can_share_organization', 'departments'], array_keys($res->json('meta')));
        $this->assertSame([$this->deptA->id], array_column($res->json('meta.departments'), 'id'), 'only the departments the user may use');

        $form = $this->formWithRule();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/form-templates', ['form_id' => $form->id, 'name' => 'ق'])->assertCreated();
        $saved = $this->actingAs($this->owner, 'sanctum')->getJson('/api/operations/form-templates?source=mine')->json('data.0');
        $this->assertSame([...array_slice($keys, 0, 11), 'is_mine', ...array_slice($keys, 11)], array_keys($saved));

        $detail = $this->actingAs($this->owner, 'sanctum')->getJson('/api/operations/form-templates/system:course_evaluation')->assertOk()->json('data');
        $this->assertSame(['title', 'description', 'type', 'flow', 'questions'], array_keys($detail['definition']));
    }

    public function test_required_flags_survive_in_the_built_in_templates(): void
    {
        $questions = collect(SystemFormTemplates::find('course_evaluation')['definition']['questions'])->keyBy('label');
        $this->assertTrue($questions['اسم الدورة']['required']);
        $this->assertTrue($questions['تقييمك العام للدورة']['required'], 'a star rating declared required stays required');
        $this->assertTrue($questions['ما مدى احتمال أن توصي بهذه الدورة لغيرك؟']['required']);
        $this->assertFalse($questions['اقتراحات لتحسين الدورة']['required']);
        $this->assertFalse(collect(SystemFormTemplates::find('contact_request')['definition']['questions'])->firstWhere('label', 'طريقة التواصل المفضلة')['required'], 'an optional choice stays optional');
    }

    public function test_templates_that_overlap_an_existing_workflow_say_so(): void
    {
        foreach (['volunteer_application', 'ambassador_application', 'consultant_application', 'partnership_request', 'complaint', 'leave_request', 'reimbursement_request', 'course_registration'] as $key) {
            $this->assertNotEmpty(SystemFormTemplates::find($key)['domain_notice'], $key);
        }
    }

    public function test_the_library_filters_by_category_and_search(): void
    {
        $hr = $this->actingAs($this->owner, 'sanctum')->getJson('/api/operations/form-templates?category=hr')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['system:internal_hr_request', 'system:leave_request'], array_column($hr, 'id'));

        $found = $this->actingAs($this->owner, 'sanctum')->getJson('/api/operations/form-templates?search='.urlencode('إجازة'))->assertOk()->json('data');
        $this->assertSame(['system:leave_request'], array_column($found, 'id'));

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/operations/form-templates?category=unknown')->assertUnprocessable();
    }

    // ── Using a template ─────────────────────────────────────────────────────

    public function test_using_a_template_creates_a_private_draft_owned_by_the_user_with_its_rules(): void
    {
        $res = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/form-templates/system:course_evaluation/use', [
            'title' => 'تقييم دورة الربيع', 'department_id' => $this->deptA->id,
        ])->assertCreated();

        $form = DynamicForm::findOrFail($res->json('data.id'));
        $this->assertSame('تقييم دورة الربيع', $form->title);
        $this->assertSame(['draft', 'private', 'restricted', 'course_eval'], [$form->status, $form->visibility, $form->responses_visibility, $form->type]);
        $this->assertSame($this->owner->id, $form->owner_id);
        $this->assertSame($this->deptA->id, $form->department_id);
        $this->assertNull($form->published_version_id);

        // the rule names the stored id of the yes/no question, not the template ref
        $questions = $form->questions()->get();
        $yesNo = $questions->firstWhere('type', 'yes_no');
        $dependent = $questions->first(fn ($q) => $q->logic !== null);
        $this->assertSame($yesNo->id, $dependent->logic['conditions'][0]['question']);

        $this->assertDatabaseHas('activity_logs', ['entity_id' => $form->id, 'action' => 'form.created_from_template']);
    }

    public function test_a_template_cannot_place_a_form_in_a_department_the_user_does_not_belong_to(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/form-templates/system:complaint/use', ['department_id' => $this->deptB->id])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->assertSame(0, DynamicForm::count());
    }

    public function test_unknown_templates_are_not_found_and_roles_outside_forms_are_refused(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/operations/form-templates/system:nope')->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/form-templates/999999/use')->assertNotFound();

        $student = $this->makeUser('student');
        $this->actingAs($student, 'sanctum')->getJson('/api/operations/form-templates')->assertForbidden();
        $this->actingAs($student, 'sanctum')->postJson('/api/operations/form-templates/system:complaint/use')->assertForbidden();
    }

    // ── Saving a form as a template ──────────────────────────────────────────

    public function test_a_form_saved_as_a_template_reproduces_its_structure_and_rules(): void
    {
        $form = $this->formWithRule();
        $res = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/form-templates', [
            'form_id' => $form->id, 'name' => 'قالب الاستطلاع', 'category' => 'feedback',
        ])->assertCreated()->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.question_count', 3);

        $template = FormTemplate::findOrFail($res->json('data.id'));
        $this->assertSame([-1, -2, -3], array_column($template->definition['questions'], 'ref'));
        $this->assertSame(-1, $template->definition['questions'][1]['logic']['conditions'][0]['question']);
        $this->assertSame(['review_step' => true], $template->definition['flow']);

        $newId = $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/form-templates/{$template->id}/use")->assertCreated()->json('data.id');
        $copy = DynamicForm::findOrFail($newId);
        $this->assertSame(['review_step' => true], $copy->flow);
        $q = $copy->questions()->get();
        $this->assertSame($q[0]->id, $q[1]->logic['conditions'][0]['question']);
        $this->assertSame(1, $template->refresh()->uses_count);
        $this->assertDatabaseHas('activity_logs', ['entity_id' => $form->id, 'action' => 'form.saved_as_template']);
    }

    public function test_a_template_never_contains_responses(): void
    {
        $form = $this->formWithRule(['status' => 'active']);
        $form->update(['allow_anonymous' => true]);
        $first = $form->questions()->first();
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$first->id => 'yes']]);

        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/form-templates', ['form_id' => $form->id, 'name' => 'ق'])->assertCreated()->json('data.id');
        $json = json_encode(FormTemplate::findOrFail($id)->definition, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('submission', $json);
        $this->assertStringNotContainsString('answers', $json);
    }

    public function test_saving_a_form_you_cannot_see_is_not_found(): void
    {
        $form = $this->formWithRule();
        $stranger = $this->makeUser('operations_manager', $this->deptB);

        $this->actingAs($stranger, 'sanctum')->postJson('/api/operations/form-templates', ['form_id' => $form->id, 'name' => 'x'])->assertNotFound();
        $this->assertSame(0, FormTemplate::count());
    }

    // ── Who sees a saved template ────────────────────────────────────────────

    public function test_private_department_and_organization_visibility(): void
    {
        $form = $this->formWithRule();
        $colleague = $this->makeUser('community_manager', $this->deptA);
        $outsider = $this->makeUser('operations_manager', $this->deptB);
        $admin = $this->makeUser('admin');

        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/form-templates', ['form_id' => $form->id, 'name' => 'قالب'])->json('data.id');
        $saved = fn ($user) => array_column($this->actingAs($user, 'sanctum')->getJson('/api/operations/form-templates?source=saved')->assertOk()->json('data'), 'id');

        // private
        $this->assertSame([(string) $id], $saved($this->owner));
        $this->assertSame([], $saved($colleague));
        $this->actingAs($colleague, 'sanctum')->getJson("/api/operations/form-templates/{$id}")->assertNotFound();

        // department
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/form-templates/{$id}", ['visibility' => 'department', 'department_id' => $this->deptA->id])->assertOk();
        $this->assertSame([(string) $id], $saved($colleague));
        $this->assertSame([], $saved($outsider));
        $this->actingAs($outsider, 'sanctum')->postJson("/api/operations/form-templates/{$id}/use")->assertNotFound();

        // organization: only platform-wide managers may publish to everyone
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/form-templates/{$id}", ['visibility' => 'organization'])
            ->assertUnprocessable()->assertJsonValidationErrors('visibility');
        $this->actingAs($admin, 'sanctum')->putJson("/api/operations/form-templates/{$id}", ['visibility' => 'organization'])->assertOk();
        $this->assertSame([(string) $id], $saved($outsider));
    }

    public function test_a_template_cannot_be_shared_with_a_department_the_user_does_not_belong_to(): void
    {
        $form = $this->formWithRule();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/form-templates', [
            'form_id' => $form->id, 'name' => 'x', 'visibility' => 'department', 'department_id' => $this->deptB->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('department_id');
    }

    public function test_only_the_owner_or_a_platform_manager_changes_or_deletes_a_template(): void
    {
        $form = $this->formWithRule();
        $colleague = $this->makeUser('community_manager', $this->deptA);
        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/form-templates', [
            'form_id' => $form->id, 'name' => 'قالب', 'visibility' => 'department', 'department_id' => $this->deptA->id,
        ])->json('data.id');

        $this->actingAs($colleague, 'sanctum')->getJson("/api/operations/form-templates/{$id}")->assertOk()->assertJsonPath('data.abilities.edit', false);
        $this->actingAs($colleague, 'sanctum')->putJson("/api/operations/form-templates/{$id}", ['name' => 'مختطف'])->assertForbidden();
        $this->actingAs($colleague, 'sanctum')->deleteJson("/api/operations/form-templates/{$id}")->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/form-templates/{$id}", ['name' => 'قالب محدث'])->assertOk()->assertJsonPath('data.name', 'قالب محدث');
        $this->actingAs($this->makeUser('admin'), 'sanctum')->deleteJson("/api/operations/form-templates/{$id}")->assertOk();
        $this->assertSoftDeleted('form_templates', ['id' => $id]);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/operations/form-templates/{$id}")->assertNotFound();
    }

    public function test_replacing_a_template_structure_from_a_form(): void
    {
        $form = $this->formWithRule();
        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/form-templates', ['form_id' => $form->id, 'name' => 'قالب'])->json('data.id');

        $other = $this->makeForm(['department_id' => $this->deptA->id], [['label' => 'سؤال وحيد', 'type' => 'text']], $this->owner);
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/form-templates/{$id}", ['from_form_id' => $other->id])->assertOk()->assertJsonPath('data.question_count', 1);
        $this->assertSame('سؤال وحيد', FormTemplate::findOrFail($id)->definition['questions'][0]['label']);
    }

    // ── Duplicating a form ───────────────────────────────────────────────────

    public function test_duplicating_copies_the_structure_but_not_responses_sharing_or_versions(): void
    {
        $form = $this->formWithRule(['status' => 'active']);
        $form->update(['allow_anonymous' => true, 'max_submissions' => 50]);
        $colleague = $this->makeUser('community_manager', $this->deptB);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$form->id}/collaborators", ['user_id' => $colleague->id, 'access_level' => 'editor'])->assertCreated();
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$form->questions()->first()->id => 'yes']])->assertCreated();

        $res = $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$form->id}/duplicate")->assertCreated();
        $copy = DynamicForm::findOrFail($res->json('data.id'));

        $this->assertSame('نسخة من استطلاع القسم', $copy->title);
        $this->assertNotSame($form->slug, $copy->slug);
        $this->assertSame(['draft', 'private', $this->deptA->id], [$copy->status, $copy->visibility, $copy->department_id]);
        $this->assertNull($copy->published_version_id);
        $this->assertNull($copy->max_submissions);
        $this->assertSame(0, $copy->submissions()->count());
        $this->assertSame(0, $copy->collaborators()->count());
        $this->assertSame(0, $copy->versions()->count());
        $this->assertSame(3, $copy->questions()->count());
        $this->assertSame(1, $form->submissions()->count(), 'the source keeps its responses');
        $this->assertDatabaseHas('activity_logs', ['entity_id' => $copy->id, 'action' => 'form.duplicated']);
    }

    public function test_duplicating_needs_access_to_the_source(): void
    {
        $form = $this->formWithRule();
        $outsider = $this->makeUser('operations_manager', $this->deptB);

        $this->actingAs($outsider, 'sanctum')->postJson("/api/operations/forms/{$form->id}/duplicate")->assertForbidden();
        $this->assertSame(1, DynamicForm::count());
    }

    public function test_a_collaborator_from_another_department_gets_a_copy_without_that_department(): void
    {
        $form = $this->formWithRule();
        $guest = $this->makeUser('community_manager', $this->deptB);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$form->id}/collaborators", ['user_id' => $guest->id, 'access_level' => 'viewer'])->assertCreated();

        $id = $this->actingAs($guest, 'sanctum')->postJson("/api/operations/forms/{$form->id}/duplicate", ['title' => 'نسختي'])->assertCreated()->json('data.id');
        $copy = DynamicForm::findOrFail($id);
        $this->assertNull($copy->department_id);
        $this->assertSame($guest->id, $copy->owner_id);
        $this->assertSame('نسختي', $copy->title);
    }

    public function test_activity_labels_exist_for_the_new_actions(): void
    {
        foreach (['created_from_template', 'duplicated', 'saved_as_template'] as $action) {
            $this->assertArrayHasKey($action, \App\Services\Forms\FormActivityService::LABELS);
        }
        $this->assertSame(0, ActivityLog::count());
    }
}
