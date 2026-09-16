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
use App\Services\UserPageAccessOverrideService;
use App\Support\PageAccess\DepartmentDefaultMap;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase D — SAFE DEPARTMENT DEFAULT APPLY.
 *
 * Every write happens in isolated in-memory SQLite (RefreshDatabase). Nothing
 * here touches any persistent database.
 */
class SyncDepartmentDefaultsApplyTest extends TestCase
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

    // ── Read-only guarantees ─────────────────────────────────────────────────

    public function test_bare_command_writes_zero_rows(): void
    {
        $this->seedDepartments();

        $this->artisan('access:sync-department-defaults')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(0);

        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    public function test_dry_run_writes_zero_rows(): void
    {
        $this->seedDepartments();

        $this->artisan('access:sync-department-defaults --dry-run')
            ->expectsOutputToContain('Would insert                    : 28')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(0);

        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    // ── First apply ──────────────────────────────────────────────────────────

    public function test_first_apply_inserts_every_mapped_pair(): void
    {
        $this->seedDepartments();
        $this->makeUser('super_admin');

        $this->artisan('access:sync-department-defaults --apply')
            ->expectsOutputToContain('Writes performed                : 28')
            ->assertExitCode(0);

        $this->assertSame(28, DepartmentPageDefault::query()->count());

        $slugs = Department::query()->pluck('slug', 'id');
        $persisted = [];
        foreach (DepartmentPageDefault::query()->get() as $row) {
            $persisted[] = $slugs[$row->department_id].'|'.$row->audience->value.'|'.$row->page_key;
        }

        $expected = [];
        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            foreach ($audiences as $audience => $keys) {
                foreach ($keys as $key) {
                    $expected[] = "{$slug}|{$audience}|{$key}";
                }
            }
        }

        sort($persisted);
        sort($expected);
        $this->assertSame($expected, $persisted);
    }

    public function test_every_written_row_is_a_leader_row_with_provenance(): void
    {
        $this->seedDepartments();
        $actor = $this->makeUser('super_admin');

        $this->artisan('access:sync-department-defaults --apply')->assertExitCode(0);

        $this->assertSame(28, DepartmentPageDefault::query()->count());
        $this->assertSame(
            28,
            DepartmentPageDefault::query()->where('audience', DepartmentPageAudience::Leader->value)->count(),
            'The canonical mapping is LEADER-only.'
        );
        $this->assertSame(
            0,
            DepartmentPageDefault::query()->where('audience', DepartmentPageAudience::Member->value)->count(),
        );
        $this->assertSame(
            28,
            DepartmentPageDefault::query()->where('created_by', $actor->id)->count(),
            'created_by is the canonical provenance column.'
        );
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    public function test_second_apply_is_idempotent(): void
    {
        $this->seedDepartments();
        $this->makeUser('super_admin');

        $this->artisan('access:sync-department-defaults --apply')->assertExitCode(0);

        $before = DepartmentPageDefault::query()
            ->orderBy('id')->get(['department_id', 'page_key', 'audience', 'created_at'])->toArray();

        $this->artisan('access:sync-department-defaults --apply')
            ->expectsOutputToContain('Would insert                    : 0')
            ->expectsOutputToContain('Already present                 : 28')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(0);

        $after = DepartmentPageDefault::query()
            ->orderBy('id')->get(['department_id', 'page_key', 'audience', 'created_at'])->toArray();

        $this->assertSame(28, DepartmentPageDefault::query()->count());
        $this->assertSame($before, $after, 'A second apply must not churn a single row.');
    }

    // ── Additive: custom rows survive ────────────────────────────────────────

    public function test_a_custom_row_is_preserved_by_the_apply(): void
    {
        $this->seedDepartments();
        $actor = $this->makeUser('super_admin');

        $dept = Department::where('slug', 'finance')->firstOrFail();
        app(DepartmentPageDefaultService::class)->replaceDefaults(
            $dept, DepartmentPageAudience::Member, ['operations.weekly_reports'], $actor,
        );

        $custom = DepartmentPageDefault::query()->firstOrFail();
        $customCreatedAt = $custom->created_at;

        $this->artisan('access:sync-department-defaults --apply')
            ->expectsOutputToContain('Writes performed                : 28')
            ->assertExitCode(0);

        $this->assertSame(29, DepartmentPageDefault::query()->count(), '28 canonical + 1 custom.');

        $stillThere = DepartmentPageDefault::query()->find($custom->id);
        $this->assertNotNull($stillThere, 'The custom row must survive.');
        $this->assertSame('operations.weekly_reports', $stillThere->page_key);
        $this->assertEquals($customCreatedAt, $stillThere->created_at, 'No churn on the custom row.');
    }

    // ── Blocking conditions abort the whole apply ────────────────────────────

    public function test_a_conflict_aborts_the_apply_before_any_write(): void
    {
        $this->seedDepartments();
        $this->makeUser('super_admin');

        $dept = Department::where('slug', 'operations')->firstOrFail();

        // Plant a drifted row the service itself would refuse.
        DepartmentPageDefault::create([
            'department_id' => $dept->id,
            'page_key' => 'administration.roles', // SYSTEM_PROTECTED
            'audience' => DepartmentPageAudience::Leader->value,
        ]);

        $this->artisan('access:sync-department-defaults --apply')
            ->expectsOutputToContain('ABORTED before writing')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(1);

        $this->assertSame(1, DepartmentPageDefault::query()->count(), 'Only the planted row may exist.');
        $this->assertSame('administration.roles', DepartmentPageDefault::query()->first()->page_key);
    }

    public function test_an_invalid_department_aborts_the_apply(): void
    {
        foreach (array_diff(self::SLUGS, ['technology']) as $slug) {
            $this->makeDepartment($slug);
        }
        $this->makeUser('super_admin');

        $this->artisan('access:sync-department-defaults --apply')
            ->expectsOutputToContain('ABORTED before writing')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(1);

        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    public function test_an_invalid_page_key_aborts_the_apply(): void
    {
        $this->seedDepartments();
        $this->makeUser('super_admin');

        $command = new class extends SyncDepartmentDefaultsCommand
        {
            protected function canonicalMap(): array
            {
                return ['technology' => ['member' => [], 'leader' => ['does.not.exist']]];
            }
        };
        $command->setLaravel($this->app);
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

        $this->artisan('access:sync-department-defaults --apply')
            ->expectsOutputToContain('ABORTED before writing')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(1);

        $this->assertSame(0, DepartmentPageDefault::query()->count());
    }

    // ── Transaction rollback ─────────────────────────────────────────────────

    public function test_a_mid_apply_failure_rolls_the_whole_transaction_back(): void
    {
        $this->seedDepartments();
        $this->makeUser('super_admin');

        $command = new class extends SyncDepartmentDefaultsCommand
        {
            public int $calls = 0;

            protected function applyPair(
                DepartmentPageDefaultService $service,
                Department $department,
                DepartmentPageAudience $audience,
                string $pageKey,
                ?User $actor,
            ): array {
                $this->calls++;

                if ($this->calls > 20) {
                    throw new RuntimeException('deterministic mid-transaction failure');
                }

                return $service->addDefaults($department, $audience, [$pageKey], $actor);
            }
        };
        $command->setLaravel($this->app);
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);

        $this->artisan('access:sync-department-defaults --apply')
            ->expectsOutputToContain('APPLY FAILED')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(1);

        $this->assertSame(
            0, DepartmentPageDefault::query()->count(),
            '20 rows had already been inserted inside the transaction and must all be gone.'
        );
        $this->assertGreaterThan(20, $command->calls, 'The failure must fire after real inserts.');
    }

    // ── Protection ───────────────────────────────────────────────────────────

    public function test_the_executor_cannot_store_a_protected_page(): void
    {
        $dept = $this->makeDepartment('technology');
        $actor = $this->makeUser('super_admin');

        $protected = array_values(array_map(fn (PageAccessEntry $e) => $e->key, array_filter(
            PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->isProtected()
        )))[0];

        $this->expectException(InvalidArgumentException::class);

        app(DepartmentPageDefaultService::class)
            ->addDefaults($dept, DepartmentPageAudience::Leader, [$protected], $actor);
    }

    public function test_no_protected_or_deferred_page_is_ever_stored(): void
    {
        $this->seedDepartments();
        $this->makeUser('super_admin');

        $this->artisan('access:sync-department-defaults --apply')->assertExitCode(0);

        $stored = DepartmentPageDefault::query()->pluck('page_key')->unique()->all();

        foreach ($stored as $key) {
            $entry = PageAccessCatalog::find($key);
            $this->assertNotNull($entry);
            $this->assertFalse($entry->isProtected(), "[{$key}] is SYSTEM_PROTECTED.");
            $this->assertNotContains($key, DepartmentDefaultMap::DEFERRED_PRODUCT_DECISION);
            $this->assertNotContains($key, DepartmentDefaultMap::INTENTIONAL_SYSTEM_PROTECTED);
        }
    }

    // ── Inactive departments ─────────────────────────────────────────────────

    public function test_an_inactive_department_receives_no_rows(): void
    {
        foreach (array_diff(self::SLUGS, ['technology']) as $slug) {
            $this->makeDepartment($slug);
        }
        $archived = $this->makeDepartment('technology', 'archived');
        $this->makeUser('super_admin');

        $this->artisan('access:sync-department-defaults --apply')
            ->expectsOutputToContain('Writes performed                : 26')
            ->assertExitCode(0);

        $this->assertSame(26, DepartmentPageDefault::query()->count());
        $this->assertSame(
            0,
            DepartmentPageDefault::query()->where('department_id', $archived->id)->count(),
            'An archived department must receive nothing.'
        );
    }

    // ── Blast radius ─────────────────────────────────────────────────────────

    public function test_only_department_page_defaults_is_modified(): void
    {
        $this->seedDepartments();
        $actor = $this->makeUser('super_admin');

        $role = Role::firstOrCreate(['name' => 'volunteer'], ['display_name' => 'volunteer', 'is_system' => false]);
        RolePageDefault::create(['role_id' => $role->id, 'page_key' => 'operations.meeting_lounge']);

        $target = $this->makeUser('student');
        app(UserPageAccessOverrideService::class)->replaceOverrides($actor, $target, ['personal.student_learning'], []);

        $before = [
            'roles' => Role::query()->count(),
            'users' => User::query()->count(),
            'departments' => Department::query()->count(),
            'role_defaults' => RolePageDefault::query()->count(),
            'overrides' => DB::table('user_page_access_overrides')->count(),
        ];

        $this->artisan('access:sync-department-defaults --apply')->assertExitCode(0);

        $this->assertSame($before['roles'], Role::query()->count());
        $this->assertSame($before['users'], User::query()->count());
        $this->assertSame($before['departments'], Department::query()->count());
        $this->assertSame($before['role_defaults'], RolePageDefault::query()->count());
        $this->assertSame($before['overrides'], DB::table('user_page_access_overrides')->count());
        $this->assertSame(28, DepartmentPageDefault::query()->count());
    }

    public function test_department_scoping_limits_the_apply(): void
    {
        $this->seedDepartments();
        $this->makeUser('super_admin');

        $this->artisan('access:sync-department-defaults --apply --department=technology')
            ->expectsOutputToContain('Writes performed                : 2')
            ->assertExitCode(0);

        $this->assertSame(2, DepartmentPageDefault::query()->count());

        $tech = Department::where('slug', 'technology')->firstOrFail();
        $this->assertSame(2, DepartmentPageDefault::query()->where('department_id', $tech->id)->count());
    }
}
