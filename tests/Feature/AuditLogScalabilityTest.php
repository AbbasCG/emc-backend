<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogScalabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // withoutAudit prevents factory creates from polluting the audit_logs table
        $this->superAdmin = User::withoutAudit(fn () => User::factory()->create(['role' => 'super_admin']));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeLogs(int $count, array $overrides = []): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = array_merge([
                'user_id'    => $this->superAdmin->id,
                'user_name'  => $this->superAdmin->name,
                'user_role'  => 'super_admin',
                'action'     => 'CREATE',
                'entity_type' => 'Course',
                'entity_id'  => $i + 1,
                'entity_name' => "Course {$i}",
                'created_at' => now()->subDays($i)->toDateTimeString(),
                'updated_at' => now()->subDays($i)->toDateTimeString(),
            ], $overrides);
        }
        DB::table('audit_logs')->insert($rows);
    }

    private function actingAsSuperAdmin(): static
    {
        return $this->actingAs($this->superAdmin, 'sanctum');
    }

    // ── Phase 2: Server-side pagination ──────────────────────────────────────

    /** @test */
    public function test_default_pagination_returns_50_records(): void
    {
        $this->makeLogs(75);

        $res = $this->actingAsSuperAdmin()
            ->getJson('/api/admin/audit-logs');

        $res->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 75)
            ->assertJsonPath('meta.current_page', 1);

        $this->assertCount(50, $res->json('data'));
    }

    /** @test */
    public function test_allowed_page_sizes_25_50_100_are_accepted(): void
    {
        $this->makeLogs(10);

        foreach ([25, 50, 100] as $size) {
            $res = $this->actingAsSuperAdmin()
                ->getJson("/api/admin/audit-logs?per_page={$size}");

            $res->assertOk()->assertJsonPath('meta.per_page', $size);
        }
    }

    /** @test */
    public function test_oversized_per_page_is_clamped_to_50(): void
    {
        $this->makeLogs(5);

        $res = $this->actingAsSuperAdmin()
            ->getJson('/api/admin/audit-logs?per_page=250');

        $res->assertOk()->assertJsonPath('meta.per_page', 50);
    }

    /** @test */
    public function test_records_sorted_newest_first_by_default(): void
    {
        $this->makeLogs(5);

        $res = $this->actingAsSuperAdmin()
            ->getJson('/api/admin/audit-logs');

        $dates = collect($res->json('data'))->pluck('created_at')->all();
        $this->assertEquals($dates, collect($dates)->sortDesc()->values()->all());
    }

    // ── Phase 3: API meta ─────────────────────────────────────────────────────

    /** @test */
    public function test_paginated_response_contains_meta_fields(): void
    {
        $this->makeLogs(3);

        $res = $this->actingAsSuperAdmin()
            ->getJson('/api/admin/audit-logs');

        $res->assertOk()
            ->assertJsonStructure(['meta' => ['total', 'current_page', 'last_page', 'per_page', 'from', 'to']]);
    }

    // ── Phase 6: Filters ──────────────────────────────────────────────────────

    /** @test */
    public function test_date_from_filter_excludes_older_logs(): void
    {
        // logs created 0, 1, 2 days ago
        $this->makeLogs(3);

        // Only logs from today should be returned (1 log)
        $cutoff = now()->toDateString();

        $res = $this->actingAsSuperAdmin()
            ->getJson("/api/admin/audit-logs?date_from={$cutoff}");

        $res->assertOk();
        $this->assertEquals(1, $res->json('meta.total'));
    }

    /** @test */
    public function test_action_filter_scopes_results(): void
    {
        $this->makeLogs(3, ['action' => 'CREATE']);
        $this->makeLogs(2, ['action' => 'DELETE']);

        $res = $this->actingAsSuperAdmin()
            ->getJson('/api/admin/audit-logs?action=DELETE');

        $res->assertOk();
        $this->assertEquals(2, $res->json('meta.total'));
        $this->assertCount(2, $res->json('data'));
    }

    /** @test */
    public function test_entity_type_filter_scopes_results(): void
    {
        $this->makeLogs(4, ['entity_type' => 'Course']);
        $this->makeLogs(2, ['entity_type' => 'User']);

        $res = $this->actingAsSuperAdmin()
            ->getJson('/api/admin/audit-logs?entity_type=User');

        $res->assertOk();
        $this->assertEquals(2, $res->json('meta.total'));
    }

    /** @test */
    public function test_global_search_matches_entity_type_and_description(): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $this->superAdmin->id,
            'user_name' => 'Test User',
            'action' => 'UPDATE',
            'entity_type' => 'Workshop',
            'entity_name' => 'My Workshop',
            'description' => 'Updated the workshop schedule',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->makeLogs(3, ['entity_type' => 'Course']);

        $res = $this->actingAsSuperAdmin()
            ->getJson('/api/admin/audit-logs?search=Workshop');

        $res->assertOk();
        $this->assertEquals(1, $res->json('meta.total'));
    }

    /** @test */
    public function test_user_id_filter_scopes_results(): void
    {
        $other = User::withoutAudit(fn () => User::factory()->create(['role' => 'admin']));
        $this->makeLogs(3, ['user_id' => $other->id]);
        $this->makeLogs(2, ['user_id' => $this->superAdmin->id]);

        $res = $this->actingAsSuperAdmin()
            ->getJson("/api/admin/audit-logs?user_id={$other->id}");

        $res->assertOk();
        $this->assertEquals(3, $res->json('meta.total'));
    }

    // ── Phase 7: Statistics ───────────────────────────────────────────────────

    /** @test */
    public function test_stats_endpoint_returns_aggregate_counts(): void
    {
        $this->makeLogs(5);

        $res = $this->actingAsSuperAdmin()
            ->getJson('/api/admin/audit-logs/stats');

        $res->assertOk()
            ->assertJsonStructure(['data' => [
                'total', 'today', 'this_week', 'this_month',
                'unique_users', 'failed_operations', 'successful_operations',
            ]]);

        $this->assertGreaterThanOrEqual(5, $res->json('data.total'));
    }

    // ── Phase 12: Detail endpoint ─────────────────────────────────────────────

    /** @test */
    public function test_detail_endpoint_returns_full_payload_including_old_new_values(): void
    {
        $id = DB::table('audit_logs')->insertGetId([
            'user_id'    => $this->superAdmin->id,
            'user_name'  => $this->superAdmin->name,
            'action'     => 'UPDATE',
            'entity_type' => 'Course',
            'old_values' => json_encode(['title' => 'Old']),
            'new_values' => json_encode(['title' => 'New']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->actingAsSuperAdmin()
            ->getJson("/api/admin/audit-logs/{$id}");

        $res->assertOk()
            ->assertJsonPath('data.old_values.title', 'Old')
            ->assertJsonPath('data.new_values.title', 'New');
    }

    /** @test */
    public function test_list_endpoint_does_not_expose_old_new_values(): void
    {
        DB::table('audit_logs')->insert([
            'user_id'    => $this->superAdmin->id,
            'user_name'  => $this->superAdmin->name,
            'action'     => 'UPDATE',
            'entity_type' => 'Course',
            'old_values' => json_encode(['title' => 'Old']),
            'new_values' => json_encode(['title' => 'New']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->actingAsSuperAdmin()
            ->getJson('/api/admin/audit-logs');

        $res->assertOk();
        $firstRow = $res->json('data.0');
        $this->assertArrayNotHasKey('old_values', $firstRow);
        $this->assertArrayNotHasKey('new_values', $firstRow);
    }

    // ── Phase 13: Permission ──────────────────────────────────────────────────

    /** @test */
    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/admin/audit-logs')
            ->assertUnauthorized();
    }

    /** @test */
    public function test_non_admin_user_gets_403(): void
    {
        $student = User::withoutAudit(fn () => User::factory()->create(['role' => 'student']));

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/audit-logs')
            ->assertForbidden();
    }

    // ── Phase 10: Archive command dry-run ─────────────────────────────────────

    /** @test */
    public function test_archive_dry_run_does_not_move_records(): void
    {
        $this->makeLogs(3);

        // Insert an old log that would be archived
        DB::table('audit_logs')->insert([
            'user_id'    => $this->superAdmin->id,
            'user_name'  => 'Old User',
            'action'     => 'DELETE',
            'entity_type' => 'Course',
            'created_at' => now()->subMonths(25)->toDateTimeString(),
            'updated_at' => now()->subMonths(25)->toDateTimeString(),
        ]);

        $before = DB::table('audit_logs')->count();
        $archiveBefore = DB::table('audit_logs_archive')->count();

        $this->artisan('audit-logs:archive --dry-run')->assertSuccessful();

        $this->assertEquals($before, DB::table('audit_logs')->count());
        $this->assertEquals($archiveBefore, DB::table('audit_logs_archive')->count());
    }
}
