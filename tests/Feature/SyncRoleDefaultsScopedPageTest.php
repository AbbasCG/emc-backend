<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\User;
use App\Support\PageAccess\RoleDefaultMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * access:sync-role-defaults --page=<key> — a scoped sync of ONE page.
 *
 * Built around the real production shape that motivated it: the full plan
 * would insert 115 unrelated mappings, while only operations.impact_points
 * needed fixing. The scoped run must touch that page and nothing else, while
 * keeping every existing safety rule of the command.
 */
class SyncRoleDefaultsScopedPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'operations.impact_points';

    /** Roles that already hold the impact-points row in production. */
    private const ALREADY_PRESENT = [
        'admin', 'hr_manager', 'operations_manager', 'partnerships_manager', 'support_agent',
    ];

    /** Unrelated rows production already holds, to prove they stay untouched. */
    private const UNRELATED_PRESENT = [
        ['admin', 'finance.program_approvals'],
        ['hr_manager', 'operations.volunteers_management'],
    ];

    private function seedProductionShape(): void
    {
        foreach (RoleDefaultMap::roles() as $name) {
            Role::firstOrCreate(['name' => $name], ['display_name' => $name, 'is_system' => true]);
        }
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'is_system' => true]);
        User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        foreach (self::ALREADY_PRESENT as $role) {
            $this->row($role, self::PAGE);
        }
        foreach (self::UNRELATED_PRESENT as [$role, $key]) {
            $this->row($role, $key);
        }
    }

    private function row(string $role, string $key): RolePageDefault
    {
        return RolePageDefault::create([
            'role_id' => Role::where('name', $role)->value('id'),
            'page_key' => $key,
        ]);
    }

    private function pageRows(): int
    {
        return RolePageDefault::where('page_key', self::PAGE)->count();
    }

    private function otherRows(): int
    {
        return RolePageDefault::where('page_key', '!=', self::PAGE)->count();
    }

    /** Roles the canonical map grants the page to — the scoped universe. */
    private function mappedRoles(): array
    {
        return array_keys(array_filter(RoleDefaultMap::all(), fn (array $keys) => in_array(self::PAGE, $keys, true)));
    }

    public function test_the_canonical_map_matches_the_expected_production_plan(): void
    {
        $this->assertCount(16, $this->mappedRoles());
        $this->assertCount(11, array_diff($this->mappedRoles(), self::ALREADY_PRESENT));
    }

    public function test_scoped_dry_run_writes_nothing_and_reports_only_the_page(): void
    {
        $this->seedProductionShape();
        $before = RolePageDefault::count();

        $this->artisan('access:sync-role-defaults', ['--page' => self::PAGE, '--dry-run' => true])
            ->expectsOutputToContain('Mappings evaluated              : 16')
            ->expectsOutputToContain('Would insert                    : 11')
            ->expectsOutputToContain('Already present                 : 5')
            ->expectsOutputToContain('Conflicts                       : 0')
            ->expectsOutputToContain('Writes performed                : 0')
            ->doesntExpectOutputToContain('finance.program_approvals')
            ->doesntExpectOutputToContain('lms.')
            ->doesntExpectOutputToContain('administration.')
            ->assertExitCode(0);

        $this->assertSame($before, RolePageDefault::count());
    }

    public function test_scoped_apply_inserts_only_the_missing_page_mappings(): void
    {
        $this->seedProductionShape();
        $otherBefore = $this->otherRows();

        $this->artisan('access:sync-role-defaults', ['--page' => self::PAGE, '--apply' => true])
            ->expectsOutputToContain('Writes performed                : 11')
            ->assertExitCode(0);

        $this->assertSame(16, $this->pageRows());

        $stored = RolePageDefault::where('page_key', self::PAGE)->with('role')->get()->pluck('role.name')->sort()->values()->all();
        $expected = $this->mappedRoles();
        sort($expected);
        $this->assertSame($expected, $stored);

        // Unrelated missing mappings stay missing; unrelated present rows stay.
        $this->assertSame($otherBefore, $this->otherRows());
        $this->assertSame(0, RolePageDefault::where('page_key', 'lms.programs')->count());
    }

    public function test_existing_page_rows_are_left_untouched(): void
    {
        $this->seedProductionShape();
        $existing = RolePageDefault::where('page_key', self::PAGE)->orderBy('id')->get(['id', 'role_id', 'created_by', 'updated_by', 'created_at', 'updated_at'])->toArray();

        $this->travel(5)->minutes();
        $this->artisan('access:sync-role-defaults', ['--page' => self::PAGE, '--apply' => true])->assertExitCode(0);

        $after = RolePageDefault::whereIn('id', array_column($existing, 'id'))->orderBy('id')->get(['id', 'role_id', 'created_by', 'updated_by', 'created_at', 'updated_at'])->toArray();
        $this->assertSame($existing, $after);
    }

    public function test_second_scoped_apply_is_idempotent(): void
    {
        $this->seedProductionShape();

        $this->artisan('access:sync-role-defaults', ['--page' => self::PAGE, '--apply' => true])->assertExitCode(0);
        $total = RolePageDefault::count();

        $this->artisan('access:sync-role-defaults', ['--page' => self::PAGE, '--apply' => true])
            ->expectsOutputToContain('Already present                 : 16')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(0);

        $this->artisan('access:sync-role-defaults', ['--page' => self::PAGE, '--dry-run' => true])
            ->expectsOutputToContain('Would insert                    : 0')
            ->assertExitCode(0);

        $this->assertSame($total, RolePageDefault::count());
    }

    public function test_an_unknown_page_key_fails_safely_with_no_writes(): void
    {
        $this->seedProductionShape();
        $before = RolePageDefault::count();

        foreach ([['--dry-run' => true], ['--apply' => true]] as $mode) {
            $this->artisan('access:sync-role-defaults', ['--page' => 'operations.impact_pointz'] + $mode)
                ->expectsOutputToContain('Unknown page key [operations.impact_pointz]')
                ->expectsOutputToContain('Writes performed                : 0')
                ->assertExitCode(1);
        }

        $this->assertSame($before, RolePageDefault::count());
    }

    public function test_a_page_the_map_never_grants_plans_nothing(): void
    {
        // A real but unmapped key (root-only / protected) is valid input and
        // simply yields an empty plan — protection is never bypassed.
        $this->seedProductionShape();
        $before = RolePageDefault::count();

        $this->artisan('access:sync-role-defaults', ['--page' => 'operations.board', '--apply' => true])
            ->expectsOutputToContain('Mappings evaluated              : 0')
            ->expectsOutputToContain('Writes performed                : 0')
            ->assertExitCode(0);

        $this->assertSame($before, RolePageDefault::count());
    }

    public function test_page_and_role_filters_combine(): void
    {
        $this->seedProductionShape();

        $this->artisan('access:sync-role-defaults', ['--page' => self::PAGE, '--role' => 'volunteer', '--apply' => true])
            ->expectsOutputToContain('Writes performed                : 1')
            ->assertExitCode(0);

        $this->assertSame(6, $this->pageRows());
    }

    public function test_a_drifted_row_elsewhere_still_blocks_a_scoped_apply(): void
    {
        $this->seedProductionShape();
        $this->row('admin', 'no.such.page');
        $before = $this->pageRows();

        $this->artisan('access:sync-role-defaults', ['--page' => self::PAGE, '--apply' => true])
            ->expectsOutputToContain('ABORTED before writing')
            ->assertExitCode(1);

        $this->assertSame($before, $this->pageRows());
    }
}
