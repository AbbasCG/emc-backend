<?php

namespace Tests\Feature\Forms;

use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Which roles can reach the forms API at all, and what the role / permission model says they should be able to.
 *
 * This pins today's behaviour so that any change to who may reach the forms screens is deliberate: widening or
 * narrowing the role lists on the routes makes this test fail until it is updated together with the decision.
 */
class FormRoleAccessMatrixTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    /** Roles the forms routes (/api/operations/forms*, /api/admin/forms*) let through (FormAccessService::ROLES). */
    private const REACH = ['super_admin', 'tech_admin', 'admin', 'operations_manager', 'community_manager'];

    private const OTHER_ROLES = [
        'executive_admin', 'department_manager', 'section_lead', 'programs_manager', 'partnerships_manager',
        'finance_manager', 'marketing_manager', 'quality_manager', 'hr_manager', 'support_agent', 'instructor', 'student', 'volunteer', 'partner',
    ];

    public function test_only_the_roles_with_a_forms_route_reach_the_forms_api(): void
    {
        foreach (self::REACH as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')->getJson('/api/operations/forms')->assertOk();
        }
        foreach (self::OTHER_ROLES as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')->getJson('/api/operations/forms')->assertForbidden();
            $this->actingAs($this->makeUser($role), 'sanctum')->postJson('/api/operations/forms', ['title' => 'x'])->assertForbidden();
        }
    }

    /**
     * Phase 1 resolves the Forms-specific mismatch: community_manager holds view_forms + manage_forms (and the canonical
     * page defaults and the dashboard grant the Forms page), so it now reaches the forms routes. It gets no more than its
     * permissions and the form-level rules give (see CommunityManagerFormsAccessTest). Tasks and meetings are a separate
     * decision and stay as they were.
     */
    public function test_community_manager_reaches_forms_because_it_holds_the_forms_permissions_and_nothing_else_changed(): void
    {
        $permissions = $this->makeUser('community_manager')->basePermissions();

        $this->assertContains(Permissions::VIEW_FORMS, $permissions);
        $this->assertContains(Permissions::MANAGE_FORMS, $permissions);

        $cm = $this->makeUser('community_manager');
        $this->actingAs($cm, 'sanctum')->getJson('/api/operations/forms')->assertOk();
        $this->actingAs($cm, 'sanctum')->getJson('/api/operations/tasks')->assertForbidden();
        $this->actingAs($cm, 'sanctum')->getJson('/api/operations/meetings')->assertForbidden();
    }

    /** The other roles that hold view_forms in the model but are not on the routes. Reaching the API needs a decision first. */
    public function test_other_view_forms_holders_are_equally_unreachable_today(): void
    {
        foreach (['executive_admin', 'department_manager', 'volunteer'] as $role) {
            $this->assertContains(Permissions::VIEW_FORMS, $this->makeUser($role)->basePermissions(), $role);
            $this->actingAs($this->makeUser($role), 'sanctum')->getJson('/api/operations/forms')->assertForbidden();
        }
    }

    // ── Forms with no department ─────────────────────────────────────────────

    /**
     * Documented behaviour, decision pending: a form with no department is organisation-wide. Every role that can reach
     * the forms screens (admin, operations_manager and the two platform roles) can open its responses, whoever created
     * it. Before the department rules existed they could open every form's responses, so nothing was widened; but a form
     * whose responses are sensitive must be put in a department until explicit sharing exists.
     */
    public function test_responses_of_a_form_with_no_department_are_readable_by_every_role_that_reaches_the_forms_screens(): void
    {
        $dept = $this->makeDepartment('قسم');
        $creator = $this->makeUser('operations_manager', $dept);
        $other = $this->makeUser('operations_manager', $this->makeDepartment('قسم آخر'));
        $form = $this->makeForm(['department_id' => null], [['label' => 'سري', 'type' => 'text']], $creator);
        $this->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$form->questions->first()->id => 'حساس']])->assertCreated();

        $this->actingAs($other, 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertOk()->assertJsonPath('data.meta.total', 1);
        $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertOk();
    }

    public function test_the_same_form_inside_a_department_is_closed_to_other_departments(): void
    {
        $deptA = $this->makeDepartment('أ');
        $form = $this->makeForm(['department_id' => $deptA->id], [['label' => 'سري', 'type' => 'text']], $this->makeUser('operations_manager', $deptA));
        $outsider = $this->makeUser('operations_manager', $this->makeDepartment('ب'));

        $this->actingAs($outsider, 'sanctum')->getJson("/api/operations/forms/{$form->id}/submissions")->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/operations/forms/{$form->id}")->assertForbidden();
    }
}
