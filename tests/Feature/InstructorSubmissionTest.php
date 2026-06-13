<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\LearningPathItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function createInstructorWithCourse(): array
    {
        $user = User::factory()->create(['role' => 'instructor']);

        $profile = Instructor::create([
            'user_id' => $user->id,
            'name'    => $user->name,
            'title'   => 'Instructor',
        ]);

        $course = Course::factory()->create([
            'instructor_id' => $profile->id,
            'status'        => 'active',
        ]);

        return [$user, $profile, $course];
    }

    public function test_instructor_submissions_returns_enriched_payload(): void
    {
        [$instructorUser, , $course] = $this->createInstructorWithCourse();
        $student = User::factory()->create(['role' => 'student', 'name' => 'Student One']);

        $assignment = Assignment::create([
            'course_id'  => $course->id,
            'title'      => 'Homework 1',
            'max_score'  => 100,
            'status'     => 'active',
        ]);

        AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'user_id'       => $student->id,
            'text_answer'   => 'My answer text',
            'score'         => 88,
            'submitted_at'  => now(),
            'status'        => 'graded',
        ]);

        $response = $this->actingAs($instructorUser, 'sanctum')
            ->getJson('/api/instructor/submissions');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student.name', 'Student One')
            ->assertJsonPath('data.0.student_name', 'Student One')
            ->assertJsonPath('data.0.assignment.title', 'Homework 1')
            ->assertJsonPath('data.0.assignment_title', 'Homework 1')
            ->assertJsonPath('data.0.course.title', $course->title)
            ->assertJsonPath('data.0.course_name', $course->title)
            ->assertJsonPath('data.0.status', 'reviewed')
            ->assertJsonPath('data.0.grade', 88)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'submitted_at',
                    'student' => ['id', 'name', 'email', 'avatar'],
                    'course'  => ['id', 'title', 'slug'],
                ]],
            ]);
    }

    public function test_instructor_does_not_see_other_instructors_submissions(): void
    {
        [$userA, , $courseA] = $this->createInstructorWithCourse();
        [, , $courseB]        = $this->createInstructorWithCourse();

        $student = User::factory()->create(['role' => 'student']);

        $foreignAssignment = Assignment::create([
            'course_id' => $courseB->id,
            'title'     => 'Foreign homework',
            'status'    => 'active',
        ]);

        AssignmentSubmission::create([
            'assignment_id' => $foreignAssignment->id,
            'user_id'       => $student->id,
            'text_answer'   => 'secret',
            'submitted_at'  => now(),
            'status'        => 'submitted',
        ]);

        $ownAssignment = Assignment::create([
            'course_id' => $courseA->id,
            'title'     => 'Own homework',
            'status'    => 'active',
        ]);

        AssignmentSubmission::create([
            'assignment_id' => $ownAssignment->id,
            'user_id'       => $student->id,
            'text_answer'   => 'visible',
            'submitted_at'  => now(),
            'status'        => 'submitted',
        ]);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/instructor/submissions');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Own homework', $response->json('data.0.assignment_title'));
    }

    public function test_path_instructor_sees_submissions_for_path_courses(): void
    {
        [$pathInstructorUser, $pathProfile] = array_slice($this->createInstructorWithCourse(), 0, 2);

        $otherInstructorUser = User::factory()->create(['role' => 'instructor']);
        $otherProfile = Instructor::create([
            'user_id' => $otherInstructorUser->id,
            'name'    => $otherInstructorUser->name,
        ]);

        $course = Course::factory()->create([
            'instructor_id' => $otherProfile->id,
            'status'        => 'active',
        ]);

        $path = LearningPath::create([
            'instructor_id'     => $pathProfile->id,
            'title'             => 'Full Stack Path',
            'slug'              => 'full-stack',
            'status'            => 'published',
            'duration_unit'     => 'weeks',
            'enrollment_open'   => true,
        ]);

        LearningPathItem::create([
            'learning_path_id' => $path->id,
            'course_id'        => $course->id,
            'sort_order'       => 0,
        ]);

        $student = User::factory()->create(['role' => 'student', 'name' => 'Path Student']);

        $assignment = Assignment::create([
            'course_id' => $course->id,
            'title'     => 'Path Assignment',
            'status'    => 'active',
        ]);

        AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'user_id'       => $student->id,
            'text_answer'   => 'path answer',
            'submitted_at'  => now(),
            'status'        => 'submitted',
        ]);

        $response = $this->actingAs($pathInstructorUser, 'sanctum')
            ->getJson('/api/instructor/submissions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.learning_path.title', 'Full Stack Path')
            ->assertJsonPath('data.0.status', 'pending_review');
    }
}
