<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\FormCollaborator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Sharing a form with individual employees, across departments, without opening the departments to each other.
 */
class FormCollaborationTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private $deptA;

    private $deptB;

    private $owner;

    private $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deptA = $this->makeDepartment('قسم أ');
        $this->deptB = $this->makeDepartment('قسم ب');
        $this->owner = $this->makeUser('operations_manager', $this->deptA);
        $res = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/forms', [
            'title' => 'نموذج قسم أ', 'department_id' => $this->deptA->id, 'status' => 'active',
            'questions' => [['label' => 'سري', 'type' => 'text']],
        ])->assertCreated();
        $this->form = DynamicForm::findOrFail($res->json('data.id'));
    }

    private function share($user, string $level = 'viewer', bool $responses = false)
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/collaborators", [
            'user_id' => $user->id, 'access_level' => $level, 'can_view_responses' => $responses,
        ]);
    }

    private function respond(): void
    {
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$this->form->questions->first()->id => 'إجابة حساسة']])->assertCreated();
    }

    // ── Cross-department collaboration ───────────────────────────────────────

    public function test_an_employee_of_another_department_can_be_given_access_to_just_this_form(): void
    {
        $guest = $this->makeUser('community_manager', $this->deptB);
        $otherForm = $this->makeForm(['department_id' => $this->deptA->id, 'visibility' => 'organization'], [['label' => 'x', 'type' => 'text']], $this->owner);

        $this->actingAs($guest, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}")->assertForbidden();
        $this->share($guest)->assertCreated()->assertJsonPath('data.access_level', 'viewer');

        $this->actingAs($guest, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}")->assertOk();
        $list = $this->actingAs($guest, 'sanctum')->getJson('/api/operations/forms')->assertOk()->json('data.data');
        $this->assertSame([$this->form->id], array_column($list, 'id'), 'the guest sees the shared form and nothing else from the department');
        $this->actingAs($guest, 'sanctum')->getJson("/api/operations/forms/{$otherForm->id}")->assertForbidden();
    }

    public function test_removing_a_collaborator_takes_the_access_back_at_once(): void
    {
        $guest = $this->makeUser('community_manager', $this->deptB);
        $id = $this->share($guest)->json('data.id');
        $this->actingAs($guest, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}")->assertOk();

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/operations/forms/{$this->form->id}/collaborators/{$id}")->assertOk();

        $this->actingAs($guest, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}")->assertForbidden();
        $this->assertDatabaseMissing('form_collaborators', ['id' => $id]);
    }

    // ── Viewer and editor ────────────────────────────────────────────────────

    public function test_a_viewer_can_read_the_form_but_not_change_publish_or_share_it(): void
    {
        $viewer = $this->makeUser('community_manager', $this->deptB);
        $this->share($viewer, 'viewer');

        $this->actingAs($viewer, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}")->assertOk()->assertJsonPath('data.abilities.edit', false);
        $this->actingAs($viewer, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/versions")->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/activity")->assertOk();
        $this->actingAs($viewer, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/preview")->assertOk();

        $this->actingAs($viewer, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}", ['title' => 'خطف'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/publish")->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/close")->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/settings", ['max_submissions' => 5])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/collaborators")->assertForbidden();
        $this->assertSame('نموذج قسم أ', $this->form->fresh()->title);
    }

    public function test_an_editor_can_change_the_form_but_not_who_can_reach_it(): void
    {
        $editor = $this->makeUser('community_manager', $this->deptB);
        $friend = $this->makeUser('operations_manager', $this->deptB);
        $this->share($editor, 'editor');

        $this->actingAs($editor, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}", ['title' => 'عنوان المحرر'])->assertOk();
        $this->assertSame('عنوان المحرر', $this->form->fresh()->title);
        $this->actingAs($editor, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/settings", ['max_submissions' => 50])->assertOk();
        $this->actingAs($editor, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/collaborators")->assertOk();

        // sharing, ownership, visibility, deletion: owner / platform admins only
        $this->actingAs($editor, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $friend->id, 'access_level' => 'editor'])->assertForbidden();
        $this->actingAs($editor, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/ownership", ['owner_id' => $editor->id])->assertForbidden();
        $this->actingAs($editor, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/settings", ['visibility' => 'organization'])->assertForbidden();
        $this->actingAs($editor, 'sanctum')->deleteJson("/api/admin/forms/{$this->form->id}")->assertForbidden();
        $this->assertDatabaseMissing('form_collaborators', ['user_id' => $friend->id]);
        $this->assertNull($this->form->fresh()->deleted_at);
    }

    public function test_being_an_editor_does_not_open_the_responses(): void
    {
        $editor = $this->makeUser('community_manager', $this->deptB);
        $this->share($editor, 'editor', false);
        $this->respond();

        $this->actingAs($editor, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/submissions")->assertForbidden();
        $this->actingAs($editor, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}")->assertJsonPath('data.abilities.view_responses', false)->assertJsonMissingPath('data.submissions_count');
    }

    public function test_response_access_is_its_own_grant_and_works_for_a_viewer(): void
    {
        $viewer = $this->makeUser('community_manager', $this->deptB);
        $this->share($viewer, 'viewer', true);
        $this->respond();

        $this->actingAs($viewer, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/submissions")->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->actingAs($viewer, 'sanctum')->putJson("/api/admin/forms/{$this->form->id}/submissions/1/status", ['status' => 'reviewed'])->assertForbidden();
    }

    public function test_an_editor_with_response_access_may_also_triage_the_responses(): void
    {
        $editor = $this->makeUser('community_manager', $this->deptB);
        $this->share($editor, 'editor', true);
        $this->respond();

        $this->actingAs($editor, 'sanctum')->putJson("/api/admin/forms/{$this->form->id}/submissions/1/status", ['status' => 'reviewed'])->assertOk();
    }

    public function test_changing_a_collaborators_level_changes_what_they_can_do_immediately(): void
    {
        $guest = $this->makeUser('community_manager', $this->deptB);
        $id = $this->share($guest, 'viewer')->json('data.id');
        $this->actingAs($guest, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}", ['title' => 'x'])->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/collaborators/{$id}", ['access_level' => 'editor', 'can_view_responses' => true])
            ->assertOk()->assertJsonPath('data.access_level', 'editor')->assertJsonPath('data.can_view_responses', true);

        $this->actingAs($guest, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}", ['title' => 'مسموح'])->assertOk();
        $this->actingAs($guest, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/submissions")->assertOk();

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/collaborators/{$id}", ['access_level' => 'viewer', 'can_view_responses' => false])->assertOk();
        $this->actingAs($guest, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}", ['title' => 'مرفوض'])->assertForbidden();
        $this->actingAs($guest, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/submissions")->assertForbidden();
    }

    // ── Rules ────────────────────────────────────────────────────────────────

    public function test_who_granted_and_when_is_recorded_and_shown(): void
    {
        $guest = $this->makeUser('community_manager', $this->deptB);
        $this->share($guest);

        $row = $this->actingAs($this->owner, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/collaborators")->assertOk()->json('data.0');

        $this->assertSame($this->owner->id, $row['granted_by']['id']);
        $this->assertNotNull($row['granted_at']);
        $this->assertSame($guest->name, $row['user']['name']);
        $this->assertSame(['id', 'name', 'role', 'departments'], array_keys($row['user']), 'no e-mail or other personal detail');
        $this->assertSame([$this->deptB->name], $row['user']['departments']);
    }

    public function test_a_person_cannot_be_added_twice_and_the_owner_cannot_be_a_collaborator(): void
    {
        $guest = $this->makeUser('community_manager', $this->deptB);
        $this->share($guest)->assertCreated();

        $this->share($guest)->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->share($this->owner)->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertSame(1, FormCollaborator::where('form_id', $this->form->id)->count());
    }

    public function test_only_eligible_employees_can_be_added(): void
    {
        $inactive = $this->makeUser('community_manager');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$this->makeUser('student'), $this->makeUser('hr_manager'), $this->makeUser('instructor'), $this->makeUser('executive_admin'), $inactive] as $bad) {
            $this->share($bad)->assertStatus(422)->assertJsonValidationErrors('user_id');
        }
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => 999999, 'access_level' => 'viewer'])->assertStatus(422);
        $this->assertSame(0, FormCollaborator::count());
    }

    public function test_the_level_must_be_viewer_or_editor(): void
    {
        $guest = $this->makeUser('community_manager');

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $guest->id, 'access_level' => 'owner'])
            ->assertStatus(422)->assertJsonValidationErrors('access_level');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $guest->id])->assertStatus(422);
    }

    public function test_a_collaborator_row_of_another_form_cannot_be_touched_through_this_one(): void
    {
        $guest = $this->makeUser('community_manager', $this->deptB);
        $mine = $this->makeUser('operations_manager', $this->deptB);
        $theirForm = $this->makeForm(['owner_id' => $mine->id, 'visibility' => 'private', 'created_by' => $mine->id], [['label' => 'x', 'type' => 'text']]);
        $foreign = FormCollaborator::create(['form_id' => $theirForm->id, 'user_id' => $guest->id, 'access_level' => 'viewer', 'granted_by' => $mine->id]);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/collaborators/{$foreign->id}", ['access_level' => 'editor'])->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/operations/forms/{$this->form->id}/collaborators/{$foreign->id}")->assertNotFound();
        $this->assertSame('viewer', $foreign->fresh()->access_level);
    }

    public function test_people_without_manage_access_cannot_even_probe_the_candidate_search(): void
    {
        $viewer = $this->makeUser('community_manager', $this->deptB);
        $this->share($viewer);

        $this->actingAs($viewer, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/collaborator-candidates?q=".urlencode('مدير'))->assertForbidden();
        $this->actingAs($this->makeUser('operations_manager'), 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/collaborator-candidates?q=ab")->assertForbidden();
    }

    public function test_the_employee_search_finds_eligible_people_by_name_and_exposes_no_contact_details(): void
    {
        $target = $this->makeUser('community_manager', $this->deptB);
        $target->forceFill(['name' => 'ليلى الباحثة'])->save();
        $already = $this->makeUser('operations_manager');
        $already->forceFill(['name' => 'ليلى المضافة'])->save();
        $this->share($already);
        $student = $this->makeUser('student');
        $student->forceFill(['name' => 'ليلى الطالبة'])->save();
        $gone = $this->makeUser('community_manager');
        $gone->forceFill(['name' => 'ليلى المعطّلة', 'is_active' => false])->save();
        $this->owner->forceFill(['name' => 'ليلى المالكة'])->save();

        $res = $this->actingAs($this->owner, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/collaborator-candidates?q=".urlencode('ليلى'))->assertOk();

        $this->assertSame(['ليلى الباحثة'], array_column($res->json('data'), 'name'), 'not the owner, not already added, not a student, not inactive');
        $this->assertSame(['id', 'name', 'role', 'departments'], array_keys($res->json('data.0')));
        $this->assertStringNotContainsString('@', $res->getContent());
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/collaborator-candidates?q=ل")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_platform_admin_can_manage_collaborators_of_any_form(): void
    {
        $admin = $this->makeUser('admin');
        $guest = $this->makeUser('community_manager');

        $this->actingAs($admin, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $guest->id, 'access_level' => 'viewer'])->assertCreated();
        $this->actingAs($admin, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/collaborators")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_two_requests_adding_the_same_person_leave_exactly_one_row(): void
    {
        $guest = $this->makeUser('community_manager');
        $this->share($guest)->assertCreated();
        $this->share($guest)->assertStatus(422);

        $this->assertSame(1, FormCollaborator::where('form_id', $this->form->id)->where('user_id', $guest->id)->count());
    }

    public function test_a_collaborator_on_a_deleted_form_has_nothing(): void
    {
        $guest = $this->makeUser('community_manager');
        $this->share($guest);
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/admin/forms/{$this->form->id}")->assertOk();

        $this->actingAs($guest, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}")->assertNotFound();
        $this->actingAs($guest, 'sanctum')->getJson('/api/operations/forms')->assertOk()->assertJsonCount(0, 'data.data');
    }
}
