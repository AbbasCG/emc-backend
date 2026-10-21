<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * community_manager holds view_forms and manage_forms, so it reaches the forms screens (Phase 1). It gets exactly what
 * those permissions and the form-level rules give — not every form, not every response — and nothing changes for tasks or
 * meetings.
 */
class CommunityManagerFormsAccessTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private User $cm;

    private $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = $this->makeDepartment('المجتمع');
        $this->cm = $this->makeUser('community_manager', $this->dept);
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'sanctum');
    }

    private function revoke(User $user, string $permission): void
    {
        $row = Permission::firstOrCreate(['name' => $permission], ['display_name' => $permission, 'group' => 'operations']);
        UserPermissionAssignment::create(['user_id' => $user->id, 'permission_id' => $row->id, 'state' => 'deny', 'scope_type' => 'global', 'source_type' => 'direct_admin']);
        $user->flushPermissionCache();
    }

    public function test_it_reaches_the_forms_screens_and_creates_private_drafts_of_its_own(): void
    {
        $this->as($this->cm)->getJson('/api/operations/forms')->assertOk();

        $res = $this->as($this->cm)->postJson('/api/operations/forms', ['title' => 'من المجتمع', 'questions' => [['label' => 'س', 'type' => 'text']]])->assertCreated();

        $res->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.owner.id', $this->cm->id);
        $this->as($this->cm)->postJson('/api/operations/forms/'.$res->json('data.id').'/publish')->assertOk();
    }

    public function test_it_does_not_see_everything_other_peoples_private_forms_stay_private(): void
    {
        $ops = $this->makeUser('operations_manager', $this->dept);
        $mine = $this->makeForm(['owner_id' => $this->cm->id, 'created_by' => $this->cm->id, 'visibility' => 'private'], [['label' => 'x', 'type' => 'text']]);
        $theirPrivate = $this->makeForm(['owner_id' => $ops->id, 'created_by' => $ops->id, 'visibility' => 'private'], [['label' => 'x', 'type' => 'text']]);
        $orgNoDept = $this->makeForm(['owner_id' => $ops->id, 'created_by' => $ops->id, 'visibility' => 'organization'], [['label' => 'x', 'type' => 'text']]);
        $ownDept = $this->makeForm(['owner_id' => $ops->id, 'created_by' => $ops->id, 'visibility' => 'organization', 'department_id' => $this->dept->id], [['label' => 'x', 'type' => 'text']]);
        $otherDept = $this->makeForm(['owner_id' => $ops->id, 'created_by' => $ops->id, 'visibility' => 'organization', 'department_id' => $this->makeDepartment('بعيد')->id], [['label' => 'x', 'type' => 'text']]);

        $ids = collect($this->as($this->cm)->getJson('/api/operations/forms')->json('data.data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $orgNoDept->id, $ownDept->id], $ids);
        $this->as($this->cm)->getJson("/api/operations/forms/{$theirPrivate->id}")->assertForbidden();
        $this->as($this->cm)->getJson("/api/operations/forms/{$otherDept->id}")->assertForbidden();
        $this->as($this->cm)->putJson("/api/operations/forms/{$theirPrivate->id}", ['title' => 'x'])->assertForbidden();
    }

    public function test_it_manages_forms_in_its_scope_but_only_the_owner_decides_who_can_reach_them(): void
    {
        $ops = $this->makeUser('operations_manager');
        $form = $this->makeForm(['owner_id' => $ops->id, 'created_by' => $ops->id, 'visibility' => 'organization'], [['label' => 'x', 'type' => 'text']]);

        $this->as($this->cm)->putJson("/api/operations/forms/{$form->id}", ['title' => 'عنوان المجتمع'])->assertOk();
        $this->as($this->cm)->postJson("/api/operations/forms/{$form->id}/publish")->assertOk();
        $this->as($this->cm)->putJson("/api/operations/forms/{$form->id}/settings", ['max_submissions' => 5])->assertOk();

        $this->as($this->cm)->putJson("/api/operations/forms/{$form->id}/settings", ['visibility' => 'private'])->assertForbidden();
        $this->as($this->cm)->postJson("/api/operations/forms/{$form->id}/collaborators", ['user_id' => $this->makeUser('operations_manager')->id, 'access_level' => 'viewer'])->assertForbidden();
        $this->as($this->cm)->deleteJson("/api/admin/forms/{$form->id}")->assertForbidden();
    }

    public function test_it_reads_responses_only_where_a_form_lets_it(): void
    {
        $ops = $this->makeUser('operations_manager');
        $restricted = $this->makeForm(['owner_id' => $ops->id, 'created_by' => $ops->id, 'visibility' => 'organization', 'responses_visibility' => 'restricted'], [['label' => 'x', 'type' => 'text']]);
        $open = $this->makeForm(['owner_id' => $ops->id, 'created_by' => $ops->id, 'visibility' => 'organization', 'responses_visibility' => 'form_viewers'], [['label' => 'x', 'type' => 'text']]);

        $this->as($this->cm)->getJson("/api/operations/forms/{$restricted->id}/submissions")->assertForbidden();
        $this->as($this->cm)->getJson("/api/operations/forms/{$open->id}/submissions")->assertOk();
    }

    public function test_the_forms_permissions_still_decide_what_it_may_do(): void
    {
        $ops = $this->makeUser('operations_manager');
        $form = $this->makeForm(['owner_id' => $ops->id, 'created_by' => $ops->id, 'visibility' => 'organization'], [['label' => 'x', 'type' => 'text']]);
        $this->revoke($this->cm, Permissions::MANAGE_FORMS);

        $this->as($this->cm)->getJson("/api/operations/forms/{$form->id}")->assertOk();
        $this->as($this->cm)->postJson('/api/operations/forms', ['title' => 'x'])->assertForbidden();
        $this->as($this->cm)->putJson("/api/operations/forms/{$form->id}", ['title' => 'x'])->assertForbidden();
        $this->as($this->cm)->postJson("/api/operations/forms/{$form->id}/publish")->assertForbidden();
    }

    public function test_without_view_forms_it_sees_no_form_even_one_it_was_made_a_collaborator_of(): void
    {
        $ops = $this->makeUser('operations_manager');
        $form = $this->makeForm(['owner_id' => $ops->id, 'created_by' => $ops->id, 'visibility' => 'private'], [['label' => 'x', 'type' => 'text']]);
        \App\Models\FormCollaborator::create(['form_id' => $form->id, 'user_id' => $this->cm->id, 'access_level' => 'editor', 'granted_by' => $ops->id]);
        $this->as($this->cm)->getJson("/api/operations/forms/{$form->id}")->assertOk();

        $this->revoke($this->cm, Permissions::VIEW_FORMS);

        $this->as($this->cm)->getJson("/api/operations/forms/{$form->id}")->assertForbidden();
        $this->as($this->cm)->getJson('/api/operations/forms')->assertForbidden();
    }

    public function test_it_can_be_given_access_to_a_form_by_its_owner(): void
    {
        $ops = $this->makeUser('operations_manager', $this->makeDepartment('بعيد'));
        $form = $this->makeForm(['owner_id' => $ops->id, 'created_by' => $ops->id, 'visibility' => 'private'], [['label' => 'x', 'type' => 'text']]);

        $this->as($ops)->postJson("/api/operations/forms/{$form->id}/collaborators", ['user_id' => $this->cm->id, 'access_level' => 'editor', 'can_view_responses' => true])->assertCreated();

        $this->as($this->cm)->getJson("/api/operations/forms/{$form->id}")->assertOk();
        $this->as($this->cm)->putJson("/api/operations/forms/{$form->id}", ['title' => 'من المتعاون'])->assertOk();
        $this->as($this->cm)->getJson("/api/operations/forms/{$form->id}/submissions")->assertOk();
    }

    public function test_the_fix_is_forms_only_tasks_and_meetings_still_refuse_it_and_other_roles_are_unchanged(): void
    {
        $this->as($this->cm)->getJson('/api/operations/tasks')->assertForbidden();
        $this->as($this->cm)->getJson('/api/operations/meetings')->assertForbidden();

        foreach (['hr_manager', 'programs_manager', 'department_manager', 'executive_admin', 'volunteer', 'student'] as $role) {
            $this->as($this->makeUser($role))->getJson('/api/operations/forms')->assertForbidden();
        }
        $this->assertSame(0, DynamicForm::count());
    }
}
