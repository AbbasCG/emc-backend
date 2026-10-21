<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\FormCollaborator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Who owns a form, who may open it, and who may read its responses — three separate questions.
 */
class FormOwnershipAndVisibilityTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private function create(array $over = [], $as = null): array
    {
        $as ??= $this->makeUser('operations_manager', $this->makeDepartment('قسم '.uniqid()));
        $res = $this->actingAs($as, 'sanctum')->postJson('/api/operations/forms', array_merge([
            'title' => 'نموذج', 'questions' => [['label' => 'سؤال', 'type' => 'text']],
        ], $over))->assertCreated();

        return [$as, $res->json('data'), DynamicForm::findOrFail($res->json('data.id'))];
    }

    // ── New forms ────────────────────────────────────────────────────────────

    public function test_a_new_form_is_a_private_draft_owned_by_its_creator_with_restricted_responses(): void
    {
        [$creator, $data, $form] = $this->create();

        $this->assertSame('draft', $form->status);
        $this->assertSame('private', $form->visibility);
        $this->assertSame('restricted', $form->responses_visibility);
        $this->assertSame($creator->id, (int) $form->created_by);
        $this->assertSame($creator->id, (int) $form->owner_id);
        $this->assertSame($creator->id, (int) $form->last_edited_by);
        $this->assertNull($form->published_version_id);
        $this->assertSame($creator->id, $data['owner']['id']);
        $this->assertSame($creator->id, $data['created_by']['id']);
        $this->assertNull($data['current_version']);
        $this->assertTrue($data['abilities']['manage_access']);
    }

    public function test_the_creator_can_choose_organization_visibility_when_creating(): void
    {
        [, , $form] = $this->create(['visibility' => 'organization', 'responses_visibility' => 'form_viewers']);

        $this->assertSame('organization', $form->visibility);
        $this->assertSame('form_viewers', $form->responses_visibility);
    }

    public function test_people_are_shown_by_id_and_name_only(): void
    {
        [, $data] = $this->create();

        foreach (['created_by', 'owner', 'last_editor'] as $key) {
            $this->assertSame(['id', 'name'], array_keys($data[$key] ?? ['id' => 0, 'name' => '']), $key);
        }
        $this->assertStringNotContainsString('@', json_encode($data));
    }

    // ── Private versus organization: the form definition ────────────────────

    public function test_a_private_form_is_invisible_to_everyone_but_its_owner_collaborators_and_platform_admins(): void
    {
        $dept = $this->makeDepartment('مشترك');
        $owner = $this->makeUser('operations_manager', $dept);
        $sameDept = $this->makeUser('operations_manager', $dept);
        $other = $this->makeUser('community_manager');
        [, , $form] = $this->create(['department_id' => $dept->id], $owner);

        foreach ([$sameDept, $other] as $outsider) {
            $this->actingAs($outsider, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertForbidden();
            $this->actingAs($outsider, 'sanctum')->getJson('/api/operations/forms')->assertOk()->assertJsonCount(0, 'data.data');
            $this->actingAs($outsider, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['title' => 'x'])->assertForbidden();
        }

        $this->actingAs($owner, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertOk();
        $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertOk();
        $this->actingAs($this->makeUser('super_admin'), 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertOk();
    }

    public function test_an_organization_form_follows_the_department_scope_as_before(): void
    {
        $deptA = $this->makeDepartment('أ');
        $owner = $this->makeUser('operations_manager', $deptA);
        [, , $deptForm] = $this->create(['department_id' => $deptA->id, 'visibility' => 'organization'], $owner);
        [, , $orgForm] = $this->create(['visibility' => 'organization'], $owner);
        $member = $this->makeUser('operations_manager', $deptA);
        $outsider = $this->makeUser('operations_manager', $this->makeDepartment('ب'));

        $this->actingAs($member, 'sanctum')->getJson("/api/operations/forms/{$deptForm->id}")->assertOk();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/operations/forms/{$deptForm->id}")->assertForbidden();
        // no department: every role that can reach the forms screens
        $this->actingAs($outsider, 'sanctum')->getJson("/api/operations/forms/{$orgForm->id}")->assertOk();
    }

    public function test_changing_visibility_is_owner_or_platform_admin_only_and_takes_effect_at_once(): void
    {
        $owner = $this->makeUser('operations_manager');
        $other = $this->makeUser('community_manager');
        [, , $form] = $this->create([], $owner);
        $this->actingAs($other, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertForbidden();

        $this->actingAs($owner, 'sanctum')->putJson("/api/operations/forms/{$form->id}/settings", ['visibility' => 'organization'])->assertOk()->assertJsonPath('data.visibility', 'organization');
        $this->actingAs($other, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertOk();

        // an organization-scope manager can edit the form but not decide who can reach it
        $this->actingAs($other, 'sanctum')->putJson("/api/operations/forms/{$form->id}/settings", ['visibility' => 'private'])->assertForbidden();
        $this->actingAs($this->makeUser('admin'), 'sanctum')->putJson("/api/operations/forms/{$form->id}/settings", ['visibility' => 'private'])->assertOk();
        $this->actingAs($other, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertForbidden();
    }

    // ── Responses are a separate question ───────────────────────────────────

    public function test_an_organization_form_with_restricted_responses_shows_its_definition_but_not_its_answers(): void
    {
        $owner = $this->makeUser('operations_manager');
        $other = $this->makeUser('community_manager');
        [, , $form] = $this->create(['visibility' => 'organization', 'status' => 'active'], $owner);
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$form->questions->first()->id => 'حساس']])->assertCreated();

        $this->actingAs($other, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertOk()->assertJsonMissingPath('data.submissions_count');
        $this->actingAs($other, 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertForbidden();
        $this->actingAs($other, 'sanctum')->putJson("/api/admin/forms/{$form->id}/submissions/1/status", ['status' => 'archived'])->assertForbidden();

        $this->actingAs($owner, 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->actingAs($owner, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertJsonPath('data.submissions_count', 1);
        $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertOk();
    }

    public function test_opening_the_responses_to_form_viewers_is_a_deliberate_owner_decision(): void
    {
        $owner = $this->makeUser('operations_manager');
        $other = $this->makeUser('community_manager');
        [, , $form] = $this->create(['visibility' => 'organization'], $owner);
        $this->actingAs($other, 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertForbidden();

        $this->actingAs($other, 'sanctum')->putJson("/api/operations/forms/{$form->id}/settings", ['responses_visibility' => 'form_viewers'])->assertForbidden();
        $this->actingAs($owner, 'sanctum')->putJson("/api/operations/forms/{$form->id}/settings", ['responses_visibility' => 'form_viewers'])->assertOk();

        $this->actingAs($other, 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertOk();
    }

    public function test_the_attachment_download_follows_the_response_rule_not_the_form_rule(): void
    {
        \Illuminate\Support\Facades\Storage::fake('private');
        $owner = $this->makeUser('operations_manager');
        $other = $this->makeUser('community_manager');
        [, , $form] = $this->create(['visibility' => 'organization', 'status' => 'active', 'questions' => [['label' => 'ملف', 'type' => 'file']]], $owner);
        $this->post("/api/forms/{$form->slug}/submit", ['answers' => [$form->questions->first()->id => $this->pdfUpload()]], ['Accept' => 'application/json'])->assertCreated();
        $submission = $form->submissions()->first();
        $answer = $submission->answers()->first();
        $url = "/api/operations/forms/{$form->id}/submissions/{$submission->id}/answers/{$answer->id}/file";

        $this->actingAs($other, 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($owner, 'sanctum')->get($url)->assertOk();
    }

    // ── Existing forms keep their effective access ──────────────────────────

    public function test_a_form_that_predates_phase_one_behaves_exactly_as_it_did(): void
    {
        $dept = $this->makeDepartment('قديم');
        $creator = $this->makeUser('operations_manager', $dept);
        $colleague = $this->makeUser('operations_manager', $dept);
        $outsider = $this->makeUser('operations_manager', $this->makeDepartment('بعيد'));
        // a raw row as the Phase 0 code wrote it: no owner, no visibility columns set
        $id = DB::table('dynamic_forms')->insertGetId([
            'title' => 'قديم', 'slug' => 'legacy-old', 'type' => 'general', 'status' => 'active', 'created_by' => $creator->id,
            'department_id' => $dept->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $form = DynamicForm::findOrFail($id);

        $this->assertSame('organization', $form->visibility);
        $this->assertSame('form_viewers', $form->responses_visibility);
        $this->assertNull($form->owner_id);
        $this->assertSame($creator->id, $form->effectiveOwnerId(), 'the creator is the owner when none is recorded');

        // creator, department colleague: the form and its responses. Outsider: nothing.
        foreach ([$creator, $colleague] as $user) {
            $this->actingAs($user, 'sanctum')->getJson("/api/operations/forms/{$id}")->assertOk();
            $this->actingAs($user, 'sanctum')->getJson("/api/operations/forms/{$id}/submissions")->assertOk();
            $this->actingAs($user, 'sanctum')->putJson("/api/operations/forms/{$id}", ['title' => 'مُعدّل'])->assertOk();
        }
        $this->actingAs($outsider, 'sanctum')->getJson("/api/operations/forms/{$id}")->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/operations/forms/{$id}/submissions")->assertForbidden();
    }

    // ── Ownership ────────────────────────────────────────────────────────────

    public function test_the_owner_can_transfer_ownership_and_the_creator_never_changes(): void
    {
        $owner = $this->makeUser('operations_manager');
        $heir = $this->makeUser('community_manager');
        [, , $form] = $this->create(['visibility' => 'organization'], $owner);

        $res = $this->actingAs($owner, 'sanctum')->postJson("/api/operations/forms/{$form->id}/ownership", ['owner_id' => $heir->id, 'keep_access' => 'editor', 'keep_responses' => true])->assertOk();

        $res->assertJsonPath('data.owner.id', $heir->id)->assertJsonPath('data.created_by.id', $owner->id);
        $form->refresh();
        $this->assertSame($heir->id, (int) $form->owner_id);
        $this->assertSame($owner->id, (int) $form->created_by);
        $previous = FormCollaborator::where('form_id', $form->id)->where('user_id', $owner->id)->firstOrFail();
        $this->assertSame('editor', $previous->access_level);
        $this->assertTrue($previous->can_view_responses);
        $this->assertSame($owner->id, (int) $previous->granted_by);
    }

    public function test_after_a_transfer_without_kept_access_the_former_owner_has_none(): void
    {
        $owner = $this->makeUser('operations_manager');
        $heir = $this->makeUser('community_manager');
        [, , $form] = $this->create([], $owner);

        $res = $this->actingAs($owner, 'sanctum')->postJson("/api/operations/forms/{$form->id}/ownership", ['owner_id' => $heir->id, 'keep_access' => 'none'])->assertOk();

        $res->assertJsonPath('data.access_removed', true);
        $this->assertArrayNotHasKey('title', $res->json('data'), 'nothing about the form is sent to someone who no longer has access');
        $this->actingAs($owner, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertForbidden();
        $this->actingAs($heir, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertOk();
    }

    public function test_ownership_changes_are_authorized_and_the_new_owner_must_be_able_to_manage_forms(): void
    {
        $owner = $this->makeUser('operations_manager');
        $heir = $this->makeUser('community_manager');
        $editor = $this->makeUser('operations_manager');
        $stranger = $this->makeUser('operations_manager');
        [, , $form] = $this->create(['visibility' => 'organization'], $owner);
        FormCollaborator::create(['form_id' => $form->id, 'user_id' => $editor->id, 'access_level' => 'editor', 'granted_by' => $owner->id]);

        foreach ([$editor, $stranger] as $notOwner) {
            $this->actingAs($notOwner, 'sanctum')->postJson("/api/operations/forms/{$form->id}/ownership", ['owner_id' => $heir->id])->assertForbidden();
        }
        $this->assertSame($owner->id, (int) $form->fresh()->owner_id);

        foreach ([$this->makeUser('student')->id, $this->makeUser('hr_manager')->id, $this->makeUser('executive_admin')->id, 999999, $owner->id] as $bad) {
            $this->actingAs($owner, 'sanctum')->postJson("/api/operations/forms/{$form->id}/ownership", ['owner_id' => $bad])->assertStatus(422)->assertJsonValidationErrors('owner_id');
        }

        // a platform admin may move it (e.g. when the owner left)
        $this->actingAs($this->makeUser('admin'), 'sanctum')->postJson("/api/operations/forms/{$form->id}/ownership", ['owner_id' => $heir->id])->assertOk();
    }

    public function test_an_inactive_account_cannot_be_given_ownership(): void
    {
        $owner = $this->makeUser('operations_manager');
        $gone = $this->makeUser('community_manager');
        $gone->forceFill(['is_active' => false])->save();
        [, , $form] = $this->create([], $owner);

        $this->actingAs($owner, 'sanctum')->postJson("/api/operations/forms/{$form->id}/ownership", ['owner_id' => $gone->id])->assertStatus(422);
    }

    // ── Last editor ──────────────────────────────────────────────────────────

    public function test_last_editor_moves_only_after_a_change_that_was_really_saved(): void
    {
        $owner = $this->makeUser('operations_manager');
        $editor = $this->makeUser('community_manager');
        [, , $form] = $this->create(['visibility' => 'organization'], $owner);
        $this->assertSame($owner->id, (int) $form->last_edited_by);

        // nothing changed: not an edit
        $this->actingAs($editor, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['title' => $form->title])->assertOk();
        $this->assertSame($owner->id, (int) $form->fresh()->last_edited_by);

        // refused: not an edit
        $this->actingAs($editor, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['title' => 'جديد', 'questions' => [['label' => '', 'type' => 'text']]])->assertStatus(422);
        $this->assertSame($owner->id, (int) $form->fresh()->last_edited_by);
        $this->assertSame($form->title, $form->fresh()->title);

        // saved: an edit
        $this->actingAs($editor, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['title' => 'جديد'])->assertOk()->assertJsonPath('data.last_editor.id', $editor->id);
        $this->assertSame($editor->id, (int) $form->fresh()->last_edited_by);
    }

    public function test_changing_only_the_questions_counts_as_an_edit_and_publishing_does_not(): void
    {
        $owner = $this->makeUser('operations_manager');
        $editor = $this->makeUser('community_manager');
        [, , $form] = $this->create(['visibility' => 'organization'], $owner);

        $this->actingAs($editor, 'sanctum')->putJson("/api/operations/forms/{$form->id}", ['questions' => [['id' => $form->questions->first()->id, 'label' => 'سؤال معدّل', 'type' => 'text']]])->assertOk();
        $this->assertSame($editor->id, (int) $form->fresh()->last_edited_by);

        $this->actingAs($owner, 'sanctum')->postJson("/api/operations/forms/{$form->id}/publish")->assertOk();
        $this->assertSame($editor->id, (int) $form->fresh()->last_edited_by, 'publishing is not editing');
    }
}
