<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meeting Lounge department scoping (Admin\MeetingController) — must use the
 * canonical DepartmentAccessService, never the non-existent
 * user.department_id/user.department fields.
 */
class MeetingLoungeScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept ' . Str::random(6),
            'name_ar' => 'إدارة تجريبية',
            'slug' => 'dept-' . Str::random(8),
            'status' => 'active',
            'is_active' => true,
        ], $overrides));
    }

    private function makeMeeting(Department $dept, array $overrides = []): Meeting
    {
        return Meeting::create(array_merge([
            'title' => 'اجتماع تجريبي',
            'type' => 'department',
            'status' => 'scheduled',
            'meeting_date' => now(),
            'start_time' => now(),
            'department_id' => $dept->id,
        ], $overrides));
    }

    public function test_member_sees_own_department_meeting(): void
    {
        $dept = $this->makeDepartment();
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $user->departments()->attach($dept->id, ['is_primary' => true]);
        $meeting = $this->makeMeeting($dept, ['organizer_id' => User::factory()->create()->id]);

        $response = $this->actingAs($user)->getJson('/api/department/meetings');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($meeting->id));
    }

    public function test_member_does_not_see_other_department_meeting(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $user->departments()->attach($dept->id, ['is_primary' => true]);
        $meeting = $this->makeMeeting($otherDept, ['organizer_id' => User::factory()->create()->id]);

        $response = $this->actingAs($user)->getJson('/api/department/meetings');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertFalse($ids->contains($meeting->id));
    }

    public function test_user_with_no_department_only_sees_own_organized_meetings(): void
    {
        $dept = $this->makeDepartment();
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $ownMeeting = $this->makeMeeting($dept, ['organizer_id' => $user->id]);
        $otherMeeting = $this->makeMeeting($dept, ['organizer_id' => User::factory()->create()->id]);

        $response = $this->actingAs($user)->getJson('/api/department/meetings');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($ownMeeting->id));
        $this->assertFalse($ids->contains($otherMeeting->id));
    }

    public function test_privileged_admin_sees_all_departments_meetings(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $m1 = $this->makeMeeting($deptA, ['organizer_id' => User::factory()->create()->id]);
        $m2 = $this->makeMeeting($deptB, ['organizer_id' => User::factory()->create()->id]);

        $response = $this->actingAs($admin)->getJson('/api/department/meetings');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($m1->id) && $ids->contains($m2->id));
    }

    public function test_department_leader_sees_own_department_meeting_without_pivot_row(): void
    {
        $dept = $this->makeDepartment();
        $leader = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $leader->id]);
        $meeting = $this->makeMeeting($dept, ['organizer_id' => User::factory()->create()->id]);

        $response = $this->actingAs($leader)->getJson("/api/department/meetings/{$meeting->id}");

        $response->assertOk();
    }

    public function test_unrelated_user_gets_403_on_meeting_detail(): void
    {
        $dept = $this->makeDepartment();
        $stranger = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $meeting = $this->makeMeeting($dept, ['organizer_id' => User::factory()->create()->id]);

        $this->actingAs($stranger)->getJson("/api/department/meetings/{$meeting->id}")->assertStatus(403);
    }
}
