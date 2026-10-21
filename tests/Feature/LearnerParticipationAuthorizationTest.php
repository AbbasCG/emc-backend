<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Evaluation;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Participating as a learner needs an enrollment, for every account. Administrative standing is management, not participation:
 * an administrator cannot submit an assignment, read a learner's assignment page or file a review for a course they are not enrolled
 * in merely because they are an administrator. Management itself (instructor grading, admin screens) is untouched.
 */
class LearnerParticipationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** @return array<string, array{0: string}> */
    public static function adminTier(): array
    {
        return ['admin' => ['admin'], 'super_admin' => ['super_admin'], 'tech_admin' => ['tech_admin']];
    }

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /** @return array{course: Course, assignment: Assignment, teacher: User} */
    private function courseWithAssignment(): array
    {
        $teacher = $this->userWithRole('instructor');
        $profile = Instructor::create(['user_id' => $teacher->id, 'name' => $teacher->name]);
        $course = Course::factory()->free()->create(['instructor_id' => $profile->id, 'registration_open' => true, 'status' => 'published']);
        $assignment = Assignment::create(['course_id' => $course->id, 'title' => 'واجب', 'status' => 'active', 'max_score' => 10, 'resubmission_allowed' => true, 'is_visible' => true]);

        return compact('course', 'assignment', 'teacher');
    }

    #[DataProvider('adminTier')]
    public function test_an_administrator_cannot_use_the_learner_assignment_endpoints_for_a_course_they_are_not_enrolled_in(string $role): void
    {
        ['assignment' => $assignment] = $this->courseWithAssignment();
        $admin = $this->userWithRole($role);

        $this->actingAs($admin, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'تسليم'])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson("/api/student/assignments/{$assignment->id}")->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson("/api/student/assignments/{$assignment->id}/history")->assertForbidden();

        $this->assertSame(0, AssignmentSubmission::where('user_id', $admin->id)->count(), 'nothing is stored for a refused submission');
    }

    #[DataProvider('adminTier')]
    public function test_an_administrator_who_is_enrolled_participates_like_any_learner(string $role): void
    {
        ['course' => $course, 'assignment' => $assignment] = $this->courseWithAssignment();
        $admin = $this->userWithRole($role);
        $this->actingAs($admin, 'sanctum')->postJson("/api/courses/{$course->id}/register", [])->assertCreated();

        $this->actingAs($admin, 'sanctum')->getJson("/api/student/assignments/{$assignment->id}")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'تسليمي'])->assertSuccessful();
        $this->actingAs($admin, 'sanctum')->getJson("/api/student/assignments/{$assignment->id}/history")->assertOk();
        $this->assertSame(1, AssignmentSubmission::where('user_id', $admin->id)->where('assignment_id', $assignment->id)->count());
    }

    public function test_an_enrollment_that_does_not_grant_access_gives_no_participation_even_to_an_administrator(): void
    {
        ['course' => $course, 'assignment' => $assignment] = $this->courseWithAssignment();
        $admin = $this->userWithRole('admin');
        Registration::factory()->create(['user_id' => $admin->id, 'course_id' => $course->id, 'status' => 'pending_payment']);

        $this->actingAs($admin, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'x'])->assertForbidden();
    }

    public function test_the_instructor_of_a_course_cannot_submit_its_assignments_as_a_learner_but_still_grades_them(): void
    {
        ['assignment' => $assignment, 'teacher' => $teacher, 'course' => $course] = $this->courseWithAssignment();
        $student = $this->userWithRole('student');
        $this->actingAs($student, 'sanctum')->postJson("/api/courses/{$course->id}/register", [])->assertCreated();
        $this->actingAs($student, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'إجابة'])->assertSuccessful();

        $this->actingAs($teacher, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'x'])->assertForbidden();

        // Management is untouched: the instructor sees the submissions to grade.
        $this->actingAs($teacher, 'sanctum')->getJson('/api/instructor/submissions')->assertOk();
        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)->firstOrFail();
        $this->actingAs($teacher, 'sanctum')->getJson("/api/instructor/submissions/{$submission->id}")->assertOk();
    }

    public function test_administrative_management_of_assignments_and_courses_is_unchanged(): void
    {
        ['course' => $course] = $this->courseWithAssignment();
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/courses')->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson("/api/admin/courses/{$course->id}")->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/instructor/submissions')->assertOk();
    }

    #[DataProvider('adminTier')]
    public function test_an_administrator_cannot_review_a_course_they_are_not_enrolled_in_and_can_when_they_are(string $role): void
    {
        ['course' => $course] = $this->courseWithAssignment();
        $admin = $this->userWithRole($role);

        $this->actingAs($admin, 'sanctum')->postJson('/api/student/evaluations', ['course_id' => $course->id, 'rating' => 5])->assertForbidden();
        $this->assertSame(0, Evaluation::where('user_id', $admin->id)->count());

        $this->actingAs($admin, 'sanctum')->postJson("/api/courses/{$course->id}/register", [])->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/student/evaluations', ['course_id' => $course->id, 'rating' => 5])->assertCreated();
    }

    public function test_a_review_must_name_a_course_or_workshop_for_everyone(): void
    {
        foreach (['student', 'volunteer', 'admin'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')->postJson('/api/student/evaluations', ['rating' => 5, 'comment' => 'x'])->assertStatus(422);
        }
        $this->assertSame(0, Evaluation::count());
    }
}
