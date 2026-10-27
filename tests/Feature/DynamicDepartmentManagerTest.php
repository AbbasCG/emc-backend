<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicDepartmentManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_department_manager_dynamically_inherits_finance_manager_role_and_permissions(): void
    {
        $dept = Department::create([
            'name' => 'Finance',
            'name_ar' => 'الإدارة المالية',
            'slug' => 'finance',
            'status' => 'active',
            'is_active' => true,
        ]);

        $manager = User::factory()->create([
            'role' => 'department_manager',
            'department' => 'finance',
            'is_active' => true,
        ]);
        $dept->update(['leader_id' => $manager->id]);
        $manager->departments()->sync([$dept->id => ['is_primary' => true]]);

        $this->assertSame('finance_manager', $manager->getDepartmentSpecializedRole());
        $this->assertSame('finance_manager', $manager->effectiveRole());
        $this->assertTrue($manager->hasRole('finance_manager'));
        $this->assertTrue($manager->hasRole('department_manager'));

        // Permissions should include view_payments (from finance_manager) and manage_meetings (from department_manager)
        $this->assertTrue($manager->hasPermission(\App\Support\Permissions::VIEW_PAYMENTS));
        $this->assertTrue($manager->hasPermission(\App\Support\Permissions::MANAGE_MEETINGS));

        // Route protected by role:finance_manager should allow this user
        $response = $this->actingAs($manager)->getJson('/api/finance/payments');
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_department_manager_dynamically_inherits_hr_manager_role(): void
    {
        $dept = Department::create([
            'name' => 'Human Resources',
            'name_ar' => 'إدارة الموارد البشرية والشؤون القانونية',
            'slug' => 'human-resources',
            'status' => 'active',
            'is_active' => true,
        ]);

        $manager = User::factory()->create([
            'role' => 'department_manager',
            'department' => 'human-resources',
            'is_active' => true,
        ]);
        $dept->update(['leader_id' => $manager->id]);

        $this->assertSame('hr_manager', $manager->getDepartmentSpecializedRole());
        $this->assertSame('hr_manager', $manager->effectiveRole());
        $this->assertTrue($manager->hasRole('hr_manager'));
        $this->assertTrue($manager->hasPermission(\App\Support\Permissions::VIEW_VOLUNTEERS));
    }

    public function test_volunteer_approval_preserves_specialized_roles(): void
    {
        $dept = Department::create([
            'name' => 'Technology',
            'name_ar' => 'إدارة التقنية وتطوير المنصة',
            'slug' => 'technology',
            'status' => 'active',
            'is_active' => true,
        ]);

        $instructor = User::factory()->create([
            'role' => 'instructor',
            'email' => 'teacher@emc.test',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $profile = \App\Models\VolunteerHrProfile::create([
            'full_name' => 'Instructor Volunteer',
            'email' => 'teacher@emc.test',
            'normalized_email' => 'teacher@emc.test',
            'phone' => '123456789',
            'job_title' => 'متطوع تدريس',
            'join_date' => now()->toDateString(),
            'cv_path' => 'cvs/sample.pdf',
            'user_id' => $instructor->id,
            'status' => 'interview_scheduled',
        ]);

        $service = app(\App\Services\VolunteerApprovalService::class);
        $service->approve($profile, $admin, $dept->id);

        $instructor->refresh();
        // Role should remain instructor
        $this->assertSame('instructor', $instructor->role);
        // But linked to department
        $this->assertTrue($instructor->departments->contains($dept->id));
        // And has team membership with section_id = null
        $teamMember = \App\Models\TeamMember::where('user_id', $instructor->id)->first();
        $this->assertNotNull($teamMember);
        $this->assertEquals($dept->id, $teamMember->department_id);
        $this->assertNull($teamMember->section_id);
    }

    public function test_department_member_transfer_updates_department_and_resets_section(): void
    {
        $dept1 = Department::create([
            'name' => 'Marketing',
            'name_ar' => 'إدارة التسويق',
            'slug' => 'marketing',
            'status' => 'active',
            'is_active' => true,
        ]);

        $dept2 = Department::create([
            'name' => 'Quality',
            'name_ar' => 'إدارة الجودة',
            'slug' => 'quality',
            'status' => 'active',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $member = User::factory()->create([
            'role' => 'department_member',
            'department' => 'marketing',
            'is_active' => true,
        ]);

        // Transfer to Quality department via AdminUserController
        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$member->id}", [
            'department_id' => $dept2->id,
        ]);

        $response->assertStatus(200);

        $member->refresh();
        $this->assertTrue($member->departments->contains($dept2->id));
        $this->assertFalse($member->departments->contains($dept1->id));

        $teamMember = \App\Models\TeamMember::where('user_id', $member->id)->first();
        $this->assertNotNull($teamMember);
        $this->assertEquals($dept2->id, $teamMember->department_id);
        $this->assertNull($teamMember->section_id);
    }
}
