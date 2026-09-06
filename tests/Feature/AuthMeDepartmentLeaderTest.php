<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `AuthController::me()`'s `is_department_leader` now derives from
 * `DepartmentAccessService::getLedDepartmentIds()` (departments.leader_id),
 * not `TeamProfile.is_leader` directly — see the audit that found the two
 * could drift (TeamProfile.is_leader is a synced display badge, never the
 * authorization source DepartmentAccessService itself reads).
 */
class AuthMeDepartmentLeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_department_leader_true_when_departments_leader_id_matches(): void
    {
        $user = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);
        $dept = Department::create([
            'name' => 'Dept', 'name_ar' => 'إدارة', 'slug' => 'dept-authme-test',
            'status' => 'active', 'is_active' => true, 'leader_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertTrue($response->json('data.is_department_leader'));
    }

    public function test_is_department_leader_false_when_is_leader_badge_true_but_no_leader_id(): void
    {
        // The exact drift scenario this fix closes: TeamProfile.is_leader=true
        // (the badge) with NO matching departments.leader_id — must now read
        // false, since DepartmentAccessService is the sole authority.
        $user = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);
        $dept = Department::create([
            'name' => 'Dept', 'name_ar' => 'إدارة أخرى', 'slug' => 'dept-authme-test-2',
            'status' => 'active', 'is_active' => true,
        ]);
        TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $user->id, 'name_ar' => 'Test',
            'position_ar' => 'Manager', 'is_leader' => true, 'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertFalse($response->json('data.is_department_leader'));
    }

    public function test_is_department_leader_false_for_non_leader(): void
    {
        $user = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertFalse($response->json('data.is_department_leader'));
    }
}
