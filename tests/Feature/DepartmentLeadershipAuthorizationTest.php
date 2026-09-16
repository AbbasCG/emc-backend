<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\PermissionDelegationService;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Services\EffectivePageAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase B — DEPARTMENT LEADERSHIP AUTHORIZATION.
 *
 * Proves the route-entry rule ("an administrative role OR the canonical leader
 * of an ACTIVE department") grants CONTEXTUAL entry only, and that no amount of
 * leadership converts into global admin authority or bypasses business
 * authorization.
 *
 * ROUTE/PAGE ENTRY != BUSINESS AUTHORIZATION.
 */
class DepartmentLeadershipAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const STRUCTURE = '/api/department/structure';
    private const EMPLOYEES = '/api/department/employees';

    private function makeUser(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function makeDepartment(string $slug, string $status = 'active'): Department
    {
        return Department::create([
            'name' => $slug, 'name_ar' => $slug, 'slug' => $slug,
            'status' => $status, 'is_active' => $status === 'active',
        ]);
    }

    private function makeMember(User $user, Department $dept): void
    {
        $profile = TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $user->id,
            'name_ar' => $user->name, 'position_ar' => 'عضو', 'is_active' => true,
        ]);

        TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $dept->id, 'status' => 'active',
        ]);
    }

    /** The grant endpoint validates permission_name against the table first. */
    private function makePermission(string $name): string
    {
        \App\Models\Permission::firstOrCreate(
            ['name' => $name],
            ['display_name' => $name, 'group' => 'users'],
        );

        return $name;
    }

    private function makeLeader(string $role, Department $dept): User
    {
        $user = $this->makeUser($role);
        $this->makeMember($user, $dept);
        $dept->forceFill(['leader_id' => $user->id])->save();

        return $user;
    }

    // ── The required matrix ──────────────────────────────────────────────────

    /** Every role in this list is a NON-manager slug the old route list excluded. */
    private const LEADER_ROLES = ['partner', 'volunteer', 'instructor', 'student', 'support_agent', 'department_manager'];

    private const NON_LEADER_ROLES = ['partner', 'volunteer', 'instructor', 'student'];

    public function test_a_canonical_leader_may_enter_the_leader_surfaces_whatever_their_role(): void
    {
        foreach (self::LEADER_ROLES as $i => $role) {
            $dept = $this->makeDepartment('dept-leader-'.$i);
            $leader = $this->makeLeader($role, $dept);

            Sanctum::actingAs($leader);

            $this->getJson(self::STRUCTURE)->assertOk();
            $this->getJson(self::EMPLOYEES)->assertOk();
        }
    }

    public function test_a_non_leader_in_those_roles_is_still_refused(): void
    {
        foreach (self::NON_LEADER_ROLES as $i => $role) {
            $dept = $this->makeDepartment('dept-nonleader-'.$i);
            $this->makeLeader('department_manager', $dept);

            $user = $this->makeUser($role);
            $this->makeMember($user, $dept); // a MEMBER, deliberately not the leader

            Sanctum::actingAs($user);

            $this->getJson(self::STRUCTURE)->assertForbidden();
            $this->getJson(self::EMPLOYEES)->assertForbidden();
        }
    }

    public function test_a_manager_role_that_leads_nothing_enters_the_route_but_is_refused_by_the_controller(): void
    {
        $dept = $this->makeDepartment('technology');
        $this->makeLeader('department_manager', $dept);

        // A manager slug is in the coarse role list, so route entry passes —
        // but it proves no leadership, so the controller refuses.
        $manager = $this->makeUser('operations_manager');
        $this->makeMember($manager, $dept);

        Sanctum::actingAs($manager);

        $this->getJson(self::STRUCTURE)->assertForbidden();
    }

    public function test_a_leader_of_department_a_cannot_reach_department_b(): void
    {
        $deptA = $this->makeDepartment('technology');
        $deptB = $this->makeDepartment('finance');

        $leaderA = $this->makeLeader('partner', $deptA);
        $this->makeLeader('department_manager', $deptB);

        Sanctum::actingAs($leaderA);

        // Own department: allowed.
        $this->getJson(self::STRUCTURE.'?department_id='.$deptA->id)->assertOk();

        // Someone else's: refused.
        $this->getJson(self::STRUCTURE.'?department_id='.$deptB->id)->assertForbidden();
    }

    public function test_an_archived_department_leader_gains_no_entry(): void
    {
        $archived = $this->makeDepartment('operations', 'archived');
        $leader = $this->makeLeader('volunteer', $archived);

        Sanctum::actingAs($leader);

        $this->getJson(self::STRUCTURE)->assertForbidden();
        $this->getJson(self::EMPLOYEES)->assertForbidden();
    }

    public function test_root_authority_is_unchanged(): void
    {
        $this->makeDepartment('technology');

        foreach (['super_admin', 'tech_admin'] as $role) {
            Sanctum::actingAs($this->makeUser($role));
            $this->getJson(self::EMPLOYEES)->assertOk();
        }
    }

    public function test_an_ordinary_department_member_gains_nothing(): void
    {
        $dept = $this->makeDepartment('technology');
        $this->makeLeader('department_manager', $dept);

        $member = $this->makeUser('volunteer');
        $this->makeMember($member, $dept);

        Sanctum::actingAs($member);

        $this->getJson(self::STRUCTURE)->assertForbidden();
        $this->getJson(self::EMPLOYEES)->assertForbidden();
    }

    // ── No global admin authority is conferred ───────────────────────────────

    public function test_a_non_manager_leader_gains_no_global_admin_surface(): void
    {
        $dept = $this->makeDepartment('technology');
        $leader = $this->makeLeader('volunteer', $dept);

        Sanctum::actingAs($leader);

        // Department leader surface: allowed.
        $this->getJson(self::EMPLOYEES)->assertOk();

        // Global admin surfaces: still refused.
        foreach (['/api/admin/roles', '/api/admin/users', '/api/admin/permissions'] as $adminUrl) {
            $response = $this->getJson($adminUrl);
            $this->assertContains(
                $response->status(), [403, 404, 405],
                "[{$adminUrl}] must not become reachable for a department leader (got {$response->status()})."
            );
        }
    }

    public function test_a_non_manager_leader_gains_no_protected_page_access(): void
    {
        $dept = $this->makeDepartment('technology');
        $leader = $this->makeLeader('partner', $dept);

        $resolved = app(EffectivePageAccessService::class)->resolveAll($leader);

        foreach (PageAccessCatalog::all() as $entry) {
            if ($entry->isProtected()) {
                $this->assertFalse(
                    $resolved[$entry->key]->allowed,
                    "SYSTEM_PROTECTED [{$entry->key}] leaked to a department leader."
                );
            }
        }
    }

    // ── Business authorization is not bypassed ───────────────────────────────

    public function test_route_entry_does_not_let_a_leader_delegate_a_permission_they_lack(): void
    {
        $dept = $this->makeDepartment('technology');
        $leader = $this->makeLeader('volunteer', $dept);

        $employee = $this->makeUser('student');
        $this->makeMember($employee, $dept);

        // Entry is granted...
        Sanctum::actingAs($leader);
        $this->getJson(self::EMPLOYEES)->assertOk();

        // ...but the delegation service still refuses a permission the actor
        // does not hold. Page/route entry proves nothing to it.
        $this->assertFalse(
            app(PermissionDelegationService::class)->canDelegate($leader, $employee, 'users.impersonate'),
            'A department leader must not be able to delegate a permission they do not hold.'
        );

        $this->postJson("/api/department/employees/{$employee->id}/permissions/grant", [
            'permission_name' => $this->makePermission('users.impersonate'),
        ])->assertForbidden();
    }

    public function test_self_delegation_remains_blocked_for_a_canonical_leader(): void
    {
        $dept = $this->makeDepartment('technology');
        $leader = $this->makeLeader('department_manager', $dept);

        $this->assertFalse(
            app(PermissionDelegationService::class)->canDelegate($leader, $leader, 'users.impersonate'),
            'Self-delegation must stay blocked regardless of leadership.'
        );
    }

    public function test_cross_department_delegation_remains_blocked(): void
    {
        $deptA = $this->makeDepartment('technology');
        $deptB = $this->makeDepartment('finance');

        $leaderA = $this->makeLeader('department_manager', $deptA);

        $outsider = $this->makeUser('student');
        $this->makeMember($outsider, $deptB);

        Sanctum::actingAs($leaderA);

        $this->postJson("/api/department/employees/{$outsider->id}/permissions/grant", [
            'permission_name' => $this->makePermission('users.impersonate'),
        ])->assertForbidden();
    }

    public function test_leadership_is_never_inferred_from_team_profile_is_leader(): void
    {
        $dept = $this->makeDepartment('technology');
        $this->makeLeader('department_manager', $dept);

        // A profile flagged is_leader=true, but departments.leader_id points
        // elsewhere. The display badge must confer nothing.
        $pretender = $this->makeUser('partner');
        $profile = TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $pretender->id,
            'name_ar' => $pretender->name, 'position_ar' => 'قائد', 'is_active' => true,
            'is_leader' => true,
        ]);
        TeamMember::create([
            'user_id' => $pretender->id, 'team_profile_id' => $profile->id,
            'department_id' => $dept->id, 'status' => 'active',
        ]);

        Sanctum::actingAs($pretender);

        $this->getJson(self::STRUCTURE)->assertForbidden();
        $this->getJson(self::EMPLOYEES)->assertForbidden();
    }
}
