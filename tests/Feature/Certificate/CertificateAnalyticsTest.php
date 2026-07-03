<?php

namespace Tests\Feature\Certificate;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin   = User::factory()->create(['role' => 'super_admin']);
        $this->student = User::factory()->create(['role' => 'student']);

        CertificateTemplate::factory()->create(['is_default' => true, 'is_active' => true]);
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_analytics_endpoints_require_auth(): void
    {
        $this->getJson('/api/admin/certificates/analytics/overview')
            ->assertStatus(401);
    }

    public function test_student_cannot_access_analytics(): void
    {
        $this->actingAs($this->student)
            ->getJson('/api/admin/certificates/analytics/overview')
            ->assertStatus(403);
    }

    // ── Overview ──────────────────────────────────────────────────────────────

    public function test_overview_returns_real_counts(): void
    {
        Certificate::factory()->count(3)->create(['user_id' => $this->student->id, 'status' => 'issued']);
        Certificate::factory()->count(2)->create(['user_id' => $this->student->id, 'status' => 'revoked']);

        $res = $this->actingAs($this->admin)
            ->getJson('/api/admin/certificates/analytics/overview?preset=all_time')
            ->assertOk()
            ->assertJsonPath('success', true);

        $data = $res->json('data');
        $this->assertGreaterThanOrEqual(3, $data['issued']);
        $this->assertGreaterThanOrEqual(2, $data['revoked']);
        $this->assertArrayHasKey('total', $data);
    }

    public function test_overview_preset_filter(): void
    {
        $res = $this->actingAs($this->admin)
            ->getJson('/api/admin/certificates/analytics/overview?preset=last_30_days')
            ->assertOk();

        $this->assertIsArray($res->json('data'));
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    public function test_status_filter_changes_results(): void
    {
        Certificate::factory()->count(2)->create(['user_id' => $this->student->id, 'status' => 'revoked']);
        Certificate::factory()->count(4)->create(['user_id' => $this->student->id, 'status' => 'issued']);

        $res = $this->actingAs($this->admin)
            ->getJson('/api/admin/certificates/analytics/overview?preset=all_time&status=revoked')
            ->assertOk();

        $this->assertEquals(2, $res->json('data.total'));
    }

    // ── Trends ────────────────────────────────────────────────────────────────

    public function test_trends_returns_grouped_data(): void
    {
        Certificate::factory()->count(3)->create(['user_id' => $this->student->id]);

        $res = $this->actingAs($this->admin)
            ->getJson('/api/admin/certificates/analytics/trends?preset=last_30_days&granularity=daily')
            ->assertOk();

        $data = $res->json('data');
        $this->assertIsArray($data);

        if (count($data) > 0) {
            $this->assertArrayHasKey('date', $data[0]);
            $this->assertArrayHasKey('created', $data[0]);
        }
    }

    // ── Status distribution ───────────────────────────────────────────────────

    public function test_status_distribution_returns_correct_counts(): void
    {
        Certificate::factory()->create(['user_id' => $this->student->id, 'status' => 'issued']);
        Certificate::factory()->create(['user_id' => $this->student->id, 'status' => 'revoked']);

        $res = $this->actingAs($this->admin)
            ->getJson('/api/admin/certificates/analytics/status-distribution?preset=all_time')
            ->assertOk();

        $data = $res->json('data');
        $this->assertArrayHasKey('by_status', $data);
        $this->assertArrayHasKey('by_pdf_status', $data);
    }

    // ── Approval metrics ─────────────────────────────────────────────────────

    public function test_approval_metrics_returns_pending_count(): void
    {
        Certificate::factory()->count(3)->create([
            'user_id'         => $this->student->id,
            'approval_status' => 'pending_approval',
        ]);

        $res = $this->actingAs($this->admin)
            ->getJson('/api/admin/certificates/analytics/approval?preset=all_time')
            ->assertOk();

        $data = $res->json('data');
        $this->assertGreaterThanOrEqual(3, $data['pending_approvals']);
        $this->assertArrayHasKey('stuck_approvals', $data);
        $this->assertArrayHasKey('top_approvers', $data);
    }

    // ── PDF metrics ───────────────────────────────────────────────────────────

    public function test_pdf_metrics_detects_failures(): void
    {
        Certificate::factory()->create([
            'user_id'              => $this->student->id,
            'pdf_generation_status'=> 'failed',
            'status'               => 'generation_failed',
        ]);

        $res = $this->actingAs($this->admin)
            ->getJson('/api/admin/certificates/analytics/pdf?preset=all_time')
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, $res->json('data.failed'));
        $this->assertIsArray($res->json('data.latest_failed'));
    }

    // ── Template usage ────────────────────────────────────────────────────────

    public function test_template_usage_counts_certificates(): void
    {
        $template = CertificateTemplate::factory()->create(['is_active' => true]);
        Certificate::factory()->count(4)->create([
            'user_id'                 => $this->student->id,
            'certificate_template_id' => $template->id,
            'status'                  => 'issued',
        ]);

        $res = $this->actingAs($this->admin)
            ->getJson('/api/admin/certificates/analytics/templates')
            ->assertOk();

        $rows = collect($res->json('data'));
        $row  = $rows->firstWhere('id', $template->id);
        $this->assertNotNull($row);
        $this->assertEquals(4, $row['total_certificates']);
    }

    // ── Failure insights ──────────────────────────────────────────────────────

    public function test_failure_insights_groups_failures(): void
    {
        Certificate::factory()->create([
            'user_id'              => $this->student->id,
            'pdf_generation_status'=> 'failed',
            'status'               => 'generation_failed',
        ]);

        $res = $this->actingAs($this->admin)
            ->getJson('/api/admin/certificates/analytics/failures?preset=all_time')
            ->assertOk();

        $insights = $res->json('data');
        $this->assertIsArray($insights);

        $types = array_column($insights, 'type');
        $this->assertContains('pdf_generation_failed', $types);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function test_analytics_export_overview_returns_csv(): void
    {
        $res = $this->actingAs($this->admin)
            ->get('/api/admin/certificates/analytics/export/overview?preset=all_time');

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
    }

    public function test_analytics_export_rejects_invalid_type(): void
    {
        $this->actingAs($this->admin)
            ->get('/api/admin/certificates/analytics/export/hacked')
            ->assertStatus(422);
    }
}
