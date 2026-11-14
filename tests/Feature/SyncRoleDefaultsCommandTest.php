<?php

namespace Tests\Feature;

use App\Console\Commands\SyncRoleDefaultsCommand;
use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\User;
use App\Services\RolePageDefaultService;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\RoleDefaultMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 2G.3B.1 — the SAFE --apply path.
 *
 * Every write in this file happens in an ISOLATED in-memory SQLite database
 * (RefreshDatabase + phpunit.xml's sqlite/:memory:). The persistent development
 * database is never touched.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: applying a role default decides which
 * dashboard pages open by default. It grants no permission to anyone.
 */
class SyncRoleDefaultsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedCanonicalRoles(): void
    {
        foreach (RoleDefaultMap::roles() as $name) {
            Role::firstOrCreate(['name' => $name], ['display_name' => $name, 'is_system' => true]);
        }

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'is_system' => true]);
        User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function rows(): int
    {
        return RolePageDefault::query()->count();
    }

    // ── 17/18. Read-only modes write nothing ─────────────────────────────────

    public function test_no_flag_writes_zero_rows(): void
    {
        $this->seedCanonicalRoles();

        $this->artisan('access:sync-role-defaults')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(0);

        $this->assertSame(0, $this->rows());
    }

    public function test_dry_run_writes_zero_rows_and_plans_every_pair(): void
    {
        $this->seedCanonicalRoles();

        $this->artisan('access:sync-role-defaults', ['--dry-run' => true])
            ->expectsOutputToContain('Would insert                    : '.RoleDefaultMap::pairCount())
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(0);

        $this->assertSame(0, $this->rows());
        $this->assertSame(193, RoleDefaultMap::pairCount());
    }

    // ── 19. First apply ──────────────────────────────────────────────────────

    public function test_first_apply_inserts_every_canonical_pair(): void
    {
        $this->seedCanonicalRoles();

        $this->artisan('access:sync-role-defaults', ['--apply' => true])
            ->expectsOutputToContain('Writes performed                : 193')
            ->assertExitCode(0);

        $this->assertSame(193, $this->rows());

        // Every stored row belongs to the canonical map — no stranger crept in.
        $map = RoleDefaultMap::all();
        foreach (RolePageDefault::query()->with('role')->get() as $row) {
            $roleName = $row->role->name;
            $this->assertArrayHasKey($roleName, $map, "unexpected role [{$roleName}] in stored rows");
            $this->assertContains($row->page_key, $map[$roleName], "unexpected pair [{$roleName}/{$row->page_key}]");
        }

        // And every canonical pair is present — nothing was dropped.
        foreach ($map as $roleName => $keys) {
            $role = Role::where('name', $roleName)->first();
            $stored = RolePageDefault::where('role_id', $role->id)->pluck('page_key')->all();
            sort($stored);
            $expected = $keys;
            sort($expected);
            $this->assertSame($expected, $stored, "[{$roleName}] stored set must equal its canonical set");
        }
    }

    /**
     * "State = ALLOW" in this schema is the ROW'S EXISTENCE: role_page_defaults
     * is positive-only and has no state/audience column, so a stored row IS an
     * allow and a DENY is not representable. Asserted structurally.
     */
    public function test_every_written_row_is_a_positive_allow_with_provenance(): void
    {
        $this->seedCanonicalRoles();
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);

        $columns = array_keys((array) DB::table('role_page_defaults')->first());

        $this->assertNotContains('state', $columns, 'schema is positive-only — no state column may exist');
        $this->assertNotContains('audience', $columns);
        $this->assertContains('created_by', $columns);

        $actor = User::where('role', 'super_admin')->first();
        $this->assertSame(193, RolePageDefault::where('created_by', $actor->id)->count());

        // Identity is role + page_key; no route string is ever stored.
        foreach (RolePageDefault::query()->pluck('page_key') as $key) {
            $this->assertStringNotContainsString('/dashboard', $key);
            $this->assertNotNull(PageAccessCatalog::find($key));
        }
    }

    // ── 20. Second apply is idempotent ───────────────────────────────────────

    public function test_second_apply_is_idempotent(): void
    {
        $this->seedCanonicalRoles();
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);
        $this->assertSame(193, $this->rows());

        $before = RolePageDefault::query()->orderBy('id')->pluck('page_key')->all();

        $this->artisan('access:sync-role-defaults', ['--apply' => true])
            ->expectsOutputToContain('Planned inserts                 : 0')
            ->expectsOutputToContain('Already present                 : 193')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(0);

        $this->assertSame(193, $this->rows());
        $this->assertSame($before, RolePageDefault::query()->orderBy('id')->pluck('page_key')->all());
    }

    // ── 21. Custom rows are preserved — NO PRUNE ─────────────────────────────

    public function test_apply_preserves_a_custom_row_absent_from_the_map(): void
    {
        $this->seedCanonicalRoles();

        // An eligible page deliberately NOT in the canonical map for this role.
        $custom = 'operations.weekly_reports';
        $this->assertNotContains($custom, RoleDefaultMap::forRole('volunteer'));

        $role = Role::where('name', 'volunteer')->first();
        $actor = User::where('role', 'super_admin')->first();
        app(RolePageDefaultService::class)->addDefaults($role, [$custom], $actor);

        $customRow = RolePageDefault::where('role_id', $role->id)->where('page_key', $custom)->first();
        $this->assertNotNull($customRow);

        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);

        // Canonical rows added alongside it, custom row untouched.
        $this->assertSame(194, $this->rows(), '193 canonical + 1 pre-existing custom row');

        $after = RolePageDefault::find($customRow->id);
        $this->assertNotNull($after, 'the custom row must not be deleted');
        $this->assertSame($custom, $after->page_key);
        $this->assertSame($customRow->created_at->toString(), $after->created_at->toString(), 'no churn');
    }

    // ── 22. Conflict aborts the whole apply ──────────────────────────────────

    public function test_a_drifted_stored_row_aborts_the_apply_before_any_write(): void
    {
        $this->seedCanonicalRoles();

        // A stored row whose page is SYSTEM_PROTECTED — impossible to create via
        // the service, so it is written raw to simulate drift/legacy data.
        $protected = collect(PageAccessCatalog::all())->first(fn (PageAccessEntry $e) => $e->isProtected());
        $role = Role::where('name', 'admin')->first();

        DB::table('role_page_defaults')->insert([
            'role_id' => $role->id, 'page_key' => $protected->key,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->rows());

        $this->artisan('access:sync-role-defaults', ['--apply' => true])
            ->expectsOutputToContain('ABORTED before writing')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(1);

        // Nothing inserted, and the conflicting row is untouched — no prune.
        $this->assertSame(1, $this->rows());
        $this->assertDatabaseHas('role_page_defaults', ['role_id' => $role->id, 'page_key' => $protected->key]);
    }

    // ── 23/24. Malformed plan input aborts ───────────────────────────────────

    public function test_an_invalid_role_aborts_the_apply(): void
    {
        $this->seedCanonicalRoles();

        $this->app->bind(SyncRoleDefaultsCommand::class, fn () => new class extends SyncRoleDefaultsCommand
        {
            protected function canonicalMap(): array
            {
                return ['role_that_does_not_exist' => ['finance.dashboard']];
            }
        });

        $this->artisan('access:sync-role-defaults', ['--apply' => true])
            ->expectsOutputToContain('Invalid roles                   : 1')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(1);

        $this->assertSame(0, $this->rows());
    }

    public function test_an_invalid_page_key_aborts_the_apply(): void
    {
        $this->seedCanonicalRoles();

        $this->app->bind(SyncRoleDefaultsCommand::class, fn () => new class extends SyncRoleDefaultsCommand
        {
            protected function canonicalMap(): array
            {
                return ['admin' => ['not.a.real.page']];
            }
        });

        $this->artisan('access:sync-role-defaults', ['--apply' => true])
            ->expectsOutputToContain('Invalid page keys               : 1')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(1);

        $this->assertSame(0, $this->rows());
    }

    // ── 25. Transaction rollback ─────────────────────────────────────────────

    public function test_a_failure_mid_apply_rolls_the_whole_transaction_back(): void
    {
        $this->seedCanonicalRoles();

        $this->app->bind(SyncRoleDefaultsCommand::class, fn () => new class extends SyncRoleDefaultsCommand
        {
            private int $calls = 0;

            protected function applyPair(RolePageDefaultService $service, Role $role, string $pageKey, ?User $actor): array
            {
                $this->calls++;

                if ($this->calls > 50) {
                    throw new RuntimeException('deterministic mid-transaction failure');
                }

                return $service->addDefaults($role, [$pageKey], $actor);
            }
        });

        $this->artisan('access:sync-role-defaults', ['--apply' => true])
            ->expectsOutputToContain('APPLY FAILED')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(1);

        // 50 inserts had already succeeded inside the transaction — none survive.
        $this->assertSame(0, $this->rows(), 'no partial state may remain after rollback');
    }

    // ── 26. Protected / baseline / deferred defence in depth ─────────────────

    public function test_the_executor_cannot_store_a_protected_page_even_if_planned(): void
    {
        $this->seedCanonicalRoles();
        $protected = collect(PageAccessCatalog::all())->first(fn (PageAccessEntry $e) => $e->isProtected());

        $role = Role::where('name', 'admin')->first();
        $actor = User::where('role', 'super_admin')->first();

        // The canonical service is the last line of defence.
        $this->assertThrows(
            fn () => app(RolePageDefaultService::class)->addDefaults($role, [$protected->key], $actor),
            \InvalidArgumentException::class
        );

        $this->assertSame(0, $this->rows());
    }

    public function test_the_plan_never_contains_protected_baseline_or_deferred_inserts(): void
    {
        $this->seedCanonicalRoles();
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);

        $stored = RolePageDefault::query()->pluck('page_key')->unique();

        foreach ($stored as $key) {
            $entry = PageAccessCatalog::find($key);
            $this->assertFalse($entry->isProtected(), "[{$key}] protected page was stored");
            $this->assertFalse($entry->authenticatedBaseline, "[{$key}] baseline page was stored");
            $this->assertNotContains($key, RoleDefaultMap::DEFERRED_PRODUCT_DECISION);
            $this->assertNotContains($key, RoleDefaultMap::LEADER_OWNED);
        }
    }

    public function test_root_roles_receive_no_rows(): void
    {
        $this->seedCanonicalRoles();
        Role::firstOrCreate(['name' => 'tech_admin'], ['display_name' => 'Tech Admin', 'is_system' => true]);

        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);

        foreach (RoleDefaultMap::ROOT_ROLES as $root) {
            $role = Role::where('name', $root)->first();
            $this->assertSame(0, RolePageDefault::where('role_id', $role->id)->count(), "[{$root}] must get no rows");
        }
    }

    // ── 27. Only role_page_defaults is touched ───────────────────────────────

    public function test_apply_touches_only_the_role_page_defaults_table(): void
    {
        $this->seedCanonicalRoles();

        $before = [
            'departments' => DB::table('department_page_defaults')->count(),
            'overrides' => DB::table('user_page_access_overrides')->count(),
            'users' => DB::table('users')->count(),
            'roles' => DB::table('roles')->count(),
        ];

        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);

        $this->assertSame($before['departments'], DB::table('department_page_defaults')->count());
        $this->assertSame($before['overrides'], DB::table('user_page_access_overrides')->count());
        $this->assertSame($before['users'], DB::table('users')->count());
        $this->assertSame($before['roles'], DB::table('roles')->count());
        $this->assertSame(193, $this->rows());
    }

    // ── --role scoping stays additive ────────────────────────────────────────

    public function test_role_option_limits_the_apply_to_one_role(): void
    {
        $this->seedCanonicalRoles();
        $expected = count(RoleDefaultMap::forRole('finance_manager'));

        $this->artisan('access:sync-role-defaults', ['--apply' => true, '--role' => 'finance_manager'])
            ->expectsOutputToContain('Writes performed                : '.$expected)
            ->assertExitCode(0);

        $this->assertSame($expected, $this->rows());
    }
}
