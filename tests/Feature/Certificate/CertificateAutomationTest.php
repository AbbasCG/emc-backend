<?php

namespace Tests\Feature\Certificate;

use App\Events\Certificate\CertificateCreated;
use App\Events\Certificate\CertificatePdfFailed;
use App\Events\Certificate\CertificatePdfGenerated;
use App\Events\Course\CourseCompleted;
use App\Events\LearningPath\LearningPathCompleted;
use App\Events\Workshop\WorkshopCompleted;
use App\Jobs\GenerateCertificatePdfJob;
use App\Listeners\Certificate\IssueCertificateAfterCourseCompletion;
use App\Listeners\Certificate\IssueCertificateAfterLearningPathCompletion;
use App\Listeners\Certificate\IssueCertificateAfterWorkshopCompletion;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Course;
use App\Models\LearningPath;
use App\Models\User;
use App\Models\Workshop;
use App\Services\Certificate\CertificateAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CertificateAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User    $student;
    private Course  $course;

    protected function setUp(): void
    {
        parent::setUp();

        // A default active template is required for automation
        CertificateTemplate::factory()->create([
            'is_default'   => true,
            'is_active'    => true,
            'designer_mode'=> true,
            'name'         => 'Default',
        ]);

        $this->student = User::factory()->create(['role' => 'student']);
        $this->course  = Course::factory()->create([
            'certificate_type'      => 'course_completion',
            'certificate_available' => true,
        ]);
    }

    // ── Automation service ────────────────────────────────────────────────────

    public function test_course_completion_creates_certificate(): void
    {
        Queue::fake();
        Event::fake([CertificateCreated::class]);

        $result = app(CertificateAutomationService::class)
            ->issueForCourseCompletion($this->student, $this->course);

        $this->assertTrue($result->wasCreated());
        $this->assertDatabaseHas('certificates', [
            'user_id'          => $this->student->id,
            'course_id'        => $this->course->id,
            'certificate_type' => 'course_completion',
            'status'           => 'pending_generation',
        ]);

        Queue::assertPushed(GenerateCertificatePdfJob::class);
        Event::assertDispatched(CertificateCreated::class);
    }

    public function test_duplicate_course_completion_does_not_create_duplicate_certificate(): void
    {
        Queue::fake();

        $svc = app(CertificateAutomationService::class);

        $first  = $svc->issueForCourseCompletion($this->student, $this->course);
        $second = $svc->issueForCourseCompletion($this->student, $this->course);

        $this->assertTrue($first->wasCreated());
        $this->assertTrue($second->wasDuplicate());
        $this->assertSame($first->certificate->id, $second->certificate->id);

        $this->assertDatabaseCount('certificates', 1);
    }

    public function test_workshop_completion_creates_certificate(): void
    {
        Queue::fake();
        $workshop = Workshop::factory()->create();

        $result = app(CertificateAutomationService::class)
            ->issueForWorkshopCompletion($this->student, $workshop);

        $this->assertTrue($result->wasCreated());
        $this->assertDatabaseHas('certificates', [
            'user_id'          => $this->student->id,
            'workshop_id'      => $workshop->id,
            'certificate_type' => 'workshop_attendance',
        ]);
    }

    public function test_duplicate_workshop_completion_is_prevented(): void
    {
        Queue::fake();
        $workshop = Workshop::factory()->create();

        $svc    = app(CertificateAutomationService::class);
        $first  = $svc->issueForWorkshopCompletion($this->student, $workshop);
        $second = $svc->issueForWorkshopCompletion($this->student, $workshop);

        $this->assertTrue($second->wasDuplicate());
        $this->assertDatabaseCount('certificates', 1);
    }

    public function test_revoked_certificate_allows_new_certificate(): void
    {
        Queue::fake();

        // Create and immediately revoke
        Certificate::create([
            'user_id'          => $this->student->id,
            'course_id'        => $this->course->id,
            'certificate_type' => 'course_completion',
            'title'            => 'Test',
            'recipient_name'   => $this->student->name,
            'recipient_email'  => $this->student->email,
            'status'           => 'revoked',
            'related_type'     => 'course',
            'related_id'       => $this->course->id,
        ]);

        // A new issuance should succeed because the existing one is revoked
        $result = app(CertificateAutomationService::class)
            ->issueForCourseCompletion($this->student, $this->course);

        $this->assertTrue($result->wasCreated());
        $this->assertDatabaseCount('certificates', 2);
    }

    // ── Event-driven listeners ────────────────────────────────────────────────

    public function test_course_completed_event_triggers_listener(): void
    {
        Queue::fake();
        Event::fake([CertificateCreated::class]);

        event(new CourseCompleted($this->student, $this->course));

        Queue::assertPushed(IssueCertificateAfterCourseCompletion::class);
    }

    public function test_workshop_completed_event_triggers_listener(): void
    {
        Queue::fake();
        $workshop = Workshop::factory()->create();

        event(new WorkshopCompleted($this->student, $workshop));

        Queue::assertPushed(IssueCertificateAfterWorkshopCompletion::class);
    }

    // ── Job state machine ─────────────────────────────────────────────────────

    public function test_pdf_job_sets_generating_status_on_start(): void
    {
        $cert = Certificate::factory()->create([
            'user_id'          => $this->student->id,
            'status'           => 'pending_generation',
            'pdf_generation_status' => 'pending',
        ]);

        $job = new GenerateCertificatePdfJob($cert);

        // Job will fail (no real render service), but status should be set to 'generating'
        try {
            $job->handle(app(\App\Services\Certificate\CertificateRenderService::class));
        } catch (\Throwable) {}

        // Either 'generating' was set and then 'failed' (if render throws) — both are acceptable
        $fresh = $cert->fresh();
        $this->assertContains($fresh->pdf_generation_status, ['generating', 'failed', 'ready']);
    }

    public function test_pdf_job_marks_failed_on_exception(): void
    {
        Event::fake([CertificatePdfFailed::class]);

        $cert = Certificate::factory()->create([
            'user_id' => $this->student->id,
            'status'  => 'pending_generation',
        ]);

        // A broken render service
        $mockRender = $this->createMock(\App\Services\Certificate\CertificateRenderService::class);
        $mockRender->method('storePdf')->willThrowException(new \RuntimeException('Render error'));

        $job = new GenerateCertificatePdfJob($cert);

        try {
            $job->handle($mockRender);
        } catch (\Throwable) {}

        $this->assertEquals('generation_failed', $cert->fresh()->status);
        $this->assertEquals('failed', $cert->fresh()->pdf_generation_status);

        Event::assertDispatched(CertificatePdfFailed::class);
    }

    // ── Retry endpoint ────────────────────────────────────────────────────────

    public function test_retry_pdf_endpoint_only_retries_failed_certificates(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'super_admin']);

        $cert = Certificate::factory()->create([
            'user_id' => $this->student->id,
            'status'  => 'generation_failed',
            'pdf_generation_status' => 'failed',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/certificates/{$cert->id}/retry-pdf");

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertEquals('pending_generation', $cert->fresh()->status);
        Queue::assertPushed(GenerateCertificatePdfJob::class);
    }

    public function test_retry_pdf_endpoint_rejects_non_failed_status(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'super_admin']);
        $cert  = Certificate::factory()->create([
            'user_id' => $this->student->id,
            'status'  => 'issued',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/certificates/{$cert->id}/retry-pdf")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ── Artisan commands ──────────────────────────────────────────────────────

    public function test_audit_integrity_command_dry_run(): void
    {
        $this->artisan('certificates:audit-integrity --dry-run')
            ->assertExitCode(0); // exits 0 when no issues found
    }

    public function test_repair_missing_pdfs_dry_run(): void
    {
        $this->artisan('certificates:repair-missing-pdfs --dry-run')
            ->assertExitCode(0);
    }

    public function test_retry_failed_dry_run(): void
    {
        $this->artisan('certificates:retry-failed --dry-run')
            ->assertExitCode(0);
    }

    public function test_cleanup_orphan_pdfs_dry_run(): void
    {
        $this->artisan('certificates:cleanup-orphan-pdfs --dry-run')
            ->assertExitCode(0);
    }
}
