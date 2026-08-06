<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Course;
use App\Models\User;
use App\Services\Certificate\CertificateStatusGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 4 security, authorization and workflow tests.
 */
class CertificateSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function student(?array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => 'student'], $attrs));
    }

    private function makeCert(array $overrides = []): Certificate
    {
        $user   = $overrides['user'] ?? $this->student();
        $course = $overrides['course'] ?? Course::factory()->free()->create();

        return Certificate::create([
            'user_id'           => $user->id,
            'course_id'         => $course->id,
            'certificate_type'  => 'course_completion',
            'title'             => 'Test Certificate',
            'status'            => $overrides['status'] ?? 'issued',
            'verification_code' => 'SEC-TEST-' . uniqid(),
            'issued_at'         => now(),
        ]);
    }

    // ── Part 1: Authorization ──────────────────────────────────────────────────

    /** Guest cannot download any certificate. */
    public function test_guest_cannot_download_certificate(): void
    {
        $cert = $this->makeCert();

        $this->getJson("/api/admin/certificates/{$cert->id}/download")
            ->assertUnauthorized();
    }

    /** Student cannot access admin certificate endpoints. */
    public function test_student_cannot_access_admin_certificate_list(): void
    {
        $student = $this->student();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/certificates')
            ->assertStatus(403);
    }

    /** Student cannot download another student's certificate. */
    public function test_student_cannot_download_another_students_certificate(): void
    {
        $owner  = $this->student();
        $other  = $this->student();
        $cert   = $this->makeCert(['user' => $owner]);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/student/certificates/{$cert->id}/download")
            ->assertStatus(403);
    }

    /** Student cannot view another student's certificate detail. */
    public function test_student_cannot_view_another_students_certificate(): void
    {
        $owner = $this->student();
        $other = $this->student();
        $cert  = $this->makeCert(['user' => $owner]);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/student/certificates/{$cert->id}")
            ->assertStatus(403);
    }

    /** Student cannot download a non-issued certificate even if they own it. */
    public function test_student_cannot_download_pending_certificate(): void
    {
        $student = $this->student();
        $cert    = $this->makeCert(['user' => $student, 'status' => 'pending']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/certificates/{$cert->id}/download")
            ->assertStatus(403);
    }

    /** Admin can view any certificate. */
    public function test_admin_can_view_any_certificate(): void
    {
        $student = $this->student();
        $cert    = $this->makeCert(['user' => $student]);
        $admin   = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/certificates/{$cert->id}")
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    /** programs_manager role can access admin certificates list. */
    public function test_programs_manager_can_list_certificates(): void
    {
        $pm = User::factory()->create(['role' => 'programs_manager']);

        $this->actingAs($pm, 'sanctum')
            ->getJson('/api/admin/certificates')
            ->assertOk();
    }

    /** programs_manager cannot revoke certificates (only admin/super_admin/tech_admin). */
    public function test_programs_manager_cannot_revoke_certificate(): void
    {
        $pm   = User::factory()->create(['role' => 'programs_manager']);
        $cert = $this->makeCert(['status' => 'issued']);

        $this->actingAs($pm, 'sanctum')
            ->postJson("/api/admin/certificates/{$cert->id}/revoke", [
                'revoke_reason' => 'Test revocation reason.',
            ])
            ->assertStatus(403);
    }

    /** Unauthorized role (volunteer) cannot access admin certificates. */
    public function test_volunteer_cannot_access_admin_certificates(): void
    {
        $volunteer = User::factory()->create(['role' => 'volunteer']);

        $this->actingAs($volunteer, 'sanctum')
            ->getJson('/api/admin/certificates')
            ->assertStatus(403);
    }

    // ── Part 2: Download response format ──────────────────────────────────────

    /** Public verify endpoint never leaks private data. */
    public function test_public_verify_exposes_only_safe_fields(): void
    {
        $cert = $this->makeCert(['status' => 'issued']);

        $response = $this->getJson("/api/certificates/verify/{$cert->verification_code}");

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonMissing(['pdf_path'])   // storage path must never appear
            ->assertJsonMissing(['revoke_reason']);
    }

    /** Revoked certificate returns 410 on public verify. */
    public function test_revoked_certificate_returns_410_on_verify(): void
    {
        $cert = $this->makeCert(['status' => 'revoked']);
        $cert->update(['status' => 'revoked']);

        $this->getJson("/api/certificates/verify/{$cert->verification_code}")
            ->assertStatus(410);
    }

    // ── Part 4: Input validation ───────────────────────────────────────────────

    /** Rejection requires a reason note. */
    public function test_rejection_requires_notes(): void
    {
        $admin = $this->admin();
        $cert  = $this->makeCert(['status' => 'pending']);

        // Create approval records so recordApproval has something to work with
        \App\Models\CertificateApproval::create(['certificate_id' => $cert->id, 'type' => 'administrative', 'status' => 'pending']);
        \App\Models\CertificateApproval::create(['certificate_id' => $cert->id, 'type' => 'programs', 'status' => 'pending']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/certificates/{$cert->id}/approvals", [
                'type'   => 'administrative',
                'status' => 'rejected',
                // notes deliberately omitted
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    /** Revoke requires a reason. */
    public function test_revoke_requires_reason(): void
    {
        $admin = $this->admin();
        $cert  = $this->makeCert(['status' => 'issued']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/certificates/{$cert->id}/revoke", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['revoke_reason']);
    }

    /** Invalid certificate type is rejected. */
    public function test_store_rejects_invalid_certificate_type(): void
    {
        $admin   = $this->admin();
        $student = $this->student();
        $course  = Course::factory()->free()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/certificates', [
                'user_id'          => $student->id,
                'course_id'        => $course->id,
                'certificate_type' => 'INVALID_TYPE',
                'title'            => 'Bad Certificate',
            ])
            ->assertStatus(422);
    }

    // ── Part 5: Status transitions ────────────────────────────────────────────

    /** Cannot revoke a revoked certificate. */
    public function test_cannot_revoke_already_revoked_certificate(): void
    {
        $admin = $this->admin();
        $cert  = $this->makeCert(['status' => 'revoked']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/certificates/{$cert->id}/revoke", [
                'revoke_reason' => 'Trying to revoke again.',
            ])
            ->assertStatus(422);
    }

    /** Cannot regenerate a revoked certificate. */
    public function test_cannot_regenerate_revoked_certificate(): void
    {
        $admin = $this->admin();
        $cert  = $this->makeCert(['status' => 'revoked']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/certificates/{$cert->id}/regenerate")
            ->assertStatus(422);
    }

    /** Status guard allows valid transitions. */
    public function test_status_guard_allows_valid_transition(): void
    {
        $cert = $this->makeCert(['status' => 'issued']);
        CertificateStatusGuard::assertCanTransition($cert, 'revoked'); // should not throw
        $this->assertTrue(true);
    }

    /** Status guard blocks invalid transitions. */
    public function test_status_guard_blocks_invalid_transition(): void
    {
        $this->expectException(ValidationException::class);

        $cert = $this->makeCert(['status' => 'revoked']);
        CertificateStatusGuard::assertCanTransition($cert, 'issued');
    }

    /** Status guard blocks revoked → pending transition. */
    public function test_status_guard_blocks_revoked_to_pending(): void
    {
        $cert = $this->makeCert(['status' => 'revoked']);
        $this->assertFalse(CertificateStatusGuard::canTransition($cert, 'pending'));
    }

    // ── Part 7: Template security ──────────────────────────────────────────────

    /** blade_path outside certificates.templates.* namespace is rejected by the PDF service. */
    public function test_unsafe_blade_path_is_sanitized(): void
    {
        $pdfService = app(\App\Services\CertificatePdfService::class);

        // Use reflection to call the private sanitize method
        $ref    = new \ReflectionClass($pdfService);
        $method = $ref->getMethod('sanitizeBladePath');
        $method->setAccessible(true);

        $this->assertEquals('certificates.templates.default', $method->invoke($pdfService, 'auth.login'));
        $this->assertEquals('certificates.templates.default', $method->invoke($pdfService, '../etc/passwd'));
        $this->assertEquals('certificates.templates.default', $method->invoke($pdfService, null));
        $this->assertEquals('certificates.templates.custom', $method->invoke($pdfService, 'certificates.templates.custom'));
        $this->assertEquals('certificates.templates.default', $method->invoke($pdfService, 'certificates.templates.custom!@#'));
    }

    // ── Part 6: Approval idempotency ──────────────────────────────────────────

    /** CertificateApproval firstOrCreate never creates duplicate rows for same type. */
    public function test_approval_firstorcreate_never_duplicates(): void
    {
        $cert = $this->makeCert(['status' => 'pending']);

        // Simulate two decide() calls via firstOrCreate (same logic as CertificateApprovalService)
        \App\Models\CertificateApproval::firstOrCreate(
            ['certificate_id' => $cert->id, 'type' => 'administrative'],
            ['status' => 'pending'],
        );
        \App\Models\CertificateApproval::firstOrCreate(
            ['certificate_id' => $cert->id, 'type' => 'administrative'],
            ['status' => 'pending'],
        );

        $this->assertEquals(
            1,
            \App\Models\CertificateApproval::where('certificate_id', $cert->id)->where('type', 'administrative')->count()
        );
    }

    // ── Part 11: Error response format ────────────────────────────────────────

    /** 403 response is JSON, not HTML. */
    public function test_unauthorized_returns_json_not_html(): void
    {
        $student  = $this->student();
        $other    = $this->student();
        $cert     = $this->makeCert(['user' => $other]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/certificates/{$cert->id}");

        $response->assertStatus(403);
        $this->assertJson($response->getContent());
    }

    /** Validation error returns 422 with errors key. */
    public function test_validation_error_returns_422_with_errors(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/certificates', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }
}
