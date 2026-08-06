<?php

namespace Tests\Feature\Departments;

use App\Enums\RegistrationStatus;
use App\Models\Course;
use App\Models\Department;
use App\Models\Task;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerHrProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function department(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name_ar' => 'التقنية', 'name' => 'Technology', 'slug' => 'tech-' . uniqid(), 'is_active' => true,
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function deptManager(?int $leaderOfDeptId = null): User
    {
        $user = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        if ($leaderOfDeptId) {
            Department::whereKey($leaderOfDeptId)->update(['leader_id' => $user->id]);
        }
        return $user;
    }

    public function test_overview_returns_department_details(): void
    {
        $dept = $this->department(['description_ar' => 'قسم تجريبي']);
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $response->assertStatus(200);
        $this->assertSame($dept->id, $response->json('data.department.id'));
        $this->assertSame('التقنية', $response->json('data.department.name_ar'));
    }

    public function test_kpi_counts_are_correct(): void
    {
        $dept = $this->department();
        TeamProfile::create(['department_id' => $dept->id, 'name_ar' => 'A', 'position_ar' => 'X', 'is_active' => true]);
        TeamProfile::create(['department_id' => $dept->id, 'name_ar' => 'B', 'position_ar' => 'X', 'is_active' => false]);
        $otherDept = $this->department(['name_ar' => 'أخرى', 'slug' => 'other-' . uniqid()]);
        TeamProfile::create(['department_id' => $otherDept->id, 'name_ar' => 'C', 'position_ar' => 'X', 'is_active' => true]);

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.kpis.total_members'));
        $this->assertSame(1, $response->json('data.kpis.active_members'));
    }

    public function test_leadership_data_is_correct(): void
    {
        $manager = User::factory()->create(['role' => 'department_manager', 'name' => 'Manager One', 'is_active' => true]);
        $dept = $this->department(['leader_id' => $manager->id]);

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $response->assertStatus(200);
        $this->assertSame('Manager One', $response->json('data.leadership.manager.name'));
    }

    public function test_leadership_manager_is_null_when_not_assigned(): void
    {
        $dept = $this->department();
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.leadership.manager'));
        $this->assertContains('لا يوجد مدير للقسم', $response->json('data.completeness.missing'));
    }

    public function test_linked_members_are_scoped_to_department(): void
    {
        $dept = $this->department();
        $other = $this->department(['name_ar' => 'أخرى2', 'slug' => 'other2-' . uniqid()]);
        TeamProfile::create(['department_id' => $dept->id, 'name_ar' => 'Mine', 'position_ar' => 'X', 'is_active' => true]);
        TeamProfile::create(['department_id' => $other->id, 'name_ar' => 'NotMine', 'position_ar' => 'X', 'is_active' => true]);

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $names = collect($response->json('data.members'))->pluck('name');
        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('NotMine'));
    }

    public function test_approved_volunteers_are_scoped_to_department(): void
    {
        $dept = $this->department();
        VolunteerHrProfile::create([
            'full_name' => 'Approved Person', 'email' => 'a@example.com', 'normalized_email' => 'a@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'approved', 'approved_at' => now(),
        ]);
        $other = $this->department(['name_ar' => 'أخرى3', 'slug' => 'other3-' . uniqid()]);
        VolunteerHrProfile::create([
            'full_name' => 'Other Dept', 'email' => 'b@example.com', 'normalized_email' => 'b@example.com',
            'phone' => '050', 'department_id' => $other->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'approved', 'approved_at' => now(),
        ]);

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $names = collect($response->json('data.approved_volunteers'))->pluck('full_name');
        $this->assertTrue($names->contains('Approved Person'));
        $this->assertFalse($names->contains('Other Dept'));
    }

    public function test_volunteer_applications_are_scoped_and_counted(): void
    {
        $dept = $this->department();
        VolunteerHrProfile::create([
            'full_name' => 'Pending', 'email' => 'p@example.com', 'normalized_email' => 'p@example.com',
            'phone' => '050', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted', 'submitted_at' => now(),
        ]);

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $this->assertSame(1, $response->json('data.volunteer_applications.stats.total'));
        $this->assertSame(1, $response->json('data.volunteer_applications.stats.submitted'));
        $this->assertSame(1, $response->json('data.kpis.pending_applications'));
    }

    public function test_courses_are_scoped_correctly(): void
    {
        $dept = $this->department();
        $course = Course::factory()->create(['department_id' => $dept->id]);
        $otherDept = $this->department(['name_ar' => 'أخرى4', 'slug' => 'other4-' . uniqid()]);
        Course::factory()->create(['department_id' => $otherDept->id]);

        $admin = $this->admin();
        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $this->assertSame(1, $response->json('data.kpis.courses_linked'));
        $this->assertSame($course->id, $response->json('data.courses.0.id'));
    }

    public function test_open_tasks_are_scoped_correctly(): void
    {
        $admin = $this->admin();
        $dept = $this->department();
        Task::create(['title' => 'Open task', 'department_id' => $dept->id, 'status' => 'in_progress', 'created_by' => $admin->id]);
        Task::create(['title' => 'Done task', 'department_id' => $dept->id, 'status' => 'completed', 'created_by' => $admin->id]);
        $other = $this->department(['name_ar' => 'أخرى5', 'slug' => 'other5-' . uniqid()]);
        Task::create(['title' => 'Other dept task', 'department_id' => $other->id, 'status' => 'in_progress', 'created_by' => $admin->id]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $this->assertSame(1, $response->json('data.kpis.open_tasks'));
        $titles = collect($response->json('data.tasks'))->pluck('title');
        $this->assertTrue($titles->contains('Open task'));
        $this->assertFalse($titles->contains('Done task'));
        $this->assertFalse($titles->contains('Other dept task'));
    }

    public function test_completeness_percentage_follows_defined_rules(): void
    {
        $manager = $this->admin();
        $dept = $this->department(['leader_id' => $manager->id, 'description_ar' => 'وصف', 'icon' => 'star']);
        TeamProfile::create(['department_id' => $dept->id, 'name_ar' => 'A', 'position_ar' => 'X', 'is_active' => true]);
        Course::factory()->create(['department_id' => $dept->id]);

        $response = $this->actingAs($manager, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $response->assertStatus(200);
        $this->assertSame(100, $response->json('data.completeness.percentage'));
        $this->assertEmpty($response->json('data.completeness.missing'));
    }

    public function test_unauthorized_role_receives_403(): void
    {
        $dept = $this->department();
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $response = $this->actingAs($student, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $response->assertStatus(403);
    }

    public function test_department_manager_cannot_access_another_departments_overview(): void
    {
        $ownDept = $this->department(['name_ar' => 'قسمي', 'slug' => 'mine-' . uniqid()]);
        $otherDept = $this->department(['name_ar' => 'قسم آخر', 'slug' => 'other-' . uniqid()]);
        $manager = $this->deptManager($ownDept->id);

        $ownResponse = $this->actingAs($manager, 'sanctum')->getJson("/api/operations/departments/{$ownDept->id}/overview");
        $otherResponse = $this->actingAs($manager, 'sanctum')->getJson("/api/operations/departments/{$otherDept->id}/overview");

        $ownResponse->assertStatus(200);
        $otherResponse->assertStatus(403);
    }

    public function test_empty_department_returns_valid_empty_arrays_not_errors(): void
    {
        $dept = $this->department();
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.members'));
        $this->assertSame([], $response->json('data.approved_volunteers'));
        $this->assertSame([], $response->json('data.courses'));
        $this->assertSame([], $response->json('data.tasks'));
        $this->assertSame(0, $response->json('data.kpis.total_members'));
    }

    public function test_overview_does_not_trigger_excessive_queries(): void
    {
        $admin = $this->admin();
        $dept = $this->department();
        TeamProfile::create(['department_id' => $dept->id, 'name_ar' => 'A', 'position_ar' => 'X', 'is_active' => true]);
        Course::factory()->count(3)->create(['department_id' => $dept->id]);
        Task::create(['title' => 'T1', 'department_id' => $dept->id, 'status' => 'in_progress', 'created_by' => $admin->id]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($admin, 'sanctum')->getJson("/api/operations/departments/{$dept->id}/overview")->assertStatus(200);
        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Generous fixed bound — this endpoint issues one query per section
        // (counts + limited lists), not one per row.
        $this->assertLessThan(40, $queryCount);
    }
}
