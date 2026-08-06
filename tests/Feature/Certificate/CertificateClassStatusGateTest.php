<?php

namespace Tests\Feature\Certificate;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\User;
use App\Services\Certificate\CertificateAutomationService;
use App\Services\CertificateEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ticket 1 final blocker: course-completion certificate issuance must
 * respect the student's resolved ClassGroup status. Uses the real
 * production path — CertificateAutomationService::issueForCourseCompletion()
 * — not just the eligibility helper, per the recovery instructions'
 * explicit "test the actual issuance pipeline, not only a helper" rule.
 */
class CertificateClassStatusGateTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private Course $course;
    private Instructor $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        CertificateTemplate::factory()->create([
            'is_default' => true, 'is_active' => true, 'designer_mode' => true, 'name' => 'Default',
        ]);

        $instructorUser = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $this->instructor = Instructor::create(['user_id' => $instructorUser->id, 'name' => $instructorUser->name, 'title' => 'Instructor']);

        $this->student = User::factory()->create(['role' => 'student']);
        $this->course = Course::factory()->create([
            'certificate_type' => 'course_completion', 'certificate_available' => true,
            'instructor_id' => $this->instructor->id,
        ]);
        Registration::create([
            'user_id' => $this->student->id, 'course_id' => $this->course->id,
            'full_name' => $this->student->name, 'email' => $this->student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    private function classGroup(array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id' => $this->course->id, 'instructor_id' => $this->instructor->id,
            'level_code' => 'B1', 'name' => 'B1', 'capacity' => 10, 'status' => 'active',
        ], $overrides));
    }

    private function assignToClass(ClassGroup $group): void
    {
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $this->student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);
    }

    private function issue(): \App\Services\Certificate\AutomationResult
    {
        Queue::fake();

        return app(CertificateAutomationService::class)->issueForCourseCompletion($this->student, $this->course);
    }

    // ── Class status ─────────────────────────────────────────────────────────

    public function test_draft_class_does_not_issue_a_certificate(): void
    {
        $this->assignToClass($this->classGroup(['status' => 'draft']));

        $result = $this->issue();

        $this->assertTrue($result->wasBlocked());
        $this->assertSame('class_draft', $result->reason);
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_ready_class_does_not_issue_a_certificate(): void
    {
        $this->assignToClass($this->classGroup(['status' => 'ready']));

        $result = $this->issue();

        $this->assertTrue($result->wasBlocked());
        $this->assertSame('class_ready', $result->reason);
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_active_class_does_not_issue_a_completion_certificate(): void
    {
        $this->assignToClass($this->classGroup(['status' => 'active']));

        $result = $this->issue();

        $this->assertTrue($result->wasBlocked());
        $this->assertSame('class_active', $result->reason);
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_completed_class_can_issue_when_eligibility_passes(): void
    {
        $this->assignToClass($this->classGroup(['status' => 'completed']));

        $result = $this->issue();

        $this->assertTrue($result->wasCreated());
        $this->assertDatabaseCount('certificates', 1);
    }

    public function test_archived_class_without_completion_evidence_does_not_issue(): void
    {
        $this->assignToClass($this->classGroup(['status' => 'archived']));

        $result = $this->issue();

        $this->assertTrue($result->wasBlocked());
        $this->assertSame('class_archived_without_completion_evidence', $result->reason);
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_archived_class_preserves_an_already_issued_certificate(): void
    {
        // Certificate issued while completed, class archived afterwards.
        $group = $this->classGroup(['status' => 'completed']);
        $this->assignToClass($group);
        $first = $this->issue();
        $this->assertTrue($first->wasCreated());

        $group->update(['status' => 'archived']);

        // Re-running does not touch, duplicate, or invalidate the existing certificate.
        $second = $this->issue();

        $this->assertTrue($second->wasBlocked());
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseHas('certificates', ['id' => $first->certificate->id, 'status' => 'pending_generation']);
    }

    // ── Class isolation ──────────────────────────────────────────────────────

    public function test_class_from_another_course_is_never_used(): void
    {
        $otherCourse = Course::factory()->create(['instructor_id' => $this->instructor->id]);
        $otherGroup = ClassGroup::create([
            'course_id' => $otherCourse->id, 'instructor_id' => $this->instructor->id,
            'level_code' => 'B1', 'name' => 'Other', 'capacity' => 10, 'status' => 'draft',
        ]);
        ClassGroupStudent::create([
            'class_group_id' => $otherGroup->id, 'user_id' => $this->student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);

        // No class in *this* course — issuance proceeds normally (no_class_context).
        $result = $this->issue();

        $this->assertTrue($result->wasCreated());
    }

    public function test_removed_membership_is_never_used(): void
    {
        $group = $this->classGroup(['status' => 'draft']);
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $this->student->id,
            'status' => 'removed', 'assigned_at' => now(),
        ]);

        // Removed membership in a draft class must not block issuance.
        $result = $this->issue();

        $this->assertTrue($result->wasCreated());
    }

    public function test_another_class_in_the_same_course_does_not_affect_eligibility(): void
    {
        $myGroup = $this->classGroup(['status' => 'completed', 'name' => 'Mine']);
        $this->assignToClass($myGroup);

        // A second, unrelated class in the same course, in draft — must not block.
        ClassGroup::create([
            'course_id' => $this->course->id, 'instructor_id' => $this->instructor->id,
            'level_code' => 'B1', 'name' => 'Someone else\'s', 'capacity' => 10, 'status' => 'draft',
        ]);

        $result = $this->issue();

        $this->assertTrue($result->wasCreated());
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function test_blocked_issuance_creates_zero_certificate_and_pdf_job(): void
    {
        $this->assignToClass($this->classGroup(['status' => 'draft']));

        Queue::fake();
        app(CertificateAutomationService::class)->issueForCourseCompletion($this->student, $this->course);

        $this->assertDatabaseCount('certificates', 0);
        Queue::assertNothingPushed();
    }

    public function test_repeated_completion_after_class_becomes_completed_creates_exactly_one_certificate(): void
    {
        $group = $this->classGroup(['status' => 'draft']);
        $this->assignToClass($group);

        $blocked = $this->issue();
        $this->assertTrue($blocked->wasBlocked());
        $this->assertDatabaseCount('certificates', 0);

        $group->update(['status' => 'completed']);

        $first = $this->issue();
        $this->assertTrue($first->wasCreated());

        $second = $this->issue();
        $this->assertTrue($second->wasDuplicate());

        $this->assertDatabaseCount('certificates', 1);
    }

    // ── Other certificate types unaffected ───────────────────────────────────

    public function test_no_resolved_class_context_still_allows_issuance(): void
    {
        // Student has no ClassGroupStudent row at all.
        $decision = app(CertificateEligibilityService::class)->canIssueForClassContext($this->student->id, $this->course->id);

        $this->assertTrue($decision['allowed']);
        $this->assertSame('no_class_context', $decision['reason']);
        $this->assertNull($decision['class_group']);
    }
}
