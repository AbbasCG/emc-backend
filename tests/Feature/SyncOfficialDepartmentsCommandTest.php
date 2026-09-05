<?php

namespace Tests\Feature;

use App\Models\Department;
use Database\Seeders\OfficialDepartmentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan departments:sync-official` — the production-safe alternative
 * to re-running OfficialDepartmentsSeeder (which uses updateOrCreate and
 * would overwrite every one of the 13 departments' metadata on every run,
 * even ones an admin has since customized). This command only ever INSERTS
 * rows that don't exist yet; it never updates an existing row, and it never
 * assigns leader_id.
 *
 * Exercises the exact production scenario: ai-transformation (إدارة الذكاء
 * الاصطناعي والتحول الرقمي) confirmed missing from production.
 */
class SyncOfficialDepartmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_creates_no_departments(): void
    {
        $this->assertSame(0, Department::count());

        $this->artisan('departments:sync-official')->assertSuccessful();

        $this->assertSame(0, Department::count());
    }

    public function test_dry_run_detects_missing_ai_transformation(): void
    {
        // Every OTHER official department already exists — only the AI one is missing,
        // matching the exact production state that was verified.
        foreach (OfficialDepartmentsSeeder::DEPARTMENTS as $slug => [$nameAr, $nameEn, $color, $icon, $sort]) {
            if ($slug === 'ai-transformation') {
                continue;
            }
            Department::create([
                'name' => $nameAr, 'name_ar' => $nameAr, 'name_en' => $nameEn, 'slug' => $slug,
                'color' => $color, 'icon' => $icon, 'sort_order' => $sort, 'status' => 'active',
            ]);
        }
        $this->assertFalse(Department::where('slug', 'ai-transformation')->exists());

        $this->artisan('departments:sync-official')
            ->expectsOutputToContain('Would create 1 department')
            ->assertSuccessful();

        // Still absent — dry run made no writes.
        $this->assertFalse(Department::where('slug', 'ai-transformation')->exists());
    }

    public function test_apply_inserts_the_missing_ai_transformation_department(): void
    {
        foreach (OfficialDepartmentsSeeder::DEPARTMENTS as $slug => [$nameAr, $nameEn, $color, $icon, $sort]) {
            if ($slug === 'ai-transformation') {
                continue;
            }
            Department::create([
                'name' => $nameAr, 'name_ar' => $nameAr, 'name_en' => $nameEn, 'slug' => $slug,
                'color' => $color, 'icon' => $icon, 'sort_order' => $sort, 'status' => 'active',
            ]);
        }

        $this->artisan('departments:sync-official', ['--apply' => true])
            ->expectsOutputToContain('Created 1 department')
            ->assertSuccessful();

        $dept = Department::where('slug', 'ai-transformation')->first();
        $this->assertNotNull($dept);
        $this->assertSame('إدارة الذكاء الاصطناعي والتحول الرقمي', $dept->name_ar);
        $this->assertSame('AI & Digital Transformation', $dept->name_en);
        $this->assertSame('active', $dept->status);
    }

    public function test_apply_never_overwrites_a_customized_existing_department(): void
    {
        $existing = Department::create([
            'name' => 'Custom Name', 'name_ar' => 'اسم مخصص من الإدارة', 'name_en' => 'Custom Name',
            'slug' => 'finance', 'color' => '#ABCDEF', 'icon' => 'custom-icon', 'sort_order' => 999, 'status' => 'active',
        ]);

        $this->artisan('departments:sync-official', ['--apply' => true])->assertSuccessful();

        $this->assertSame('اسم مخصص من الإدارة', $existing->fresh()->name_ar);
        $this->assertSame('#ABCDEF', $existing->fresh()->color);
        $this->assertSame(999, $existing->fresh()->sort_order);
    }

    public function test_command_is_idempotent(): void
    {
        $this->artisan('departments:sync-official', ['--apply' => true])->assertSuccessful();
        $countAfterFirstApply = Department::count();

        $this->artisan('departments:sync-official')
            ->expectsOutputToContain('No missing canonical official departments')
            ->assertSuccessful();

        $this->artisan('departments:sync-official', ['--apply' => true])->assertSuccessful();

        $this->assertSame($countAfterFirstApply, Department::count());
        $this->assertSame(count(OfficialDepartmentsSeeder::DEPARTMENTS), Department::count());
    }

    public function test_leader_id_remains_null_after_creating_ai_transformation(): void
    {
        $this->artisan('departments:sync-official', ['--apply' => true])->assertSuccessful();

        $dept = Department::where('slug', 'ai-transformation')->first();
        $this->assertNull($dept->leader_id);

        // Confirm no leadership is inferred from role — DepartmentAccessService
        // must see zero led departments for a fresh ai_manager user.
        $aiManager = \App\Models\User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);
        $svc = app(\App\Services\DepartmentAccessService::class);
        $this->assertFalse($svc->canManageDepartment($aiManager, $dept->id));
    }
}
