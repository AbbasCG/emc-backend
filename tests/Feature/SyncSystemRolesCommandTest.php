<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan roles:sync-system` — the production-safe alternative to
 * re-running the full RoleSeeder (which uses updateOrCreate and would
 * overwrite any role an admin has since customized via RoleController).
 * This command only ever INSERTS rows that don't exist yet; it never
 * updates an existing row.
 */
class SyncSystemRolesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_creates_no_roles(): void
    {
        $this->assertSame(0, Role::count());

        $this->artisan('roles:sync-system')->assertSuccessful();

        $this->assertSame(0, Role::count());
    }

    public function test_apply_inserts_every_missing_canonical_role(): void
    {
        $this->artisan('roles:sync-system', ['--apply' => true])->assertSuccessful();

        $this->assertSame(count(RoleSeeder::ROLES), Role::count());
        $this->assertTrue(Role::where('name', 'ai_manager')->exists());
    }

    public function test_apply_never_overwrites_a_customized_existing_role(): void
    {
        Role::create([
            'name' => 'finance_manager',
            'display_name' => 'Custom Finance Title — set by an admin',
            'description' => 'Custom description',
            'is_system' => true,
        ]);

        $this->artisan('roles:sync-system', ['--apply' => true])->assertSuccessful();

        $this->assertSame(
            'Custom Finance Title — set by an admin',
            Role::where('name', 'finance_manager')->value('display_name'),
        );
    }

    public function test_apply_only_creates_the_missing_role_when_others_already_exist(): void
    {
        foreach (RoleSeeder::ROLES as $roleData) {
            if ($roleData['name'] === 'ai_manager') {
                continue; // Simulate the exact production gap.
            }
            Role::create($roleData);
        }
        $this->assertFalse(Role::where('name', 'ai_manager')->exists());

        $this->artisan('roles:sync-system', ['--apply' => true])
            ->expectsOutputToContain('Created 1 role')
            ->assertSuccessful();

        $this->assertTrue(Role::where('name', 'ai_manager')->exists());
        $this->assertSame(count(RoleSeeder::ROLES), Role::count());
    }

    public function test_command_is_idempotent(): void
    {
        $this->artisan('roles:sync-system', ['--apply' => true])->assertSuccessful();
        $countAfterFirstApply = Role::count();

        $this->artisan('roles:sync-system', ['--apply' => true])
            ->expectsOutputToContain('No missing canonical system roles')
            ->assertSuccessful();

        $this->assertSame($countAfterFirstApply, Role::count());
    }

    public function test_ai_manager_assignable_after_sync(): void
    {
        $this->artisan('roles:sync-system', ['--apply' => true])->assertSuccessful();

        $this->assertContains('ai_manager', Role::assignableSlugs());
    }
}
