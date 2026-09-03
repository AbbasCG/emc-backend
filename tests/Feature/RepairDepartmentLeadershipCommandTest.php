<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `php artisan departments:repair-leadership` — the idempotent, dry-run-by-
 * default data repair for the exact bug this engagement traced down to
 * test-7@test.nl / TeamProfile#5 / Department#3 (leader_id=27 while
 * is_leader=true pointed at a different real user). Only repairs
 * unambiguous mismatches; never guesses on multi-candidate or unlinked
 * profiles.
 */
class RepairDepartmentLeadershipCommandTest extends TestCase
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

    public function test_dry_run_makes_no_database_changes(): void
    {
        $dept = $this->makeDepartment();
        $wrongLeader = User::factory()->create(['is_active' => true]);
        $rightUser = User::factory()->create(['is_active' => true]);
        $dept->update(['leader_id' => $wrongLeader->id]);
        TeamProfile::create([
            'user_id' => $rightUser->id, 'department_id' => $dept->id,
            'name_ar' => 'x', 'position_ar' => 'y', 'member_type' => 'staff', 'is_active' => true, 'is_leader' => true,
        ]);

        $this->artisan('departments:repair-leadership')->assertSuccessful();

        $this->assertSame($wrongLeader->id, $dept->fresh()->leader_id);
    }

    public function test_dry_run_detects_the_mismatch(): void
    {
        $dept = $this->makeDepartment();
        $wrongLeader = User::factory()->create(['is_active' => true]);
        $rightUser = User::factory()->create(['is_active' => true]);
        $dept->update(['leader_id' => $wrongLeader->id]);
        TeamProfile::create([
            'user_id' => $rightUser->id, 'department_id' => $dept->id,
            'name_ar' => 'x', 'position_ar' => 'y', 'member_type' => 'staff', 'is_active' => true, 'is_leader' => true,
        ]);

        $this->artisan('departments:repair-leadership')
            ->expectsOutputToContain('Would repair 1 department')
            ->assertSuccessful();
    }

    public function test_apply_fixes_unambiguous_mismatch(): void
    {
        $dept = $this->makeDepartment();
        $wrongLeader = User::factory()->create(['is_active' => true]);
        $rightUser = User::factory()->create(['is_active' => true]);
        $dept->update(['leader_id' => $wrongLeader->id]);
        TeamProfile::create([
            'user_id' => $rightUser->id, 'department_id' => $dept->id,
            'name_ar' => 'x', 'position_ar' => 'y', 'member_type' => 'staff', 'is_active' => true, 'is_leader' => true,
        ]);

        $this->artisan('departments:repair-leadership', ['--apply' => true])->assertSuccessful();

        $this->assertSame($rightUser->id, $dept->fresh()->leader_id);
    }

    public function test_apply_skips_ambiguous_multiple_leader_case(): void
    {
        $dept = $this->makeDepartment();
        $wrongLeader = User::factory()->create(['is_active' => true]);
        $userA = User::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['is_active' => true]);
        $dept->update(['leader_id' => $wrongLeader->id]);
        TeamProfile::create([
            'user_id' => $userA->id, 'department_id' => $dept->id,
            'name_ar' => 'a', 'position_ar' => 'y', 'member_type' => 'staff', 'is_active' => true, 'is_leader' => true,
        ]);
        TeamProfile::create([
            'user_id' => $userB->id, 'department_id' => $dept->id,
            'name_ar' => 'b', 'position_ar' => 'y', 'member_type' => 'staff', 'is_active' => true, 'is_leader' => true,
        ]);

        $this->artisan('departments:repair-leadership', ['--apply' => true])
            ->expectsOutputToContain('MANUAL REVIEW')
            ->assertSuccessful();

        // Neither candidate is auto-assigned — the wrong leader_id is left untouched.
        $this->assertSame($wrongLeader->id, $dept->fresh()->leader_id);
    }

    public function test_apply_skips_profile_with_no_linked_user(): void
    {
        $dept = $this->makeDepartment();
        $wrongLeader = User::factory()->create(['is_active' => true]);
        $dept->update(['leader_id' => $wrongLeader->id]);
        TeamProfile::create([
            'user_id' => null, 'department_id' => $dept->id,
            'name_ar' => 'x', 'position_ar' => 'y', 'member_type' => 'staff', 'is_active' => true, 'is_leader' => true,
        ]);

        $this->artisan('departments:repair-leadership', ['--apply' => true])->assertSuccessful();

        $this->assertSame($wrongLeader->id, $dept->fresh()->leader_id);
    }

    public function test_command_is_idempotent(): void
    {
        $dept = $this->makeDepartment();
        $wrongLeader = User::factory()->create(['is_active' => true]);
        $rightUser = User::factory()->create(['is_active' => true]);
        $dept->update(['leader_id' => $wrongLeader->id]);
        TeamProfile::create([
            'user_id' => $rightUser->id, 'department_id' => $dept->id,
            'name_ar' => 'x', 'position_ar' => 'y', 'member_type' => 'staff', 'is_active' => true, 'is_leader' => true,
        ]);

        $this->artisan('departments:repair-leadership', ['--apply' => true])->assertSuccessful();
        $leaderIdAfterFirstApply = $dept->fresh()->leader_id;

        $this->artisan('departments:repair-leadership', ['--apply' => true])
            ->expectsOutputToContain('No unambiguous mismatches found')
            ->assertSuccessful();

        $this->assertSame($leaderIdAfterFirstApply, $dept->fresh()->leader_id);
        $this->assertSame($rightUser->id, $dept->fresh()->leader_id);
    }

    public function test_department_already_consistent_is_reported_ok_not_repaired(): void
    {
        $dept = $this->makeDepartment();
        $user = User::factory()->create(['is_active' => true]);
        $dept->update(['leader_id' => $user->id]);
        TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id,
            'name_ar' => 'x', 'position_ar' => 'y', 'member_type' => 'staff', 'is_active' => true, 'is_leader' => true,
        ]);

        $this->artisan('departments:repair-leadership')
            ->expectsOutputToContain('No unambiguous mismatches found')
            ->assertSuccessful();

        $this->assertSame($user->id, $dept->fresh()->leader_id);
    }
}
