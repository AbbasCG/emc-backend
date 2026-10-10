<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExpertApplication;
use App\Models\User;
use App\Services\EffectivePageAccessService;
use App\Support\PageAccess\PageAccessCatalog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AI EXPERT APPLICATIONS — /dashboard/admin/ai/expert-applications.
 *
 * PAGE ACCESS: its own capability, organizational_departments.ai_expert_applications
 * (root authority + admin and ai_manager role defaults).
 * BUSINESS AUTHORIZATION: the `admin/expert-applications` API group,
 * role:admin,super_admin,tech_admin,ai_manager.
 *
 * The two are pinned to the same audience for every canonical role, and the
 * status workflow is pinned to the single canonical vocabulary.
 */
class ExpertApplicationsAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'organizational_departments.ai_expert_applications';

    private const MANAGERS = ['super_admin', 'tech_admin', 'admin', 'ai_manager'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function application(array $attrs = []): ExpertApplication
    {
        $values = [];
        foreach (Schema::getColumns('expert_applications') as $col) {
            if ($col['nullable'] || $col['default'] !== null || $col['auto_increment']
                || in_array($col['name'], ['created_at', 'updated_at'], true)) {
                continue;
            }
            $values[$col['name']] = str_contains(strtolower($col['type_name'] ?? $col['type']), 'json') ? '[]' : 'x';
        }

        return ExpertApplication::forceCreate(array_merge($values, [
            'uuid' => (string) Str::uuid(),
            'status' => 'new',
            'full_name' => 'خبير تجريبي',
            'email' => 'expert'.Str::random(6).'@example.test',
            'primary_specialty' => 'الذكاء الاصطناعي',
        ], $attrs));
    }

    private function setStatus(User $user, ExpertApplication $app, string $status)
    {
        return $this->actingAs($user)->putJson("/api/admin/expert-applications/{$app->id}/status", ['status' => $status]);
    }

    // ── Page access == API, for every canonical role ─────────────────────────

    public function test_the_page_has_its_own_capability(): void
    {
        $entry = PageAccessCatalog::find(self::PAGE);
        $this->assertNotNull($entry);
        $this->assertSame('/dashboard/admin/ai/expert-applications', $entry->primaryRoute);
        $this->assertNotContains(
            '/dashboard/admin/ai/expert-applications',
            PageAccessCatalog::find('technical_system.ai_command_center')->routePatterns,
            'the intake must no longer ride on the technical AI platform capability',
        );
    }

    public function test_page_access_matches_the_api_for_every_canonical_role(): void
    {
        $app = $this->application();

        foreach (RoleSeeder::ROLES as $row) {
            $role = $row['name'];
            $user = $this->user($role);

            $page = app(EffectivePageAccessService::class)->canAccess($user, self::PAGE);
            $list = $this->actingAs($user)->getJson('/api/admin/expert-applications')->status();
            $show = $this->actingAs($user)->getJson("/api/admin/expert-applications/{$app->id}")->status();

            $this->assertSame($page, $list === 200, "[{$role}] page=".($page ? 'Y' : 'n')." list={$list}");
            $this->assertSame($list, $show, "[{$role}] list and show disagree");
            $this->assertSame(in_array($role, self::MANAGERS, true), $page, "[{$role}] unexpected audience");
        }
    }

    public function test_ai_manager_does_not_gain_the_technical_ai_platform(): void
    {
        $ai = $this->user('ai_manager');

        $this->assertTrue(app(EffectivePageAccessService::class)->canAccess($ai, self::PAGE));
        $this->assertFalse(app(EffectivePageAccessService::class)->canAccess($ai, 'technical_system.ai_command_center'));
        $this->actingAs($ai)->getJson('/api/admin/ai/usage')->assertForbidden();
    }

    public function test_unauthorized_roles_cannot_read_or_change_status(): void
    {
        $app = $this->application();

        foreach (['executive_admin', 'hr_manager', 'quality_manager', 'finance_manager', 'department_manager', 'instructor', 'volunteer', 'student', 'partner'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->getJson('/api/admin/expert-applications')->assertForbidden();
            $this->actingAs($user)->getJson("/api/admin/expert-applications/{$app->id}")->assertForbidden();
            $this->setStatus($user, $app, 'rejected')->assertForbidden();
        }

        $this->assertSame('new', $app->fresh()->status);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $app = $this->application();

        $this->getJson('/api/admin/expert-applications')->assertUnauthorized();
        $this->getJson("/api/admin/expert-applications/{$app->id}")->assertUnauthorized();
        $this->putJson("/api/admin/expert-applications/{$app->id}/status", ['status' => 'rejected'])->assertUnauthorized();
    }

    // ── Status workflow ──────────────────────────────────────────────────────

    public function test_review_then_approve(): void
    {
        $ai = $this->user('ai_manager');
        $app = $this->application();

        $this->setStatus($ai, $app, 'under_review')->assertOk();
        $this->setStatus($ai, $app, 'approved')->assertOk();

        $this->assertSame('approved', $app->fresh()->status);
        $this->assertSame($ai->id, (int) $app->fresh()->reviewed_by);
        $this->assertNotNull($app->fresh()->reviewed_at);
        $this->assertSame(2, AuditLog::where('action', 'EXPERT_APPLICATION_STATUS_CHANGED')->where('entity_id', $app->id)->count());
    }

    public function test_reject_directly_from_new(): void
    {
        foreach (self::MANAGERS as $role) {
            $app = $this->application();
            $this->setStatus($this->user($role), $app, 'rejected')->assertOk();
            $this->assertSame('rejected', $app->fresh()->status, $role);
        }
    }

    public function test_final_states_cannot_change(): void
    {
        $admin = $this->user('admin');

        foreach (['approved', 'rejected'] as $final) {
            $app = $this->application(['status' => $final]);
            foreach (['new', 'under_review', 'approved', 'rejected'] as $to) {
                $this->setStatus($admin, $app, $to)
                    ->assertStatus(422)
                    ->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
            }
            $this->assertSame($final, $app->fresh()->status);
        }
    }

    public function test_cannot_move_back_to_new(): void
    {
        $app = $this->application(['status' => 'under_review']);

        $this->setStatus($this->user('admin'), $app, 'new')->assertStatus(422);
        $this->assertSame('under_review', $app->fresh()->status);
    }

    public function test_the_retired_vocabulary_and_garbage_are_rejected(): void
    {
        $admin = $this->user('admin');
        $app = $this->application();

        foreach (['pending', 'reviewed', 'accepted', 'APPROVED', ''] as $bad) {
            $this->setStatus($admin, $app, $bad)->assertStatus(422);
        }

        $this->assertSame('new', $app->fresh()->status);
    }

    // ── Search / filter ──────────────────────────────────────────────────────

    public function test_list_filters_by_status_and_searches_name_email_specialty(): void
    {
        $admin = $this->user('admin');
        $this->application(['full_name' => 'سارة المهندسة', 'status' => 'new']);
        $this->application(['full_name' => 'Omar Data', 'email' => 'omar@example.test', 'status' => 'under_review']);
        $this->application(['full_name' => 'خالد', 'primary_specialty' => 'Cyber Security', 'status' => 'approved']);

        $this->assertSame(1, $this->actingAs($admin)->getJson('/api/admin/expert-applications?status=under_review')->json('total'));
        $this->assertSame(1, $this->actingAs($admin)->getJson('/api/admin/expert-applications?search=omar@')->json('total'));
        $this->assertSame(1, $this->actingAs($admin)->getJson('/api/admin/expert-applications?search=Cyber')->json('total'));
        $this->assertSame(1, $this->actingAs($admin)->getJson('/api/admin/expert-applications?search=سارة')->json('total'));
        $this->actingAs($admin)->getJson('/api/admin/expert-applications?status=pending')->assertStatus(422);
    }
}
