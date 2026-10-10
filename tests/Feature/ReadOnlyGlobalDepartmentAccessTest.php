<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MeetingReport;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\WeeklyDepartmentReport;
use App\Services\DepartmentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Read-only global department access (quality / quality_manager) and the
 * department member roster that feeds weekly-report / meeting evaluations.
 *
 * READ AUTHORITY != WRITE AUTHORITY: quality roles may read every
 * department's reports and rosters, but creating/editing reports still
 * requires canonical leadership (departments.leader_id). There are no
 * update/delete routes for operations reports — "editing" is a re-submit
 * through POST (upsert), which the leadership check guards.
 */
class ReadOnlyGlobalDepartmentAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function readOnlyGlobalRoles(): array
    {
        return [['quality_manager'], ['quality']];
    }

    private function makeDepartment(array $overrides = []): Department
    {
        $suffix = Str::random(8);

        return Department::create(array_merge([
            'name'      => 'Dept ' . $suffix,
            'name_ar'   => 'إدارة ' . $suffix,
            'slug'      => 'dept-' . strtolower($suffix),
            'status'    => 'active',
            'is_active' => true,
        ], $overrides));
    }

    private function makeUser(string $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $overrides));
    }

    private function makeWeeklyReport(Department $dept, string $achievements = 'original'): WeeklyDepartmentReport
    {
        return WeeklyDepartmentReport::create([
            'department_id' => $dept->id,
            'week_start'    => now()->startOfWeek()->toDateString(),
            'achievements'  => $achievements,
            'planned'       => 'plan',
            'submitted_by'  => $this->makeUser('programs_manager')->id,
            'submitted_at'  => now(),
        ]);
    }

    private function makeTeamMemberRow(User $user, Department $dept, string $status = 'active', ?string $roleTitle = null): TeamMember
    {
        $profile = TeamProfile::create([
            'user_id'       => $user->id,
            'name_ar'       => $user->name,
            'position_ar'   => 'عضو',
            'department_id' => $dept->id,
            'member_type'   => 'staff',
            'is_active'     => true,
        ]);

        return TeamMember::create([
            'user_id'         => $user->id,
            'team_profile_id' => $profile->id,
            'department_id'   => $dept->id,
            'status'          => $status,
            'role_title'      => $roleTitle,
        ]);
    }

    private function membersOf(User $viewer, Department $dept): \Illuminate\Support\Collection
    {
        return collect(
            $this->actingAs($viewer)->getJson("/api/operations/departments/{$dept->id}/members")
                ->assertOk()->json('data')
        );
    }

    // ── 1–4. Manifest: global READ scope, no CREATE scope ────────────────────

    #[DataProvider('readOnlyGlobalRoles')]
    public function test_read_only_global_role_receives_every_readable_department(string $role): void
    {
        $this->makeDepartment();
        $this->makeDepartment();
        $user = $this->makeUser($role);

        $ids = app(DepartmentAccessService::class)->getAccessibleDepartmentIds($user)->sort()->values();

        $this->assertSame(Department::query()->pluck('id')->sort()->values()->all(), $ids->all());
    }

    #[DataProvider('readOnlyGlobalRoles')]
    public function test_manifest_reflects_global_read_only_access(string $role): void
    {
        $this->makeDepartment();
        $this->makeDepartment();
        $user = $this->makeUser($role);

        $manifest = $this->actingAs($user)->getJson('/api/operations/departments/my-access')
            ->assertOk()->json('data');

        $this->assertSame('global', $manifest['read_scope']);
        $this->assertTrue($manifest['can_view_multiple_departments']);
        $this->assertEqualsCanonicalizing(
            Department::query()->active()->pluck('id')->all(),
            collect($manifest['readable_departments'])->pluck('id')->all(),
        );
    }

    #[DataProvider('readOnlyGlobalRoles')]
    public function test_manifest_does_not_grant_create_authority(string $role): void
    {
        $this->makeDepartment();
        $user = $this->makeUser($role);

        $manifest = $this->actingAs($user)->getJson('/api/operations/departments/my-access')
            ->assertOk()->json('data');

        $this->assertFalse($manifest['can_create_report']);
        $this->assertFalse($manifest['can_select_any_department']);
        $this->assertSame('restricted', $manifest['department_scope']);
        $this->assertSame([], $manifest['allowed_departments']);
    }

    public function test_read_only_global_user_who_also_leads_a_department_can_create_only_there(): void
    {
        $led = $this->makeDepartment();
        $other = $this->makeDepartment();
        $user = $this->makeUser('quality_manager');
        $led->update(['leader_id' => $user->id]);

        $manifest = $this->actingAs($user)->getJson('/api/operations/departments/my-access')->json('data');

        // Canonical leadership independently grants creation for the led
        // department only; global read never widens it.
        $this->assertTrue($manifest['can_create_report']);
        $this->assertSame([$led->id], collect($manifest['allowed_departments'])->pluck('id')->all());
        $this->assertSame('global', $manifest['read_scope']);

        $this->actingAs($user)->postJson('/api/operations/weekly-reports', [
            'department_id' => $led->id, 'achievements' => 'x', 'planned' => 'y',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/operations/weekly-reports', [
            'department_id' => $other->id, 'achievements' => 'x', 'planned' => 'y',
        ])->assertForbidden();
    }

    // ── 5–8. Read yes; create / edit / delete no ─────────────────────────────

    #[DataProvider('readOnlyGlobalRoles')]
    public function test_read_only_global_user_can_read_other_departments_weekly_reports(string $role): void
    {
        $a = $this->makeDepartment();
        $b = $this->makeDepartment();
        $reportA = $this->makeWeeklyReport($a);
        $reportB = $this->makeWeeklyReport($b);
        $user = $this->makeUser($role);

        $all = collect($this->actingAs($user)->getJson('/api/operations/weekly-reports')
            ->assertOk()->json('data.data'))->pluck('id');
        $this->assertTrue($all->contains($reportA->id));
        $this->assertTrue($all->contains($reportB->id));

        // The department filter narrows correctly.
        $filtered = collect($this->actingAs($user)->getJson("/api/operations/weekly-reports?department_id={$b->id}")
            ->assertOk()->json('data.data'))->pluck('id');
        $this->assertSame([$reportB->id], $filtered->all());
    }

    #[DataProvider('readOnlyGlobalRoles')]
    public function test_read_only_global_user_can_read_other_departments_meeting_report_and_roster(string $role): void
    {
        $dept = $this->makeDepartment();
        $author = $this->makeUser('programs_manager');
        $report = MeetingReport::create([
            'department_id' => $dept->id, 'title' => 'اجتماع', 'meeting_date' => now()->toDateString(),
            'created_by' => $author->id,
        ]);
        $user = $this->makeUser($role);

        $this->actingAs($user)->getJson("/api/operations/meeting-reports/{$report->id}")->assertOk();
        $this->actingAs($user)->getJson("/api/operations/departments/{$dept->id}/members")->assertOk();
    }

    #[DataProvider('readOnlyGlobalRoles')]
    public function test_read_only_global_user_cannot_create_reports_for_another_department(string $role): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeUser($role);

        $this->actingAs($user)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id, 'achievements' => 'x', 'planned' => 'y',
        ])->assertForbidden();

        $this->actingAs($user)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id, 'title' => 'اجتماع', 'meeting_date' => now()->toDateString(), 'ratings' => [],
        ])->assertForbidden();

        $this->assertSame(0, WeeklyDepartmentReport::where('department_id', $dept->id)->count());
        $this->assertSame(0, MeetingReport::where('department_id', $dept->id)->count());
    }

    #[DataProvider('readOnlyGlobalRoles')]
    public function test_read_only_global_user_cannot_edit_another_departments_report(string $role): void
    {
        // Weekly reports are edited by re-submitting for the same
        // department + week (upsert); that path must stay leadership-only.
        $dept = $this->makeDepartment();
        $report = $this->makeWeeklyReport($dept, 'original');
        $user = $this->makeUser($role);

        $this->actingAs($user)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id,
            'week_start'    => $report->week_start->toDateString(),
            'achievements'  => 'tampered',
            'planned'       => 'tampered',
        ])->assertForbidden();

        $this->assertSame('original', $report->fresh()->achievements);
    }

    #[DataProvider('readOnlyGlobalRoles')]
    public function test_read_only_global_user_cannot_delete_another_departments_report(string $role): void
    {
        // No delete route exists for operations reports at all; a direct
        // call must never reach a handler that removes data.
        $dept = $this->makeDepartment();
        $report = $this->makeWeeklyReport($dept);
        $user = $this->makeUser($role);

        $status = $this->actingAs($user)->deleteJson("/api/operations/weekly-reports/{$report->id}")->status();

        $this->assertContains($status, [403, 404, 405]);
        $this->assertNotNull($report->fresh());
    }

    #[DataProvider('readOnlyGlobalRoles')]
    public function test_read_only_global_user_is_not_treated_as_a_department_manager(string $role): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeUser($role);
        $service = app(DepartmentAccessService::class);

        $this->assertTrue($service->canAccessDepartment($user, $dept->id));
        $this->assertFalse($service->canManageDepartment($user, $dept->id));
        $this->assertNull($service->resolveSoleLedDepartmentId($user));
        $this->assertTrue($service->getLedDepartmentIds($user)->isEmpty());
    }

    // ── 9–10. Existing leader / member behavior unchanged ────────────────────

    public function test_leader_manifest_and_creation_are_unchanged(): void
    {
        $led = $this->makeDepartment();
        $other = $this->makeDepartment();
        $leader = $this->makeUser('programs_manager');
        $led->update(['leader_id' => $leader->id]);

        $manifest = $this->actingAs($leader)->getJson('/api/operations/departments/my-access')->json('data');

        $this->assertSame('restricted', $manifest['department_scope']);
        $this->assertSame([$led->id], collect($manifest['allowed_departments'])->pluck('id')->all());
        $this->assertFalse($manifest['can_select_any_department']);
        $this->assertTrue($manifest['can_create_report']);
        $this->assertSame('restricted', $manifest['read_scope']);
        $this->assertSame([$led->id], collect($manifest['readable_departments'])->pluck('id')->all());
        $this->assertFalse($manifest['can_view_multiple_departments']);

        $this->actingAs($leader)->postJson('/api/operations/weekly-reports', [
            'department_id' => $led->id, 'achievements' => 'x', 'planned' => 'y',
        ])->assertCreated();
        $this->actingAs($leader)->postJson('/api/operations/weekly-reports', [
            'department_id' => $other->id, 'achievements' => 'x', 'planned' => 'y',
        ])->assertForbidden();
    }

    public function test_leader_who_is_also_a_member_elsewhere_can_browse_both_but_create_only_where_led(): void
    {
        $led = $this->makeDepartment();
        $memberOf = $this->makeDepartment();
        $leader = $this->makeUser('programs_manager');
        $led->update(['leader_id' => $leader->id]);
        $leader->departments()->attach($memberOf->id, ['is_primary' => false]);

        $manifest = $this->actingAs($leader)->getJson('/api/operations/departments/my-access')->json('data');

        $this->assertSame([$led->id], collect($manifest['allowed_departments'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$led->id, $memberOf->id], collect($manifest['readable_departments'])->pluck('id')->all());
        $this->assertTrue($manifest['can_view_multiple_departments']);
    }

    public function test_global_admin_manifest_is_unchanged(): void
    {
        $this->makeDepartment();
        $this->makeDepartment();
        $admin = $this->makeUser('super_admin');

        $manifest = $this->actingAs($admin)->getJson('/api/operations/departments/my-access')->json('data');

        $activeIds = Department::query()->active()->pluck('id')->all();
        $this->assertSame('global', $manifest['department_scope']);
        $this->assertTrue($manifest['can_select_any_department']);
        $this->assertTrue($manifest['can_create_report']);
        $this->assertEqualsCanonicalizing($activeIds, collect($manifest['allowed_departments'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing($activeIds, collect($manifest['readable_departments'])->pluck('id')->all());
    }

    public function test_ordinary_member_cannot_read_unauthorized_departments(): void
    {
        $own = $this->makeDepartment();
        $foreign = $this->makeDepartment();
        $ownReport = $this->makeWeeklyReport($own);
        $foreignReport = $this->makeWeeklyReport($foreign);
        $member = $this->makeUser('instructor');
        $member->departments()->attach($own->id, ['is_primary' => true]);

        $ids = collect($this->actingAs($member)->getJson('/api/operations/weekly-reports')
            ->assertOk()->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($ownReport->id));
        $this->assertFalse($ids->contains($foreignReport->id));

        $this->actingAs($member)->getJson("/api/operations/departments/{$foreign->id}/members")->assertForbidden();

        $manifest = $this->actingAs($member)->getJson('/api/operations/departments/my-access')->json('data');
        $this->assertSame('restricted', $manifest['read_scope']);
        $this->assertSame([$own->id], collect($manifest['readable_departments'])->pluck('id')->all());
        $this->assertFalse($manifest['can_view_multiple_departments']);
        $this->assertFalse($manifest['can_create_report']);
    }

    // ── 11–13. Member roster: dedup, precedence, legacy fallback ─────────────

    public function test_department_members_does_not_duplicate_a_user_found_through_every_source(): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeUser('programs_manager', ['department' => $dept->slug]);
        $dept->update(['leader_id' => $user->id, 'deputy_leader_id' => $user->id, 'operational_member_id' => $user->id]);
        $user->departments()->attach($dept->id, ['is_primary' => true]);
        $this->makeTeamMemberRow($user, $dept, 'active', 'مدير البرامج');

        $matches = $this->membersOf($this->makeUser('super_admin'), $dept)->where('id', $user->id)->values();

        $this->assertCount(1, $matches);
        // Highest-precedence source decides kind; a lower canonical source may
        // still fill the title it lacked.
        $this->assertSame('leader', $matches[0]['kind']);
        $this->assertSame('مدير البرامج', $matches[0]['role_title']);
    }

    public function test_active_team_profile_member_is_included(): void
    {
        // Regression: the branch loaded profile users without `is_active`, so
        // this source silently yielded nobody.
        $dept = $this->makeDepartment();
        $user = $this->makeUser('instructor');
        TeamProfile::create([
            'user_id' => $user->id, 'name_ar' => $user->name, 'position_ar' => 'منسق',
            'department_id' => $dept->id, 'member_type' => 'staff', 'is_active' => true,
        ]);

        $entry = $this->membersOf($this->makeUser('super_admin'), $dept)->firstWhere('id', $user->id);

        $this->assertNotNull($entry);
        $this->assertSame('منسق', $entry['role_title']);
    }

    public function test_team_profile_of_an_inactive_account_is_excluded(): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeUser('instructor', ['is_active' => false]);
        TeamProfile::create([
            'user_id' => $user->id, 'name_ar' => $user->name, 'position_ar' => 'منسق',
            'department_id' => $dept->id, 'member_type' => 'staff', 'is_active' => true,
        ]);

        $this->assertNull($this->membersOf($this->makeUser('super_admin'), $dept)->firstWhere('id', $user->id));
    }

    public function test_legacy_text_fallback_recovers_a_user_unknown_to_canonical_structure(): void
    {
        $dept = $this->makeDepartment();
        $legacy = $this->makeUser('instructor', ['department' => $dept->slug]);

        $entry = $this->membersOf($this->makeUser('super_admin'), $dept)->firstWhere('id', $legacy->id);

        $this->assertNotNull($entry);
        $this->assertSame('team', $entry['kind']);
    }

    public function test_canonical_membership_takes_precedence_over_legacy_text(): void
    {
        $legacyNamed = $this->makeDepartment();
        $canonical = $this->makeDepartment();
        // Stale free text still names the old department, but canonical data
        // now places the user elsewhere.
        $user = $this->makeUser('instructor', ['department' => $legacyNamed->slug]);
        $this->makeTeamMemberRow($user, $canonical);

        $admin = $this->makeUser('super_admin');

        $this->assertNull($this->membersOf($admin, $legacyNamed)->firstWhere('id', $user->id));
        $this->assertNotNull($this->membersOf($admin, $canonical)->firstWhere('id', $user->id));
    }

    public function test_legacy_text_never_revives_a_canonically_deactivated_member(): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeUser('instructor', ['department' => $dept->slug]);
        $this->makeTeamMemberRow($user, $dept, 'inactive');

        $this->assertNull($this->membersOf($this->makeUser('super_admin'), $dept)->firstWhere('id', $user->id));
    }

    public function test_ambiguous_legacy_text_does_not_place_a_user_in_a_department(): void
    {
        // Two departments share the same Arabic name — the text identifies
        // neither of them, so it must not be used.
        $first = $this->makeDepartment(['name_ar' => 'الإدارة المشتركة']);
        $second = $this->makeDepartment(['name_ar' => 'الإدارة المشتركة']);
        $user = $this->makeUser('instructor', ['department' => 'الإدارة المشتركة']);

        $admin = $this->makeUser('super_admin');

        $this->assertNull($this->membersOf($admin, $first)->firstWhere('id', $user->id));
        $this->assertNull($this->membersOf($admin, $second)->firstWhere('id', $user->id));
    }

    public function test_unrelated_legacy_text_is_not_matched(): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeUser('instructor', ['department' => 'قسم غير موجود']);

        $this->assertNull($this->membersOf($this->makeUser('super_admin'), $dept)->firstWhere('id', $user->id));
    }

    public function test_inactive_legacy_account_is_excluded(): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeUser('instructor', ['department' => $dept->slug, 'is_active' => false]);

        $this->assertNull($this->membersOf($this->makeUser('super_admin'), $dept)->firstWhere('id', $user->id));
    }
}
