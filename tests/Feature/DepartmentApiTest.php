<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\Task;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function makeDept(array $attrs = []): Department
    {
        return Department::create(array_merge([
            'name'       => 'Test Department',
            'name_ar'    => 'قسم اختبار',
            'name_en'    => 'Test Department',
            'slug'       => 'test-dept-' . uniqid(),
            'sort_order' => 1,
            'is_active'  => true,
        ], $attrs));
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_admin_departments(): void
    {
        $this->getJson('/api/admin/departments')->assertStatus(401);
    }

    public function test_student_cannot_access_admin_departments(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertStatus(403);
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    public function test_admin_can_list_departments(): void
    {
        $admin = $this->makeAdmin();
        $this->makeDept();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => [['id', 'name_ar', 'slug', 'members_count', 'leaders_count', 'courses_count', 'open_tasks_count', 'volunteer_requests_count', 'pending_items_count']]]);
    }

    // ─── members_count ───────────────────────────────────────────────────────

    public function test_members_count_reflects_team_profiles(): void
    {
        $admin = $this->makeAdmin();
        $dept  = $this->makeDept();

        TeamProfile::create(['department_id' => $dept->id, 'name_ar' => 'عضو 1', 'position_ar' => 'مطور', 'sort_order' => 1, 'is_leader' => false, 'is_executive' => false]);
        TeamProfile::create(['department_id' => $dept->id, 'name_ar' => 'عضو 2', 'position_ar' => 'مصمم', 'sort_order' => 2, 'is_leader' => false, 'is_executive' => false]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $dept->id);
        $this->assertSame(2, $row['members_count']);
        $this->assertSame(0, $row['leaders_count']);
    }

    public function test_leaders_count_reflects_team_profiles_with_is_leader(): void
    {
        $admin = $this->makeAdmin();
        $dept  = $this->makeDept();

        TeamProfile::create(['department_id' => $dept->id, 'name_ar' => 'قائد',  'position_ar' => 'مدير', 'sort_order' => 1, 'is_leader' => true,  'is_executive' => false]);
        TeamProfile::create(['department_id' => $dept->id, 'name_ar' => 'عضو',   'position_ar' => 'مطور', 'sort_order' => 2, 'is_leader' => false, 'is_executive' => false]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $dept->id);
        $this->assertSame(2, $row['members_count']);
        $this->assertSame(1, $row['leaders_count']);
    }

    // ─── volunteer_requests_count ────────────────────────────────────────────

    public function test_volunteer_requests_count_matches_by_department_name_ar(): void
    {
        $admin = $this->makeAdmin();
        $dept  = $this->makeDept(['name_ar' => 'إدارة التقنية']);

        VolunteerRequest::create([
            'full_name' => 'تجربة 1', 'email' => 'v1@test.com', 'phone' => '123',
            'country' => 'SA', 'city' => 'Riyadh', 'gender' => 'male',
            'desired_department' => 'إدارة التقنية', 'experience_level' => 'junior',
            'availability' => 'full_time', 'motivation' => str_repeat('a', 25),
            'status' => 'pending',
        ]);
        VolunteerRequest::create([
            'full_name' => 'تجربة 2', 'email' => 'v2@test.com', 'phone' => '456',
            'country' => 'SA', 'city' => 'Jeddah', 'gender' => 'female',
            'desired_department' => 'قسم آخر', 'experience_level' => 'senior',
            'availability' => 'part_time', 'motivation' => str_repeat('b', 25),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $dept->id);
        $this->assertSame(1, $row['volunteer_requests_count']);
    }

    public function test_soft_deleted_volunteer_requests_are_excluded(): void
    {
        $admin = $this->makeAdmin();
        $dept  = $this->makeDept(['name_ar' => 'إدارة التسويق']);

        $vr = VolunteerRequest::create([
            'full_name' => 'محذوف', 'email' => 'del@test.com', 'phone' => '789',
            'country' => 'SA', 'city' => 'Riyadh', 'gender' => 'male',
            'desired_department' => 'إدارة التسويق', 'experience_level' => 'junior',
            'availability' => 'weekends', 'motivation' => str_repeat('c', 25),
            'status' => 'pending',
        ]);
        $vr->delete();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $dept->id);
        $this->assertSame(0, $row['volunteer_requests_count']);
    }

    // ─── open_tasks_count ────────────────────────────────────────────────────

    public function test_open_tasks_count_excludes_completed_and_cancelled(): void
    {
        $admin = $this->makeAdmin();
        $dept  = $this->makeDept();

        Task::create(['title' => 'مهمة مفتوحة 1', 'department_id' => $dept->id, 'status' => 'in_progress', 'created_by' => $admin->id]);
        Task::create(['title' => 'مهمة مفتوحة 2', 'department_id' => $dept->id, 'status' => 'planning',    'created_by' => $admin->id]);
        Task::create(['title' => 'مهمة منتهية',   'department_id' => $dept->id, 'status' => 'completed',   'created_by' => $admin->id]);
        Task::create(['title' => 'مهمة ملغاة',    'department_id' => $dept->id, 'status' => 'cancelled',   'created_by' => $admin->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $dept->id);
        $this->assertSame(2, $row['open_tasks_count']);
    }

    // ─── pending_items_count ─────────────────────────────────────────────────

    public function test_pending_items_count_combines_pending_volunteer_requests_and_open_tasks(): void
    {
        $admin = $this->makeAdmin();
        $dept  = $this->makeDept(['name_ar' => 'إدارة الموارد البشرية']);

        // 2 pending volunteer requests
        foreach (['p1@test.com', 'p2@test.com'] as $email) {
            VolunteerRequest::create([
                'full_name' => 'مرشح', 'email' => $email, 'phone' => '000',
                'country' => 'SA', 'city' => 'Riyadh', 'gender' => 'male',
                'desired_department' => 'إدارة الموارد البشرية',
                'experience_level' => 'junior', 'availability' => 'full_time',
                'motivation' => str_repeat('x', 25), 'status' => 'pending',
            ]);
        }

        // 1 non-pending volunteer request (should not count)
        VolunteerRequest::create([
            'full_name' => 'مقبول', 'email' => 'accepted@test.com', 'phone' => '001',
            'country' => 'SA', 'city' => 'Riyadh', 'gender' => 'female',
            'desired_department' => 'إدارة الموارد البشرية',
            'experience_level' => 'senior', 'availability' => 'part_time',
            'motivation' => str_repeat('y', 25), 'status' => 'accepted',
        ]);

        // 1 open task
        Task::create(['title' => 'مهمة مراجعة', 'department_id' => $dept->id, 'status' => 'under_review', 'created_by' => $admin->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $dept->id);
        $this->assertSame(3, $row['volunteer_requests_count']);   // total: 2 pending + 1 accepted
        $this->assertSame(3, $row['pending_items_count']);        // 2 pending vr + 1 open task
    }

    // ─── courses_count ───────────────────────────────────────────────────────

    public function test_courses_count_reflects_linked_courses(): void
    {
        $admin = $this->makeAdmin();
        $dept  = $this->makeDept();

        Course::factory()->count(3)->create(['department_id' => $dept->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $dept->id);
        $this->assertSame(3, $row['courses_count']);
    }

    // ─── Response shape ───────────────────────────────────────────────────────

    public function test_response_includes_bilingual_name_fields(): void
    {
        $admin = $this->makeAdmin();
        $this->makeDept(['name_ar' => 'القسم العربي', 'name_en' => 'Arabic Department']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/departments')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('name_ar', 'القسم العربي');
        $this->assertSame('القسم العربي', $row['name_ar']);
        $this->assertSame('Arabic Department', $row['name_en']);
    }
}
