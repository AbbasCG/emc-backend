<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for a real IDOR: class-scoped assignment visibility was enforced
 * in StudentAssignmentController::index() (via CourseAssignment::visibleToClassGroupIn)
 * but NOT in show()/submit() on the legacy Assignment-by-ID path — a course-
 * enrolled student outside the assigned class group could view and submit to
 * another class's assignment just by knowing/guessing its ID.
 */
class ClassScopedAssignmentSecurityTest extends TestCase
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
        return Course::factory()->create([
            'title' => 'English Course', 'status' => 'published', 'instructor_id' => $instructor->id,
        ]);
    }

    private function enroll(User $student, Course $course): void
    {
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    private function classGroup(Course $course, Instructor $instructor, array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class 01', 'capacity' => 20, 'status' => 'active',
        ], $overrides));
    }

    private function assignToClass(ClassGroup $group, User $student): void
    {
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);
    }

    public function test_student_outside_class_cannot_view_class_scoped_assignment(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);

        $outsider = $this->student();
        $this->enroll($outsider, $course);
        // outsider is enrolled in the course but NOT a member of $groupA.

        $assignment = Assignment::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id,
            'title' => 'Class A Homework', 'status' => 'published', 'max_score' => 100,
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/student/assignments/{$assignment->id}")
            ->assertStatus(403);
    }

    public function test_student_outside_class_cannot_submit_to_class_scoped_assignment(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);

        $outsider = $this->student();
        $this->enroll($outsider, $course);

        $assignment = Assignment::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id,
            'title' => 'Class A Homework', 'status' => 'published', 'max_score' => 100,
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/student/assignments/{$assignment->id}/submit", [
                'text_answer' => 'trying to sneak in',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('assignment_submissions', [
            'assignment_id' => $assignment->id, 'user_id' => $outsider->id,
        ]);
    }

    public function test_student_inside_class_can_view_and_submit_class_scoped_assignment(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $member = $this->student();
        $this->enroll($member, $course);
        $this->assignToClass($group, $member);

        $assignment = Assignment::create([
            'course_id' => $course->id, 'class_group_id' => $group->id,
            'title' => 'Class Homework', 'status' => 'published', 'max_score' => 100,
        ]);

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/student/assignments/{$assignment->id}")
            ->assertOk();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/student/assignments/{$assignment->id}/submit", [
                'text_answer' => 'my real answer',
            ])
            ->assertStatus(201);
    }

    public function test_course_wide_assignment_remains_visible_to_all_enrolled_students(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        // No class_group_id at all — course-wide assignment, no class gate applies.

        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'Course-wide Homework',
            'status' => 'published', 'max_score' => 100,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/assignments/{$assignment->id}")
            ->assertOk();
    }

    public function test_grading_rejects_score_above_max_score(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'Homework',
            'status' => 'published', 'max_score' => 50,
        ]);
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id, 'user_id' => $student->id,
            'text_answer' => 'answer', 'submitted_at' => now(), 'status' => 'submitted',
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/submissions/{$submission->id}/review", [
                'score' => 75, 'status' => 'graded',
            ])
            ->assertStatus(422);
    }

    public function test_grading_accepts_score_within_max_score(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $assignment = Assignment::create([
            'course_id' => $course->id, 'title' => 'Homework',
            'status' => 'published', 'max_score' => 50,
        ]);
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id, 'user_id' => $student->id,
            'text_answer' => 'answer', 'submitted_at' => now(), 'status' => 'submitted',
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/submissions/{$submission->id}/review", [
                'score' => 45, 'status' => 'graded',
            ])
            ->assertOk();

        $this->assertDatabaseHas('assignment_submissions', ['id' => $submission->id, 'score' => 45]);
    }
}
