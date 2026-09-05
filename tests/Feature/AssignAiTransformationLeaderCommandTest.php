<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies `team-profiles:assign-ai-transformation-leader` — the one-off
 * canonical correction moving TeamProfile #38 (user #2078) from Department
 * #2 (programs-tracks) into Department #13 (ai-transformation) and
 * assigning department leadership, exactly mirroring the real production
 * state that was verified before this command was written.
 */
class AssignAiTransformationLeaderCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedExactProductionState(): array
    {
        $user = new User([
            'name' => 'م/ايمان السماوي', 'email' => 'iman.samawi@example.com',
            'password' => bcrypt('secret'), 'role' => 'ai_manager', 'is_active' => true,
        ]);
        $user->id = 2078;
        $user->save();

        $deptPrograms = new Department([
            'name' => 'Programs & Tracks', 'name_ar' => 'البرامج والمسارات', 'slug' => 'programs-tracks',
            'status' => 'active', 'is_active' => true,
        ]);
        $deptPrograms->id = 2;
        $deptPrograms->save();

        $deptAi = new Department([
            'name' => 'AI', 'name_ar' => 'إدارة الذكاء الاصطناعي والتحول الرقمي', 'name_en' => 'AI & Digital Transformation',
            'slug' => 'ai-transformation', 'status' => 'active', 'is_active' => true,
        ]);
        $deptAi->id = 13;
        $deptAi->save();

        $profile = new TeamProfile([
            'department_id' => 2, 'user_id' => 2078, 'name_ar' => 'م/ايمان السماوي',
            'position_ar' => 'مدير الإدارة', 'is_leader' => false, 'is_active' => true,
        ]);
        $profile->id = 38;
        $profile->save();

        return compact('user', 'deptPrograms', 'deptAi', 'profile');
    }

    public function test_dry_run_makes_no_writes(): void
    {
        $this->seedExactProductionState();

        $this->artisan('team-profiles:assign-ai-transformation-leader')->assertSuccessful();

        $profile = TeamProfile::find(38);
        $this->assertSame(2, $profile->department_id);
        $this->assertFalse($profile->is_leader);
        $this->assertNull(Department::find(13)->leader_id);
    }

    public function test_apply_moves_profile_and_assigns_leadership(): void
    {
        $this->seedExactProductionState();

        $this->artisan('team-profiles:assign-ai-transformation-leader', ['--apply' => true])
            ->expectsOutputToContain('Applied.')
            ->assertSuccessful();

        $profile = TeamProfile::find(38);
        $this->assertSame(13, $profile->department_id);
        $this->assertTrue($profile->is_leader);

        $deptAi = Department::find(13);
        $this->assertSame(2078, $deptAi->leader_id);

        $deptPrograms = Department::find(2);
        $this->assertNull($deptPrograms->leader_id);

        $svc = app(DepartmentAccessService::class);
        $this->assertTrue($svc->getLedDepartmentIds(User::find(2078))->contains(13));
    }

    public function test_apply_syncs_linked_team_member_department_and_clears_stale_unit(): void
    {
        $seed = $this->seedExactProductionState();

        $oldUnit = DepartmentalUnit::create([
            'department_id' => 2, 'code' => 'U1', 'name_ar' => 'وحدة قديمة', 'is_active' => true,
        ]);

        $member = TeamMember::create([
            'user_id' => 2078, 'team_profile_id' => 38, 'department_id' => 2,
            'unit_id' => $oldUnit->id, 'status' => 'active',
        ]);

        $this->artisan('team-profiles:assign-ai-transformation-leader', ['--apply' => true])->assertSuccessful();

        $member->refresh();
        $this->assertSame(13, $member->department_id);
        $this->assertNull($member->unit_id);
    }

    public function test_command_is_idempotent(): void
    {
        $this->seedExactProductionState();

        $this->artisan('team-profiles:assign-ai-transformation-leader', ['--apply' => true])->assertSuccessful();

        $this->artisan('team-profiles:assign-ai-transformation-leader')
            ->expectsOutputToContain('Already correct')
            ->assertSuccessful();

        $this->artisan('team-profiles:assign-ai-transformation-leader', ['--apply' => true])
            ->expectsOutputToContain('Already correct')
            ->assertSuccessful();

        $this->assertSame(1, TeamProfile::count());
        $this->assertSame(2078, Department::find(13)->leader_id);
    }

    public function test_aborts_if_team_profile_38_belongs_to_a_different_user(): void
    {
        $this->seedExactProductionState();
        $otherUser = User::factory()->create();
        TeamProfile::find(38)->update(['user_id' => $otherUser->id]);

        $this->artisan('team-profiles:assign-ai-transformation-leader', ['--apply' => true])
            ->assertFailed();

        $profile = TeamProfile::find(38);
        $this->assertSame(2, $profile->department_id);
        $this->assertNull(Department::find(13)->leader_id);
    }

    public function test_aborts_if_department_13_is_not_ai_transformation(): void
    {
        $this->seedExactProductionState();
        Department::find(13)->update(['slug' => 'something-else']);

        $this->artisan('team-profiles:assign-ai-transformation-leader', ['--apply' => true])
            ->assertFailed();

        $this->assertSame(2, TeamProfile::find(38)->department_id);
    }

    public function test_role_alone_never_grants_leadership_without_this_command(): void
    {
        $this->seedExactProductionState();

        $svc = app(DepartmentAccessService::class);
        $this->assertFalse($svc->canManageDepartment(User::find(2078), 13));
        $this->assertTrue($svc->getLedDepartmentIds(User::find(2078))->isEmpty());
    }
}
