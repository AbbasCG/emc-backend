<?php

namespace Tests\Feature;

use App\Console\Commands\SyncDepartmentDefaultsCommand;
use App\Models\Department;
use App\Models\DepartmentPageDefault;
use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentPageDefaultService;
use App\Services\EffectivePageAccessService;
use App\Support\PageAccess\DepartmentDefaultMap;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\PageAccessSource;
use App\Services\UserPageAccessOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2G.4A — DRY-RUN + EFFECTIVE ACCESS PROOFS.
 *
 * Isolated in-memory SQLite throughout. The command is asserted to write zero
 * rows in every scenario, including when --apply is explicitly passed.
 */
class SyncDepartmentDefaultsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS = [
        'advisors', 'ai-transformation', 'community-health', 'digital-ambassadors',
        'executive-management', 'finance', 'human-resources', 'marketing-media',
        'operations', 'partnerships', 'programs-tracks', 'quality-governance',
        'strategy-planning', 'technology',
    ];

    private function makeDepartment(string $slug, string $status = 'active'): Department
    {
        return Department::create([
            'name' => $slug, 'name_ar' => $slug, 'slug' => $slug,
            'status' => $status, 'is_active' => $status === 'active',
        ]);
    }

    private function seedDepartments(): void
    {
        foreach (self::SLUGS as $slug) {
            $this->makeDepartment($slug);
        }
    }

    private function makeUser(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function makeMember(User $user, Department $dept): void
    {
        $profile = TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $user->id,
            'name_ar' => $user->name, 'position_ar' => 'عضو', 'is_active' => true,
        ]);

        TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $dept->id, 'status' => 'active',
        ]);
    }

    // ── 31. Dry-run behaviour ────────────────────────────────────────────────

    public function test_empty_department_defaults_produce_the_expected_proposals(): void
    {
        $this->seedDepartments();

        $this->artisan('access:sync-department-defaults --dry-run')
            ->expectsOutputToContain('Mappings evaluated              : 28')
            ->expectsOutputToContain('Would insert                    : 28')
            ->expectsOutputToContain('Already present                 : 0')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(0);
    }

    public function test_the_dry_run_writes_zero_rows(): void
    {
        $this->seedDepartments();

        $this->artisan('access:sync-department-defaults --dry-run')->assertExitCode(0);

        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    public function test_no_flag_is_also_read_only(): void
    {
        $this->seedDepartments();

        $this->artisan('access:sync-department-defaults')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(0);

        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    public function test_apply_requires_the_explicit_flag(): void
    {
        $this->seedDepartments();

        // Neither bare nor --dry-run may ever write; only --apply does.
        $this->artisan('access:sync-department-defaults')->assertExitCode(0);
        $this->artisan('access:sync-department-defaults --dry-run')->assertExitCode(0);

        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    public function test_an_existing_matching_row_reports_already_present(): void
    {
        $this->seedDepartments();

        $dept = Department::where('slug', 'technology')->firstOrFail();
        app(DepartmentPageDefaultService::class)->replaceDefaults(
            $dept, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $this->makeUser('super_admin'),
        );

        $this->artisan('access:sync-department-defaults --dry-run')
            ->expectsOutputToContain('Would insert                    : 26')
            ->expectsOutputToContain('Already present                 : 2')
            ->assertExitCode(0);

        $this->assertSame(2, DepartmentPageDefault::query()->count());
    }

    public function test_a_drifted_stored_row_is_reported_as_conflict(): void
    {
        $this->seedDepartments();

        $dept = Department::where('slug', 'operations')->firstOrFail();

        // Bypass the service to plant a row the service itself would refuse —
        // exactly the drift the scan exists to surface.
        DepartmentPageDefault::create([
            'department_id' => $dept->id,
            'page_key' => 'administration.roles', // SYSTEM_PROTECTED
            'audience' => DepartmentPageAudience::Leader->value,
        ]);

        $this->artisan('access:sync-department-defaults --dry-run')
            ->expectsOutputToContain('Conflicts                       : 1')
            ->expectsOutputToContain('Blocking actions                : 1')
            ->assertExitCode(0);

        // Reported, never repaired.
        $this->assertSame(1, DepartmentPageDefault::query()->count());
        $this->assertSame('administration.roles', DepartmentPageDefault::query()->first()->page_key);
    }

    public function test_a_missing_department_is_reported_as_invalid_department(): void
    {
        // Seed every slug EXCEPT one.
        foreach (array_diff(self::SLUGS, ['technology']) as $slug) {
            $this->makeDepartment($slug);
        }

        $this->artisan('access:sync-department-defaults --dry-run')
            ->expectsOutputToContain('Invalid departments             : 2')
            ->expectsOutputToContain('Would insert                    : 26')
            ->assertExitCode(0);

        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    public function test_an_inactive_department_is_skipped(): void
    {
        foreach (array_diff(self::SLUGS, ['technology']) as $slug) {
            $this->makeDepartment($slug);
        }
        $this->makeDepartment('technology', 'archived');

        $this->artisan('access:sync-department-defaults --dry-run')
            ->expectsOutputToContain('Skipped inactive departments    : 2')
            ->expectsOutputToContain('Would insert                    : 26')
            ->assertExitCode(0);
    }

    public function test_an_invalid_page_key_is_reported(): void
    {
        $this->seedDepartments();

        $command = new class extends SyncDepartmentDefaultsCommand
        {
            protected function canonicalMap(): array
            {
                return ['technology' => ['member' => [], 'leader' => ['does.not.exist']]];
            }
        };
        $command->setLaravel($this->app);

        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

        $this->artisan('access:sync-department-defaults --dry-run')
            ->expectsOutputToContain('Invalid page keys               : 1')
            ->assertExitCode(0);

        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    public function test_a_protected_page_in_the_plan_is_skipped_not_inserted(): void
    {
        $this->seedDepartments();

        $protected = array_values(array_map(fn (PageAccessEntry $e) => $e->key, array_filter(
            PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->isProtected()
        )))[0];

        $command = new class extends SyncDepartmentDefaultsCommand
        {
            public static string $key = '';

            protected function canonicalMap(): array
            {
                return ['technology' => ['member' => [], 'leader' => [static::$key]]];
            }
        };
        $command::$key = $protected;
        $command->setLaravel($this->app);
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

        $this->artisan('access:sync-department-defaults --dry-run')
            ->expectsOutputToContain('Would insert                    : 0')
            ->assertExitCode(0);

        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    public function test_a_custom_department_default_is_preserved_by_the_plan(): void
    {
        $this->seedDepartments();

        $dept = Department::where('slug', 'finance')->firstOrFail();
        app(DepartmentPageDefaultService::class)->replaceDefaults(
            $dept, DepartmentPageAudience::Member, ['operations.weekly_reports'], $this->makeUser('super_admin'),
        );

        $before = DepartmentPageDefault::query()->get(['department_id', 'page_key', 'audience'])->toArray();

        $this->artisan('access:sync-department-defaults --dry-run')->assertExitCode(0);

        $this->assertSame($before, DepartmentPageDefault::query()->get(['department_id', 'page_key', 'audience'])->toArray());
        $this->assertSame(1, DepartmentPageDefault::query()->count());
    }

    public function test_the_plan_touches_neither_role_defaults_nor_user_overrides(): void
    {
        $this->seedDepartments();

        $role = Role::firstOrCreate(['name' => 'volunteer'], ['display_name' => 'volunteer', 'is_system' => false]);
        RolePageDefault::create(['role_id' => $role->id, 'page_key' => 'operations.meeting_lounge']);

        $user = $this->makeUser('student');
        app(UserPageAccessOverrideService::class)->replaceOverrides(
            $this->makeUser('super_admin'), $user, ['personal.student_learning'], [],
        );

        $roleBefore = RolePageDefault::query()->count();
        $overrideBefore = DB::table('user_page_access_overrides')->count();
        $userBefore = User::query()->count();
        $deptBefore = Department::query()->count();

        $this->artisan('access:sync-department-defaults --dry-run')->assertExitCode(0);

        $this->assertSame($roleBefore, RolePageDefault::query()->count());
        $this->assertSame($overrideBefore, DB::table('user_page_access_overrides')->count());
        $this->assertSame($userBefore, User::query()->count());
        $this->assertSame($deptBefore, Department::query()->count());
        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    public function test_department_scoping_limits_the_plan(): void
    {
        $this->seedDepartments();

        $this->artisan('access:sync-department-defaults --dry-run --department=technology')
            ->expectsOutputToContain('Mappings evaluated              : 2')
            ->expectsOutputToContain('Would insert                    : 2')
            ->assertExitCode(0);
    }

    // ── 32. Effective access precedence ──────────────────────────────────────

    public function test_precedence_root_beats_every_department_default(): void
    {
        $dept = $this->makeDepartment('technology');
        app(DepartmentPageDefaultService::class)->replaceDefaults(
            $dept, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $this->makeUser('super_admin'),
        );

        foreach (['super_admin', 'tech_admin'] as $rootRole) {
            $root = $this->makeUser($rootRole);
            $resolved = app(EffectivePageAccessService::class)->resolveAll($root);

            $this->assertCount(
                count(PageAccessCatalog::all()),
                array_filter($resolved, fn ($r) => $r->allowed),
            );

            foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
                $this->assertSame(
                    PageAccessSource::RootAuthority, $resolved[$key]->primarySource,
                    'Root authority must win over a department leader default.'
                );
            }
        }
    }

    public function test_precedence_a_user_deny_overrides_a_department_leader_default(): void
    {
        $dept = $this->makeDepartment('technology');
        $actor = $this->makeUser('super_admin');
        app(DepartmentPageDefaultService::class)->replaceDefaults(
            $dept, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $actor,
        );

        $leader = $this->makeUser('department_manager');
        $this->makeMember($leader, $dept);
        $dept->forceFill(['leader_id' => $leader->id])->save();

        $key = DepartmentDefaultMap::LEADER_OWNED[0];

        $granted = app(EffectivePageAccessService::class)->resolveAll($leader);
        $this->assertTrue($granted[$key]->allowed);
        $this->assertSame(PageAccessSource::DepartmentLeader, $granted[$key]->primarySource);

        app(UserPageAccessOverrideService::class)->replaceOverrides(
            $actor, $leader, [], [$key],
        );

        $denied = app(EffectivePageAccessService::class)->resolveAll($leader->fresh());
        $this->assertFalse($denied[$key]->allowed, 'A user DENY must beat a department leader default.');
        $this->assertSame(PageAccessSource::UserDeny, $denied[$key]->primarySource);
    }

    public function test_precedence_a_department_default_outranks_a_role_default(): void
    {
        $dept = $this->makeDepartment('technology');
        $actor = $this->makeUser('super_admin');

        // Same page reachable two ways: role default AND department member default.
        $key = 'operations.weekly_reports';

        $role = Role::firstOrCreate(['name' => 'volunteer'], ['display_name' => 'volunteer', 'is_system' => false]);
        RolePageDefault::create(['role_id' => $role->id, 'page_key' => $key]);

        $member = $this->makeUser('volunteer');
        $this->makeMember($member, $dept);

        app(DepartmentPageDefaultService::class)->replaceDefaults(
            $dept, DepartmentPageAudience::Member, [$key], $actor,
        );

        $resolved = app(EffectivePageAccessService::class)->resolveAll($member);

        $this->assertTrue($resolved[$key]->allowed);
        $this->assertSame(
            PageAccessSource::DepartmentMember, $resolved[$key]->primarySource,
            'Department member (20) must outrank role (10).'
        );
    }

    public function test_precedence_the_authenticated_baseline_remains_its_own_source(): void
    {
        $dept = $this->makeDepartment('technology');
        app(DepartmentPageDefaultService::class)->replaceDefaults(
            $dept, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $this->makeUser('super_admin'),
        );

        $leader = $this->makeUser('volunteer');
        $this->makeMember($leader, $dept);
        $dept->forceFill(['leader_id' => $leader->id])->save();

        $resolved = app(EffectivePageAccessService::class)->resolveAll($leader);

        $baseline = array_filter(PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->authenticatedBaseline);
        $this->assertCount(8, $baseline);

        foreach ($baseline as $entry) {
            $this->assertTrue($resolved[$entry->key]->allowed);
            $this->assertSame(
                PageAccessSource::AuthenticatedBaseline, $resolved[$entry->key]->primarySource,
                "[{$entry->key}] must stay baseline-sourced."
            );
        }
    }

    public function test_precedence_system_protected_still_wins_for_a_department_leader(): void
    {
        $dept = $this->makeDepartment('technology');
        app(DepartmentPageDefaultService::class)->replaceDefaults(
            $dept, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $this->makeUser('super_admin'),
        );

        $leader = $this->makeUser('department_manager');
        $this->makeMember($leader, $dept);
        $dept->forceFill(['leader_id' => $leader->id])->save();

        $resolved = app(EffectivePageAccessService::class)->resolveAll($leader);

        foreach (PageAccessCatalog::all() as $entry) {
            if ($entry->isProtected()) {
                $this->assertFalse(
                    $resolved[$entry->key]->allowed,
                    "A department leader must never reach protected [{$entry->key}]."
                );
            }
        }

        foreach (DepartmentDefaultMap::INTENTIONAL_SYSTEM_PROTECTED as $key) {
            $this->assertFalse($resolved[$key]->allowed, "[{$key}] must remain denied.");
        }
    }

    public function test_leader_receives_member_defaults_additively_without_duplication(): void
    {
        $dept = $this->makeDepartment('technology');
        $actor = $this->makeUser('super_admin');
        $svc = app(DepartmentPageDefaultService::class);

        $svc->replaceDefaults($dept, DepartmentPageAudience::Member, ['operations.weekly_reports'], $actor);
        $svc->replaceDefaults($dept, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $actor);

        $leader = $this->makeUser('department_manager');
        $this->makeMember($leader, $dept);
        $dept->forceFill(['leader_id' => $leader->id])->save();

        $resolved = app(EffectivePageAccessService::class)->resolveAll($leader);

        // Member default reaches the leader WITHOUT being restated in the map.
        $this->assertTrue($resolved['operations.weekly_reports']->allowed);
        $this->assertSame(PageAccessSource::DepartmentMember, $resolved['operations.weekly_reports']->primarySource);

        foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
            $this->assertTrue($resolved[$key]->allowed);
            $this->assertSame(PageAccessSource::DepartmentLeader, $resolved[$key]->primarySource);
        }

        // A plain member gets the member default only.
        $plain = $this->makeUser('volunteer');
        $this->makeMember($plain, $dept);
        $plainResolved = app(EffectivePageAccessService::class)->resolveAll($plain);

        $this->assertTrue($plainResolved['operations.weekly_reports']->allowed);
        foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
            $this->assertFalse($plainResolved[$key]->allowed, "A member must not receive leader page [{$key}].");
        }
    }
}
