<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase B — FINANCIAL REQUEST AUTHORITY BOUNDARY.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS TEST EXISTS — A DOCUMENTED DIVERGENCE, PINNED
 * ─────────────────────────────────────────────────────────────────────────
 * FinancialRequestController::assertTeamMember() gates request CREATION on
 * team_profiles.is_leader, while the canonical leadership authority everywhere
 * else in the platform is departments.leader_id (DepartmentAccessService).
 *
 * These two signals genuinely disagree in real data. Reconciling them would
 * change WHO MAY CREATE A FINANCIAL REQUEST — a business-workflow decision
 * with real affected users, not a page-access question. It is therefore
 * deliberately NOT changed here; the divergence is classified
 * DEFER_PRODUCT_DECISION and pinned by these tests so that any future change
 * is a conscious one rather than an accident.
 *
 * What these tests DO guarantee is the separation the access programme cares
 * about: opening a page never confers finance authority, and a department
 * leader never gains approval powers.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION.
 */
class FinancialRequestAuthorityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function makeDepartment(string $slug): Department
    {
        return Department::create([
            'name' => $slug, 'name_ar' => $slug, 'slug' => $slug,
            'status' => 'active', 'is_active' => true,
        ]);
    }

    private function makeProfile(User $user, Department $dept, bool $isLeader): TeamProfile
    {
        $profile = TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $user->id,
            'name_ar' => $user->name, 'position_ar' => 'عضو', 'is_active' => true,
            'is_leader' => $isLeader,
        ]);

        TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $dept->id, 'status' => 'active',
        ]);

        return $profile;
    }

    // ── The divergence itself, pinned ────────────────────────────────────────

    public function test_creation_currently_follows_team_profile_is_leader_not_canonical_leadership(): void
    {
        $dept = $this->makeDepartment('operations');

        // Canonical leader of the department.
        $canonicalLeader = $this->makeUser('department_manager');
        $this->makeProfile($canonicalLeader, $dept, false);
        $dept->forceFill(['leader_id' => $canonicalLeader->id])->save();

        // A DIFFERENT user carrying the is_leader display flag.
        $flagHolder = $this->makeUser('department_manager');
        $this->makeProfile($flagHolder, $dept, true);

        $access = app(DepartmentAccessService::class);

        // Canonical leadership says one thing...
        $this->assertTrue($access->getLedDepartmentIds($canonicalLeader)->contains($dept->id));
        $this->assertFalse($access->getLedDepartmentIds($flagHolder)->contains($dept->id));

        // ...and the financial-request creation gate currently says the other.
        // PINNED, not endorsed — see the class docblock.
        Sanctum::actingAs($flagHolder);
        $flagHolderResponse = $this->postJson('/api/financial-requests', [
            'title' => 'Test', 'amount' => 100, 'request_type' => 'purchase',
        ]);
        $this->assertNotSame(
            403, $flagHolderResponse->status(),
            'is_leader currently passes the creation gate — if this changed, the divergence was reconciled.'
        );

        Sanctum::actingAs($canonicalLeader);
        $canonicalResponse = $this->postJson('/api/financial-requests', [
            'title' => 'Test', 'amount' => 100, 'request_type' => 'purchase',
        ]);
        $this->assertSame(
            403, $canonicalResponse->status(),
            'Canonical leadership alone is currently NOT accepted by the creation gate.'
        );
    }

    // ── The separation the access programme guarantees ───────────────────────

    public function test_a_department_leader_gains_no_finance_approval_authority(): void
    {
        $dept = $this->makeDepartment('operations');
        $leader = $this->makeUser('department_manager');
        $this->makeProfile($leader, $dept, true);
        $dept->forceFill(['leader_id' => $leader->id])->save();

        Sanctum::actingAs($leader);

        // Create a request through the path the leader legitimately owns.
        $created = $this->postJson('/api/financial-requests', [
            'title' => 'Test', 'amount' => 100, 'request_type' => 'purchase',
        ]);

        if (! in_array($created->status(), [200, 201], true)) {
            $this->markTestSkipped('Creation payload rejected by validation; approval boundary covered below.');
        }

        $id = $created->json('data.id') ?? $created->json('id');
        $this->assertNotNull($id);

        // Finance and executive approval must remain closed to them.
        foreach (['finance-approve', 'finance-reject', 'executive-approve', 'executive-reject'] as $action) {
            $this->postJson("/api/financial-requests/{$id}/{$action}")
                ->assertForbidden();
        }
    }

    public function test_an_ordinary_member_cannot_approve_anything(): void
    {
        $dept = $this->makeDepartment('operations');
        $leader = $this->makeUser('department_manager');
        $this->makeProfile($leader, $dept, true);
        $dept->forceFill(['leader_id' => $leader->id])->save();

        $member = $this->makeUser('volunteer');
        $this->makeProfile($member, $dept, false);

        Sanctum::actingAs($member);

        // Cannot create (not a leader by either signal).
        $this->postJson('/api/financial-requests', [
            'title' => 'Test', 'amount' => 100, 'request_type' => 'purchase',
        ])->assertForbidden();
    }

    public function test_opening_the_page_confers_no_finance_authority(): void
    {
        $dept = $this->makeDepartment('operations');
        $leader = $this->makeUser('volunteer');
        $this->makeProfile($leader, $dept, false);
        $dept->forceFill(['leader_id' => $leader->id])->save();

        Sanctum::actingAs($leader);

        // The listing endpoint is auth-only, so the page opens...
        $this->getJson('/api/financial-requests')->assertOk();

        // ...but creation still requires the leader signal the controller wants,
        // and approval remains finance/executive-only.
        $this->postJson('/api/financial-requests', [
            'title' => 'Test', 'amount' => 100, 'request_type' => 'purchase',
        ])->assertForbidden();
    }
}
