<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\User;
use App\Services\AssignmentSubmissionTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 5 (remaining-gaps pass): explicit scope derivation, the submission
 * transition service, max-attempts enforcement, dashboard/missing-
 * submissions aggregation, and bulk review.
 */
class AssignmentScopeAndTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): array
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $profile = Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);
        return [$user, $profile];
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function course(Instructor $instructor): Course
    {
        return Course::factory()->create(['title' => 'English', 'status' => 'published', 'instructor_id' => $instructor->id]);
    }

    private function enroll(User $student, Course $course): void
    {
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email, 'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    // ── Scope derivation ──────────────────────────────────────────────────

    public function test_course_wide_assignment_gets_course_scope(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $assignment = Assignment::create(['course_id' => $course->id, 'title' => 'HW', 'status' => 'published', 'max_score' => 100]);

        $this->assertSame(Assignment::SCOPE_COURSE, $assignment->scope);
    }

    public function test_class_scoped_assignment_gets_class_group_scope(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1', 'capacity' => 10, 'status' => 'active',
        ]);
        $assignment = Assignment::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'title' => 'HW', 'status' => 'published', 'max_score' => 100]);

        $this->assertSame(Assignment::SCOPE_CLASS_GROUP, $assignment->scope);
    }

    public function test_scope_cannot_be_set_independently_of_the_real_fk(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        // Attempting to lie about scope is silently corrected on save.
        $assignment = Assignment::create(['course_id' => $course->id, 'scope' => 'class_group', 'title' => 'HW', 'status' => 'published', 'max_score' => 100]);

        $this->assertSame(Assignment::SCOPE_COURSE, $assignment->scope);
    }

    public function test_session_scoped_assignment_gets_session_scope(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $session = \App\Models\LmsSession::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'title' => 'S1', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);
        $assignment = Assignment::create(['course_id' => $course->id, 'session_id' => $session->id, 'title' => 'HW', 'status' => 'published', 'max_score' => 100]);

        $this->assertSame(Assignment::SCOPE_SESSION, $assignment->scope);
    }

    public function test_admin_create_rejects_session_from_a_different_course(): void
    {
        [, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $courseB = $this->course($instructorB);
        $session = \App\Models\LmsSession::create([
            'course_id' => $courseB->id, 'instructor_id' => $instructorB->id,
            'title' => 'S1', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/assignments', [
            'course_id' => $courseA->id, 'session_id' => $session->id, 'title' => 'HW',
        ]);

        $response->assertStatus(422);
    }

    // ── Transition service ───────────────────────────────────────────────

    public function test_submitted_to_graded_is_legal(): void
    {
        $svc = app(AssignmentSubmissionTransitionService::class);
        $submission = new AssignmentSubmission(['status' => 'submitted']);
        $this->assertTrue($svc->canTransition($submission, 'graded'));
    }

    public function test_graded_to_submitted_is_illegal(): void
    {
        $svc = app(AssignmentSubmissionTransitionService::class);
        $submission = new AssignmentSubmission(['status' => 'graded']);
        $this->assertFalse($svc->canTransition($submission, 'submitted'));
    }

    public function test_review_endpoint_rejects_illegal_transition(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $assignment = Assignment::create(['course_id' => $course->id, 'title' => 'HW', 'status' => 'published', 'max_score' => 100]);
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id, 'user_id' => $student->id,
            'text_answer' => 'x', 'status' => 'graded', 'score' => 90, 'submitted_at' => now(), 'reviewed_at' => now(),
        ]);

        // pending_review maps to stored 'submitted' — illegal from 'graded' per the matrix.
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/submissions/{$submission->id}/review", ['status' => 'pending_review']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('assignment_submissions', ['id' => $submission->id, 'status' => 'graded']);
    }

    // ── Max attempts ──────────────────────────────────────────────────────

    public function test_max_attempts_blocks_resubmission_beyond_the_cap(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'HW', 'status' => 'published',
            'max_score' => 100, 'resubmission_allowed' => true, 'max_attempts' => 2,
        ]);

        $this->actingAs($student, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'v1'])->assertStatus(201);
        $this->actingAs($student, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'v2'])->assertOk();
        // Attempt 3 exceeds max_attempts=2.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'v3'])
            ->assertStatus(422);
    }

    public function test_no_max_attempts_means_unlimited_resubmission(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'HW', 'status' => 'published',
            'max_score' => 100, 'resubmission_allowed' => true, 'max_attempts' => null,
        ]);

        foreach (['v1', 'v2', 'v3', 'v4'] as $i => $answer) {
            $this->actingAs($student, 'sanctum')
                ->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => $answer])
                ->assertStatus($i === 0 ? 201 : 200);
        }
    }

    // ── Dashboard / missing submissions ──────────────────────────────────

    public function test_dashboard_returns_aggregate_counters(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/assignments/dashboard');
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['assignments_total', 'submissions_total', 'pending_review', 'graded', 'needs_revision', 'missing_submissions']]);
    }

    public function test_missing_submissions_endpoint_lists_unsubmitted_students(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $submitter = $this->student();
        $nonSubmitter = $this->student();
        $this->enroll($submitter, $course);
        $this->enroll($nonSubmitter, $course);

        $ca = \App\Models\CourseAssignment::create([
            'course_id' => $course->id, 'title' => 'HW', 'deadline' => now()->addWeek(),
            'max_points' => 100, 'status' => 'published', 'is_visible' => true,
        ]);
        $bridge = Assignment::create(['course_id' => $course->id, 'course_assignment_id' => $ca->id, 'title' => 'HW', 'status' => 'published', 'max_score' => 100]);
        AssignmentSubmission::create(['assignment_id' => $bridge->id, 'user_id' => $submitter->id, 'text_answer' => 'x', 'status' => 'submitted', 'submitted_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/assignments/missing-submissions');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('assignment_id', $ca->id);
        $this->assertNotNull($row);
        $missingIds = collect($row['students'])->pluck('user_id');
        $this->assertTrue($missingIds->contains($nonSubmitter->id));
        $this->assertFalse($missingIds->contains($submitter->id));
    }

    // ── Bulk review ───────────────────────────────────────────────────────

    public function test_bulk_review_grades_multiple_submissions(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $s1 = $this->student();
        $s2 = $this->student();
        $this->enroll($s1, $course);
        $this->enroll($s2, $course);
        $assignment = Assignment::create(['course_id' => $course->id, 'title' => 'HW', 'status' => 'published', 'max_score' => 100]);
        $sub1 = AssignmentSubmission::create(['assignment_id' => $assignment->id, 'user_id' => $s1->id, 'text_answer' => 'a', 'status' => 'submitted', 'submitted_at' => now()]);
        $sub2 = AssignmentSubmission::create(['assignment_id' => $assignment->id, 'user_id' => $s2->id, 'text_answer' => 'b', 'status' => 'submitted', 'submitted_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/instructor/submissions/bulk-review', [
            'submission_ids' => [$sub1->id, $sub2->id], 'score' => 80, 'feedback' => 'Good', 'status' => 'graded',
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('updated_count'));
        $this->assertDatabaseHas('assignment_submissions', ['id' => $sub1->id, 'status' => 'graded', 'score' => 80]);
        $this->assertDatabaseHas('assignment_submissions', ['id' => $sub2->id, 'status' => 'graded', 'score' => 80]);
    }

    public function test_bulk_review_skips_unauthorized_submission_without_failing_the_batch(): void
    {
        [$userA, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $courseB = $this->course($instructorB);
        $sA = $this->student();
        $sB = $this->student();
        $this->enroll($sA, $courseA);
        $this->enroll($sB, $courseB);

        $assignmentA = Assignment::create(['course_id' => $courseA->id, 'title' => 'HW A', 'status' => 'published', 'max_score' => 100]);
        $assignmentB = Assignment::create(['course_id' => $courseB->id, 'title' => 'HW B', 'status' => 'published', 'max_score' => 100]);
        $subA = AssignmentSubmission::create(['assignment_id' => $assignmentA->id, 'user_id' => $sA->id, 'text_answer' => 'a', 'status' => 'submitted', 'submitted_at' => now()]);
        $subB = AssignmentSubmission::create(['assignment_id' => $assignmentB->id, 'user_id' => $sB->id, 'text_answer' => 'b', 'status' => 'submitted', 'submitted_at' => now()]);

        $response = $this->actingAs($userA, 'sanctum')->putJson('/api/instructor/submissions/bulk-review', [
            'submission_ids' => [$subA->id, $subB->id], 'score' => 70, 'status' => 'graded',
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('updated_count'));
        $this->assertSame(1, $response->json('skipped_count'));
        $this->assertDatabaseHas('assignment_submissions', ['id' => $subA->id, 'status' => 'graded']);
        $this->assertDatabaseHas('assignment_submissions', ['id' => $subB->id, 'status' => 'submitted']);
    }
}
