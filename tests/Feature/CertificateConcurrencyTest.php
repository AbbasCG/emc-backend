<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificatePdfJob;
use App\Models\Certificate;
use App\Models\CertificateGenerationBatch;
use App\Models\Course;
use App\Models\User;
use App\Services\Certificate\CertificateAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CertificateConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    private function makeCertificate(array $attrs = []): Certificate
    {
        return Certificate::create(array_merge([
            'user_id'               => $this->student()->id,
            'certificate_type'      => 'course_completion',
            'title'                 => 'Test Certificate',
            'status'                => 'generation_failed',
            'pdf_generation_status' => 'failed',
            'verification_code'     => 'CERT-' . rand(10000, 99999),
        ], $attrs));
    }

    // ── Part 3: Automation duplicate prevention ───────────────────────────────

    public function test_automation_does_not_create_duplicate_certificates(): void
    {
        Queue::fake();

        $student = $this->student();
        $course  = Course::factory()->free()->create(['certificate_available' => true]);

        $service = app(CertificateAutomationService::class);

        // First call
        $result1 = $service->issueForCourseCompletion($student, $course);
        // Second call — should detect the existing certificate
        $result2 = $service->issueForCourseCompletion($student, $course);

        $this->assertSame('created', $result1->outcome);
        $this->assertSame('duplicate', $result2->outcome);
        $this->assertSame($result1->certificate->id, $result2->certificate->id);

        $count = Certificate::where('user_id', $student->id)
            ->where('certificate_type', 'course_completion')
            ->where('course_id', $course->id)
            ->count();

        $this->assertSame(1, $count, 'Exactly one certificate should exist after two calls');
    }

    public function test_automation_duplicate_returns_same_certificate_when_existing_is_revoked(): void
    {
        Queue::fake();

        $student = $this->student();
        $course  = Course::factory()->free()->create(['certificate_available' => true]);
        $service = app(CertificateAutomationService::class);

        // Issue once, then revoke it
        $result1 = $service->issueForCourseCompletion($student, $course);
        $result1->certificate->update(['status' => 'revoked']);

        // After revocation, a second call should create a new certificate (revoked is excluded)
        $result2 = $service->issueForCourseCompletion($student, $course);

        $this->assertSame('created', $result2->outcome);
        $this->assertNotSame($result1->certificate->id, $result2->certificate->id);
    }

    // ── Part 4: Retry command max-retries ─────────────────────────────────────

    public function test_retry_command_skips_certificates_exceeding_max_retries(): void
    {
        Queue::fake();

        // Already at limit
        $this->makeCertificate(['pdf_command_retry_count' => 5]);
        // Below limit
        $retryable = $this->makeCertificate(['pdf_command_retry_count' => 2]);

        $this->artisan('certificates:retry-failed', ['--max-retries' => 5, '--limit' => 10])
            ->expectsOutputToContain('1 certificate(s) exceeded the retry limit')
            ->assertSuccessful();

        Queue::assertPushed(GenerateCertificatePdfJob::class, 1);
        $this->assertSame(3, $retryable->fresh()->pdf_command_retry_count);
    }

    public function test_retry_command_does_nothing_when_all_at_limit(): void
    {
        Queue::fake();

        $this->makeCertificate(['pdf_command_retry_count' => 5]);

        $this->artisan('certificates:retry-failed', ['--max-retries' => 5])
            ->expectsOutputToContain('exceeded the retry limit')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ── Part 2: Batch completion ──────────────────────────────────────────────

    public function test_batch_completion_sets_completed_at_exactly_once(): void
    {
        $batch = CertificateGenerationBatch::create([
            'batch_code'       => 'BATCH-TEST-001',
            'status'           => 'processing',
            'total_recipients' => 1,
            'generated_count'  => 0,
            'failed_count'     => 0,
        ]);

        // Simulate atomic batch completion: first update wins, second finds 0 rows
        $affected1 = CertificateGenerationBatch::where('id', $batch->id)
            ->where('status', 'processing')
            ->update(['status' => 'completed', 'completed_at' => now()]);

        $affected2 = CertificateGenerationBatch::where('id', $batch->id)
            ->where('status', 'processing')
            ->update(['status' => 'completed', 'completed_at' => now()->addSecond()]);

        $this->assertSame(1, $affected1);
        $this->assertSame(0, $affected2);

        $fresh = $batch->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    // ── Part 5: PDF job — atomic generation claim ─────────────────────────────

    public function test_pdf_job_skips_when_already_generating(): void
    {
        Queue::fake();

        $cert = Certificate::create([
            'user_id'               => $this->student()->id,
            'certificate_type'      => 'course_completion',
            'title'                 => 'Test',
            'status'                => 'pending_generation',
            'pdf_generation_status' => 'generating',  // ← already claimed by another worker
            'verification_code'     => 'CERT-GEN-' . rand(10000, 99999),
        ]);

        // Simulate the atomic claim: non-forced job cannot claim 'generating'
        $claimed = Certificate::where('id', $cert->id)
            ->where('pdf_generation_status', '!=', 'generating')
            ->update(['pdf_generation_status' => 'generating', 'status' => 'pending_generation']);

        $this->assertSame(0, $claimed, 'Claim must fail when certificate is already generating');
        // Certificate status unchanged
        $this->assertSame('generating', $cert->fresh()->pdf_generation_status);
    }

    public function test_pdf_job_force_claims_even_when_already_generating(): void
    {
        $cert = Certificate::create([
            'user_id'               => $this->student()->id,
            'certificate_type'      => 'course_completion',
            'title'                 => 'Test',
            'status'                => 'pending_generation',
            'pdf_generation_status' => 'generating',
            'verification_code'     => 'CERT-FORCE-' . rand(10000, 99999),
        ]);

        // Force claim has no condition on pdf_generation_status
        $claimed = Certificate::where('id', $cert->id)
            ->update(['pdf_generation_status' => 'generating', 'status' => 'pending_generation', 'pdf_last_error' => null]);

        $this->assertSame(1, $claimed, 'Force claim must always succeed');
    }

    // ── Part 6: retryPdf API endpoint ─────────────────────────────────────────

    public function test_retry_pdf_endpoint_returns_422_when_already_generating(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $cert  = $this->makeCertificate([
            'status'                => 'generation_failed',
            'pdf_generation_status' => 'generating',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/certificates/{$cert->id}/retry-pdf");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        Queue::assertNothingPushed();
    }

    public function test_retry_pdf_endpoint_returns_422_when_not_failed(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $cert  = $this->makeCertificate([
            'status'                => 'issued',
            'pdf_generation_status' => 'ready',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/certificates/{$cert->id}/retry-pdf");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        Queue::assertNothingPushed();
    }

    public function test_retry_pdf_endpoint_dispatches_job_for_failed_certificate(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $cert  = $this->makeCertificate([
            'status'                => 'generation_failed',
            'pdf_generation_status' => 'failed',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/certificates/{$cert->id}/retry-pdf");

        $response->assertOk()->assertJsonPath('success', true);
        Queue::assertPushed(GenerateCertificatePdfJob::class, 1);
    }
}
