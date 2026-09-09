<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentSection;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE 0.2 — Legacy department-sections endpoint hardening.
 *
 * Admin\DepartmentSectionController (mounted at /api/admin/department-sections)
 * had zero canonical department-scoping — the route-level role list
 * (admin,super_admin,tech_admin,operations_manager) was the ONLY gate, and
 * `operations_manager` alone (regardless of actual departments.leader_id)
 * could create/update/delete ANY department's sections and set leader_id to
 * a user with no TeamProfile at all. Confirmed exploitable via live HTTP
 * test before this fix (create=201, update=200, delete=200, invalid
 * leader_id assignment=200 — see Phase 0.2 report for the full record).
 *
 * Reachability audit found zero consumers (no frontend API calls, no
 * backend tests, no Blade views, no docs) referencing this endpoint or
 * DepartmentSectionController — so it was removed outright (Strategy A)
 * rather than hardened: DepartmentStructureController already covers every
 * operation it offered (and more), with the correct authorization model.
 *
 * This suite proves: the legacy route is gone, no alternate route name
 * resolves to it, and the canonical /api/department/sections* surface
 * (DepartmentStructureController, untouched by this fix) still works.
 */
class LegacyDepartmentSectionRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept', 'name_ar' => 'إدارة', 'slug' => 'legacy-removal-test-'.uniqid(),
            'status' => 'active', 'is_active' => true,
        ], $overrides));
    }

    // ── The legacy endpoint no longer exists ────────────────────────────────

    public function test_legacy_department_sections_index_route_is_gone(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/admin/department-sections');

        $response->assertStatus(404);
    }

    public function test_legacy_department_sections_store_route_is_gone(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $dept = $this->makeDepartment();

        $response = $this->actingAs($admin)->postJson('/api/admin/department-sections', [
            'department_id' => $dept->id, 'name' => 'قسم',
        ]);

        $response->assertStatus(404);
        $this->assertSame(0, DepartmentSection::count(), 'no section must be created through the removed route');
    }

    public function test_legacy_department_sections_update_and_destroy_routes_are_gone(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $dept = $this->makeDepartment();
        $section = DepartmentSection::create(['department_id' => $dept->id, 'name' => 'قسم', 'status' => 'active', 'sort_order' => 0]);

        $this->actingAs($admin)->putJson('/api/admin/department-sections/'.$section->id, ['name' => 'جديد'])->assertStatus(404);
        $this->actingAs($admin)->deleteJson('/api/admin/department-sections/'.$section->id)->assertStatus(404);
        $this->assertSame('قسم', $section->fresh()->name);
        $this->assertNotNull(DepartmentSection::find($section->id));
    }

    public function test_no_alternate_route_bypass_exists_for_the_removed_resource(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('department-sections.index'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('department-sections.store'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('department-sections.update'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('department-sections.destroy'));
    }

    // ── The now-sole-canonical path (DepartmentStructureController) still works, untouched ──

    public function test_canonical_department_structure_route_still_works_for_a_leader(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);

        $response = $this->actingAs($leader)->getJson('/api/department/structure');

        $response->assertOk();
        $this->assertSame($dept->id, $response->json('department.id'));
    }

    public function test_canonical_create_section_still_enforces_department_scoping(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->makeDepartment(['leader_id' => $leader->id]);
        $departmentB = $this->makeDepartment();

        $response = $this->actingAs($leader)->postJson('/api/department/sections', [
            'department_id' => $departmentB->id, 'name' => 'قسم مُقتحم',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, DepartmentSection::count());
    }

    public function test_canonical_reject_leader_assignment_without_team_profile(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $noProfileUser = User::factory()->create();

        $response = $this->actingAs($leader)->postJson('/api/department/sections', [
            'department_id' => $dept->id, 'name' => 'قسم', 'leader_id' => $noProfileUser->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DepartmentSection::count(), 'section must not be created when leader has no TeamProfile');
    }

    public function test_canonical_reject_cross_department_team_profile_assignment(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $otherDept = $this->makeDepartment();
        $memberUser = User::factory()->create();
        // TeamProfile belongs to a DIFFERENT department than the one being assigned to.
        TeamProfile::create([
            'department_id' => $otherDept->id, 'user_id' => $memberUser->id,
            'name_ar' => $memberUser->name, 'position_ar' => 'عضو', 'is_active' => true,
        ]);

        $response = $this->actingAs($leader)->postJson('/api/department/members/assign-section', [
            'user_id' => $memberUser->id, 'department_id' => $dept->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, TeamMember::where('user_id', $memberUser->id)->count());
    }

    public function test_canonical_super_admin_behavior_is_unaffected_by_this_fix(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $dept = $this->makeDepartment();

        $response = $this->actingAs($admin)->postJson('/api/department/sections', [
            'department_id' => $dept->id, 'name' => 'قسم',
        ]);

        $response->assertCreated();
    }

    public function test_canonical_tech_admin_behavior_is_unaffected_by_this_fix(): void
    {
        $admin = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        $dept = $this->makeDepartment();

        $response = $this->actingAs($admin)->postJson('/api/department/sections', [
            'department_id' => $dept->id, 'name' => 'قسم',
        ]);

        $response->assertCreated();
    }
}
