<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WeeklyDepartmentReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies `departments:merge-duplicate-ai` against the EXACT production IDs
 * confirmed for this merge: SOURCE Department #11 (slug=ai-digital-
 * transformation, leader_id=NULL) merges into TARGET Department #13
 * (slug=ai-transformation, leader_id=2078, canonical). Both are hard-guarded
 * by id+slug (and the target additionally by leader_id=2078) — no discovery
 * heuristic. Live organizational data moves to #13; historical/snapshot data
 * stays attached to #11. #11 is deleted only when it ends up truly
 * reference-free; otherwise it's archived (existing `status` column), never
 * hard-deleted while historical rows still point at it.
 */
class MergeDuplicateAiDepartmentCommandTest extends TestCase
{
    use RefreshDatabase;

    /** The canonical target leader — production user #2078, guarded by the command. */
    private function makeCanonicalLeader(): User
    {
        $leader = new User(['name' => 'Leader', 'email' => 'leader2078@example.com', 'password' => bcrypt('secret'), 'role' => 'ai_manager', 'is_active' => true]);
        $leader->id = 2078;
        $leader->save();

        return $leader;
    }

    private function makeTarget(array $overrides = []): Department
    {
        $overrides += ['leader_id' => $this->makeCanonicalLeader()->id];
        $target = new Department(array_merge([
            'name' => 'AI', 'name_ar' => 'إدارة الذكاء الاصطناعي والتحول الرقمي', 'name_en' => 'AI & Digital Transformation',
            'slug' => 'ai-transformation', 'status' => 'active', 'is_active' => true,
        ], $overrides));
        $target->id = 13;
        $target->save();

        return $target;
    }

    private function makeSource(array $overrides = []): Department
    {
        $source = new Department(array_merge([
            'name' => 'AI Dup', 'name_ar' => 'إدارة الذكاء الاصطناعي والتحول الرقمي', 'name_en' => 'AI & Digital Transformation Department',
            'slug' => 'ai-digital-transformation', 'status' => 'active', 'is_active' => true,
        ], $overrides));
        $source->id = 11;
        $source->save();

        return $source;
    }

    public function test_source_already_gone_is_a_no_op(): void
    {
        $this->makeTarget();

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])
            ->expectsOutputToContain('no longer exists')
            ->assertSuccessful();
    }

    public function test_aborts_if_target_department_13_missing(): void
    {
        $this->makeSource();

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])->assertFailed();

        $this->assertSame(1, Department::count());
    }

    public function test_aborts_if_target_slug_is_wrong(): void
    {
        $this->makeTarget(['slug' => 'something-else']);
        $this->makeSource();

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])->assertFailed();

        $this->assertSame(2, Department::count());
    }

    public function test_aborts_if_target_leader_is_not_2078(): void
    {
        $wrongLeader = User::factory()->create();
        $this->makeTarget(['leader_id' => $wrongLeader->id]);
        $this->makeSource();

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])->assertFailed();

        $this->assertSame($wrongLeader->id, Department::find(13)->leader_id, 'no writes on guard failure');
        $this->assertNotNull(Department::find(11), 'source must survive when blocked');
    }

    public function test_aborts_if_source_slug_is_wrong(): void
    {
        $this->makeTarget();
        $this->makeSource(['slug' => 'not-the-expected-duplicate']);

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])->assertFailed();

        $this->assertSame(2, Department::count());
    }

    public function test_dry_run_makes_no_writes(): void
    {
        $target = $this->makeTarget();
        $source = $this->makeSource();
        TeamProfile::create([
            'department_id' => $source->id, 'user_id' => User::factory()->create()->id,
            'name_ar' => 'Test', 'position_ar' => 'Member', 'is_active' => true,
        ]);

        $this->artisan('departments:merge-duplicate-ai')
            ->expectsOutputToContain('WILL_MOVE')
            ->assertSuccessful();

        $this->assertSame(2, Department::count());
        $this->assertSame($source->id, TeamProfile::first()->department_id);
    }

    public function test_apply_moves_team_profiles_team_members_and_units_and_deletes_reference_free_source(): void
    {
        $target = $this->makeTarget();
        $source = $this->makeSource();

        $memberUser = User::factory()->create();
        $profile = TeamProfile::create([
            'department_id' => $source->id, 'user_id' => $memberUser->id,
            'name_ar' => 'عضو', 'position_ar' => 'Member', 'is_leader' => true, 'is_active' => true,
        ]);
        $unit = DepartmentalUnit::create([
            'department_id' => $source->id, 'code' => 'DUPU1', 'name_ar' => 'وحدة', 'is_active' => true,
        ]);
        $member = TeamMember::create([
            'user_id' => $memberUser->id, 'team_profile_id' => $profile->id, 'department_id' => $source->id,
            'unit_id' => $unit->id, 'status' => 'active',
        ]);

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])
            ->expectsOutputToContain('deleted')
            ->assertSuccessful();

        $profile->refresh();
        $this->assertSame($target->id, $profile->department_id);
        $this->assertFalse($profile->is_leader, 'is_leader must be force-cleared, never left true after leaving its department');

        $unit->refresh();
        $this->assertSame($target->id, $unit->department_id);

        $member->refresh();
        $this->assertSame($target->id, $member->department_id);
        $this->assertTrue(DepartmentalUnit::where('id', $member->unit_id)->where('department_id', $target->id)->exists());

        $this->assertNull(Department::find(11), 'reference-free source must be deleted');
        $this->assertSame(13, Department::find(13)->id, 'target must remain');
    }

    public function test_target_leader_id_2078_is_never_overwritten(): void
    {
        $target = $this->makeTarget();
        $source = $this->makeSource();

        $otherUser = User::factory()->create();
        TeamProfile::create([
            'department_id' => $source->id, 'user_id' => $otherUser->id,
            'name_ar' => 'قائد آخر', 'position_ar' => 'Manager', 'is_leader' => true, 'is_active' => true,
        ]);

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])
            ->expectsOutputToContain('leader_id remains: 2078')
            ->assertSuccessful();

        $target->refresh();
        $this->assertSame(2078, $target->leader_id);
    }

    public function test_historical_ticket_blocks_deletion_and_source_is_archived_instead(): void
    {
        $target = $this->makeTarget();
        $source = $this->makeSource();

        Ticket::create([
            'ticket_number' => 'EMC-TCK-TEST-001', 'title' => 'Old ticket', 'description' => 'x',
            'department_id' => $source->id, 'created_by_name' => 'Someone',
        ]);

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])
            ->expectsOutputToContain('archived')
            ->assertSuccessful();

        $source->refresh();
        $this->assertSame('archived', $source->status);
        $this->assertSame(11, Ticket::first()->department_id, 'ticket must keep its original historical department_id, never rewritten');
    }

    public function test_apply_moves_courses_and_preserves_targets_existing_courses_without_duplication(): void
    {
        $target = $this->makeTarget();
        $source = $this->makeSource();

        $sourceCourse1 = Course::create(['title' => 'Source Course 1', 'slug' => 'source-course-1', 'department_id' => $source->id]);
        $sourceCourse2 = Course::create(['title' => 'Source Course 2', 'slug' => 'source-course-2', 'department_id' => $source->id]);
        $targetCourse = Course::create(['title' => 'Target Course', 'slug' => 'target-course', 'department_id' => $target->id]);

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])->assertSuccessful();

        $this->assertSame($target->id, $sourceCourse1->fresh()->department_id, 'source courses must move to the target — courses.department_id is live current ownership, not historical');
        $this->assertSame($target->id, $sourceCourse2->fresh()->department_id);
        $this->assertSame($target->id, $targetCourse->fresh()->department_id, 'target\'s own pre-existing course must be untouched');
        $this->assertSame(3, Course::where('department_id', $target->id)->count(), 'no course duplication — exactly the 2 moved + 1 pre-existing');
        $this->assertSame(0, Course::where('department_id', 11)->count());
    }

    public function test_reports_remain_historical_on_source_courses_move_and_target_leader_stays_2078(): void
    {
        // Mirrors the exact production dry-run shape: 1 volunteer_hr_profile
        // (MOVE), 1 meeting_report + 1 weekly_department_report (PRESERVE),
        // 14 courses worth of live ownership (represented here by 2, MOVE).
        $target = $this->makeTarget();
        $source = $this->makeSource();
        $creator = User::factory()->create();

        $course = Course::create(['title' => 'AI Course', 'slug' => 'ai-course-prod-shape', 'department_id' => $source->id]);
        $meetingReport = MeetingReport::create([
            'department_id' => $source->id, 'title' => 'Old meeting report', 'meeting_date' => now()->toDateString(), 'created_by' => $creator->id,
        ]);
        $weeklyReport = WeeklyDepartmentReport::create([
            'department_id' => $source->id, 'week_start' => now()->startOfWeek()->toDateString(), 'submitted_by' => $creator->id,
            'achievements' => 'x', 'planned' => 'y', 'submitted_at' => now(),
        ]);

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])
            ->expectsOutputToContain('archived')
            ->expectsOutputToContain('leader_id remains: 2078')
            ->assertSuccessful();

        $this->assertSame($target->id, $course->fresh()->department_id, 'course ownership moves to the canonical department');
        $this->assertSame(11, $meetingReport->fresh()->department_id, 'meeting report keeps its original historical department_id');
        $this->assertSame(11, $weeklyReport->fresh()->department_id, 'weekly report keeps its original historical department_id');

        $source->refresh();
        $this->assertSame('archived', $source->status, 'source archived — historical reports still reference it, so it cannot be deleted');
        $target->refresh();
        $this->assertSame(2078, $target->leader_id);
    }

    public function test_move_category_tasks_and_meetings_are_reassigned(): void
    {
        $target = $this->makeTarget();
        $source = $this->makeSource();
        $creator = User::factory()->create();

        $task = Task::create(['title' => 't', 'department_id' => $source->id, 'created_by' => $creator->id]);
        $meeting = Meeting::create([
            'title' => 'm', 'department_id' => $source->id, 'organizer_id' => $creator->id,
            'meeting_date' => now()->toDateString(), 'start_time' => '10:00',
        ]);

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])->assertSuccessful();

        $this->assertSame($target->id, $task->fresh()->department_id);
        $this->assertSame($target->id, $meeting->fresh()->department_id);
    }

    public function test_child_departments_block_the_merge(): void
    {
        $target = $this->makeTarget();
        $source = $this->makeSource();
        Department::create([
            'name' => 'Child', 'name_ar' => 'قسم فرعي', 'slug' => 'dup-child',
            'status' => 'active', 'is_active' => true, 'parent_id' => $source->id,
        ]);

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])->assertFailed();

        $this->assertNotNull(Department::find(11), 'source must survive when blocked');
    }

    public function test_idempotent_after_delete(): void
    {
        $this->makeTarget();
        $this->makeSource();

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, Department::count());

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])
            ->expectsOutputToContain('no longer exists')
            ->assertSuccessful();
        $this->assertSame(1, Department::count());
    }

    public function test_idempotent_after_archive(): void
    {
        $target = $this->makeTarget();
        $source = $this->makeSource();
        Ticket::create([
            'ticket_number' => 'EMC-TCK-TEST-002', 'title' => 'Old', 'description' => 'x',
            'department_id' => $source->id, 'created_by_name' => 'Someone',
        ]);

        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])->assertSuccessful();
        $this->assertSame('archived', $source->fresh()->status);

        // Re-run: source still exists (id=11 unchanged), everything MOVE-able
        // is already at 0, the ticket still blocks deletion — same archived
        // outcome, no errors, no duplicate writes.
        $this->artisan('departments:merge-duplicate-ai', ['--apply' => true])
            ->expectsOutputToContain('archived')
            ->assertSuccessful();

        $this->assertSame('archived', $source->fresh()->status);
        $this->assertSame(2, Department::count());
    }
}
