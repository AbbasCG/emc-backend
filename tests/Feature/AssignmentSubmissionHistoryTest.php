<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\User;
use App\Services\AssignmentSubmissionHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 5: resubmission must never silently lose the prior attempt's
 * content/grade/feedback — every state is snapshotted into
 * assignment_submission_attempts before the current row is overwritten.
 */
class AssignmentSubmissionHistoryTest extends TestCase
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

    public function test_first_submission_creates_no_history_row(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'HW1', 'status' => 'published',
            'max_score' => 100, 'resubmission_allowed' => true,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'first answer'])
            ->assertStatus(201);

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)->where('user_id', $student->id)->first();
        $this->assertNotNull($submission);
        $this->assertDatabaseCount('assignment_submission_attempts', 0);
    }

    public function test_resubmission_snapshots_prior_state_into_history(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'HW1', 'status' => 'published',
            'max_score' => 100, 'resubmission_allowed' => true,
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'first answer'])
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'second answer'])
            ->assertOk();

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)->where('user_id', $student->id)->first();
        $this->assertSame('second answer', $submission->text_answer);
        $this->assertDatabaseHas('assignment_submission_attempts', [
            'assignment_submission_id' => $submission->id, 'attempt_number' => 1, 'text_answer' => 'first answer',
        ]);
    }

    public function test_graded_state_is_preserved_in_history_before_resubmission_clears_it(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'HW1', 'status' => 'published',
            'max_score' => 100, 'resubmission_allowed' => true,
        ]);

        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id, 'user_id' => $student->id,
            'text_answer' => 'graded answer', 'score' => 85, 'feedback' => 'Good work',
            'status' => 'graded', 'submitted_at' => now(), 'reviewed_at' => now(),
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'revised answer'])
            ->assertOk();

        $this->assertDatabaseHas('assignment_submission_attempts', [
            'assignment_submission_id' => $submission->id, 'attempt_number' => 1,
            'text_answer' => 'graded answer', 'score' => 85, 'feedback' => 'Good work', 'status' => 'graded',
        ]);
        $fresh = $submission->fresh();
        $this->assertSame('revised answer', $fresh->text_answer);
        $this->assertNull($fresh->score);
    }

    public function test_multiple_resubmissions_produce_sequential_attempt_numbers(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'HW1', 'status' => 'published',
            'max_score' => 100, 'resubmission_allowed' => true,
        ]);

        foreach (['a', 'b', 'c'] as $i => $answer) {
            $this->actingAs($student, 'sanctum')
                ->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => $answer])
                ->assertStatus($i === 0 ? 201 : 200);
        }

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)->where('user_id', $student->id)->first();
        $attempts = app(AssignmentSubmissionHistoryService::class)->attempts($submission);

        $this->assertCount(2, $attempts); // 'a' and 'b' snapshotted; 'c' is current
        $this->assertSame([1, 2], $attempts->pluck('attempt_number')->all());
        $this->assertSame(['a', 'b'], $attempts->pluck('text_answer')->all());
        $this->assertSame('c', $submission->text_answer);
    }

    public function test_instructor_can_view_submission_history(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'HW1', 'status' => 'published',
            'max_score' => 100, 'resubmission_allowed' => true,
        ]);
        $this->actingAs($student, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'v1'])->assertStatus(201);
        $this->actingAs($student, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'v2'])->assertOk();

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)->first();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/submissions/{$submission->id}/history");
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('v1', $response->json('data.0.text_answer'));
    }

    public function test_another_instructor_cannot_view_submission_history(): void
    {
        [, $instructorA] = $this->instructor();
        [$userB] = $this->instructor();
        $course = $this->course($instructorA);
        $student = $this->student();
        $this->enroll($student, $course);
        $assignment = Assignment::create(['course_id' => $course->id, 'title' => 'HW1', 'status' => 'published', 'max_score' => 100]);
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id, 'user_id' => $student->id,
            'text_answer' => 'x', 'status' => 'submitted', 'submitted_at' => now(),
        ]);

        $this->actingAs($userB, 'sanctum')
            ->getJson("/api/instructor/submissions/{$submission->id}/history")
            ->assertStatus(403);
    }

    public function test_student_can_view_own_submission_history(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'HW1', 'status' => 'published',
            'max_score' => 100, 'resubmission_allowed' => true,
        ]);
        $this->actingAs($student, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'v1'])->assertStatus(201);
        $this->actingAs($student, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'v2'])->assertOk();

        $response = $this->actingAs($student, 'sanctum')->getJson("/api/student/assignments/{$assignment->id}/history");
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
