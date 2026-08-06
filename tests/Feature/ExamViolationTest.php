<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\ExamViolation;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\PlacementTestQuestion;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamViolationTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function englishCourse(): Course
    {
        return Course::factory()->create([
            'requires_placement_test' => true,
            'placement_test_type'     => 'english',
            'status'                  => 'published',
        ]);
    }

    private function placementTest(): PlacementTest
    {
        $test = PlacementTest::create([
            'title'            => 'EMC English Placement Test',
            'language'         => 'en',
            'duration_minutes' => 30,
            'total_questions'  => 1,
            'is_active'        => true,
        ]);

        PlacementTestQuestion::create([
            'placement_test_id' => $test->id,
            'question_text'     => 'Yesterday, I ___ to school.',
            'option_a'          => 'go', 'option_b' => 'went', 'option_c' => 'gone', 'option_d' => 'going',
            'correct_option'    => 'b',
            'order'             => 1,
            'level_band'        => 'beginner',
        ]);

        return $test;
    }

    private function enroll(User $student, Course $course): Registration
    {
        return Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'Registered',
        ]);
    }

    private function inProgressAttempt(User $student, Course $course, PlacementTest $test): PlacementTestAttempt
    {
        return PlacementTestAttempt::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'placement_test_id' => $test->id, 'total_questions' => $test->total_questions,
            'started_at' => now(), 'status' => 'in_progress',
        ]);
    }

    // ─── Student logging ──────────────────────────────────────────────────────

    public function test_student_can_log_a_violation_against_active_attempt(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $attempt = $this->inProgressAttempt($student, $course, $test);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/violations", [
                'violation_type' => 'copy',
                'severity'       => 'low',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('exam_violations', [
            'user_id'                   => $student->id,
            'placement_test_attempt_id' => $attempt->id,
            'violation_type'            => 'copy',
            'severity'                  => 'low',
        ]);
    }

    public function test_violation_rejects_invalid_type(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $this->inProgressAttempt($student, $course, $test);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/violations", [
                'violation_type' => 'not_a_real_type',
            ])
            ->assertStatus(422);
    }

    public function test_unenrolled_student_cannot_log_violation(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/violations", [
                'violation_type' => 'copy',
            ])
            ->assertForbidden();
    }

    public function test_violation_without_active_attempt_is_accepted_silently(): void
    {
        // No in_progress attempt exists (e.g. race on page unload after submit) —
        // must not error, since the exam is already over and there's nothing to log against.
        $student = $this->student();
        $course  = $this->englishCourse();
        $this->placementTest();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/violations", [
                'violation_type' => 'tab_switch',
            ])
            ->assertOk();

        $this->assertDatabaseCount('exam_violations', 0);
    }

    public function test_captures_ip_and_user_agent(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $this->inProgressAttempt($student, $course, $test);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/violations", [
                'violation_type' => 'right_click',
            ], ['User-Agent' => 'ExamTestAgent/1.0'])
            ->assertOk();

        $violation = ExamViolation::first();
        $this->assertNotNull($violation->ip_address);
        $this->assertSame('ExamTestAgent/1.0', $violation->user_agent);
    }

    // ─── Admin views ──────────────────────────────────────────────────────────

    public function test_admin_can_view_attempt_violation_timeline_with_risk_score(): void
    {
        $admin   = $this->admin();
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $attempt = $this->inProgressAttempt($student, $course, $test);

        ExamViolation::create([
            'user_id' => $student->id, 'placement_test_attempt_id' => $attempt->id,
            'violation_type' => 'copy', 'severity' => 'low', 'occurred_at' => now(),
        ]);
        ExamViolation::create([
            'user_id' => $student->id, 'placement_test_attempt_id' => $attempt->id,
            'violation_type' => 'fullscreen_exit', 'severity' => 'high', 'occurred_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/placement-tests/attempts/{$attempt->id}/violations")
            ->assertOk()
            ->assertJsonPath('data.violation_count', 2)
            ->assertJsonPath('data.risk_score', 7) // 1 (low) + 6 (high)
            ->assertJsonCount(2, 'data.violations');
    }

    public function test_student_cannot_access_admin_violations_endpoint(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $attempt = $this->inProgressAttempt($student, $course, $test);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/admin/placement-tests/attempts/{$attempt->id}/violations")
            ->assertForbidden();
    }

    public function test_admin_violations_summary_ranks_by_risk_score(): void
    {
        $admin    = $this->admin();
        $student1 = $this->student();
        $student2 = $this->student();
        $course   = $this->englishCourse();
        $test     = $this->placementTest();
        $this->enroll($student1, $course);
        $this->enroll($student2, $course);
        $attempt1 = $this->inProgressAttempt($student1, $course, $test);
        $attempt2 = $this->inProgressAttempt($student2, $course, $test);

        // student1: one low violation (risk 1)
        ExamViolation::create([
            'user_id' => $student1->id, 'placement_test_attempt_id' => $attempt1->id,
            'violation_type' => 'copy', 'severity' => 'low', 'occurred_at' => now(),
        ]);
        // student2: two high violations (risk 12)
        ExamViolation::create([
            'user_id' => $student2->id, 'placement_test_attempt_id' => $attempt2->id,
            'violation_type' => 'fullscreen_exit', 'severity' => 'high', 'occurred_at' => now(),
        ]);
        ExamViolation::create([
            'user_id' => $student2->id, 'placement_test_attempt_id' => $attempt2->id,
            'violation_type' => 'fullscreen_exit', 'severity' => 'high', 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/placement-tests/violations/summary')
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame($attempt2->id, $data[0]['attempt_id']); // higher risk first
        $this->assertSame(12, $data[0]['risk_score']);
        $this->assertSame($attempt1->id, $data[1]['attempt_id']);
        $this->assertSame(1, $data[1]['risk_score']);
    }
}
