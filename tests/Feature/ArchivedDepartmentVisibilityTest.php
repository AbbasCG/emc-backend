<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MeetingReport;
use App\Models\User;
use App\Services\DepartmentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the archived-department visibility fix: an archived department
 * (e.g. Department #11, merged away by MergeDuplicateAiDepartmentCommand)
 * must disappear from active organizational management listings and
 * new-assignment selectors (Department::scopeActive(), filtering on the
 * canonical `status` column), while remaining fully resolvable by ID and
 * via relations for historical records — and DepartmentAccessService's
 * leadership/authorization logic (getLedDepartmentIds/canManageDepartment)
 * must stay completely unaffected by status, since that's a fact about an
 * EXISTING assignment, not a "what can I pick" listing.
 */
class ArchivedDepartmentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveDept(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'AI', 'name_ar' => 'إدارة الذكاء الاصطناعي والتحول الرقمي', 'name_en' => 'AI',
            'slug' => 'ai-transformation', 'status' => 'active', 'is_active' => true,
        ], $overrides));
    }

    private function makeArchivedDept(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'AI Dup', 'name_ar' => 'مكرر', 'name_en' => 'Dup',
            'slug' => 'ai-digital-transformation', 'status' => 'archived', 'is_active' => true,
        ], $overrides));
    }

    public function test_active_department_listing_excludes_archived_department(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $active = $this->makeActiveDept();
        $archived = $this->makeArchivedDept();

        $response = $this->actingAs($admin)->getJson('/api/operations/departments');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id), 'active department must remain visible');
        $this->assertFalse($ids->contains($archived->id), 'archived department must not appear in the management listing');
    }

    public function test_admin_team_page_excludes_archived_department(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $active = $this->makeActiveDept();
        $archived = $this->makeArchivedDept();

        $response = $this->actingAs($admin)->getJson('/api/admin/team');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($archived->id), 'archived department must not be selectable in Team Management');
    }

    public function test_report_creation_manifest_excludes_archived_department_for_global_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $active = $this->makeActiveDept();
        $archived = $this->makeArchivedDept();

        $svc = app(DepartmentAccessService::class);
        $manifest = $svc->manifest($admin);

        $ids = collect($manifest['allowed_departments'])->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($archived->id), 'archived department must not be offered for new report creation');
    }

    public function test_report_creation_manifest_excludes_archived_department_even_if_still_led(): void
    {
        // Edge case: a leader_id somehow still points at an archived department
        // (not the real production state — #11 has leader_id=NULL — but the
        // filter must hold even here). The department must not be offered for
        // NEW report creation, while leadership authorization itself is untouched.
        $leader = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);
        $archived = $this->makeArchivedDept(['leader_id' => $leader->id]);

        $svc = app(DepartmentAccessService::class);
        $manifest = $svc->manifest($leader);

        $this->assertEmpty($manifest['allowed_departments'], 'no active department to offer for new report creation');

        // No regression: leadership/authorization itself is NOT status-filtered.
        $this->assertTrue($svc->getLedDepartmentIds($leader)->contains($archived->id));
        $this->assertTrue($svc->canManageDepartment($leader, $archived->id));
    }

    public function test_archived_department_still_resolves_directly_by_id(): void
    {
        $archived = $this->makeArchivedDept();

        $found = Department::find($archived->id);

        $this->assertNotNull($found);
        $this->assertSame('archived', $found->status);
    }

    public function test_historical_report_relation_to_archived_department_still_resolves(): void
    {
        $archived = $this->makeArchivedDept();
        $creator = User::factory()->create();
        $report = MeetingReport::create([
            'department_id' => $archived->id, 'title' => 'Historical', 'meeting_date' => now()->toDateString(), 'created_by' => $creator->id,
        ]);

        $report->load('department');

        $this->assertNotNull($report->department);
        $this->assertSame($archived->id, $report->department->id);
        $this->assertSame('archived', $report->department->status);
    }

    public function test_no_regression_in_department_access_service_leadership_logic(): void
    {
        $leader = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);
        $active = $this->makeActiveDept(['leader_id' => $leader->id]);

        $svc = app(DepartmentAccessService::class);

        $this->assertTrue($svc->getLedDepartmentIds($leader)->contains($active->id));
        $this->assertTrue($svc->canManageDepartment($leader, $active->id));
        $this->assertTrue($svc->canAccessDepartment($leader, $active->id));
    }
}
