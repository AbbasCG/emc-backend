<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\LmsSession;
use App\Models\Quiz;
use App\Models\Role;
use App\Models\User;
use App\Support\CourseContentStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * After enrolling, a learner of ANY role gets the whole learning experience: lessons and progress, attendance recorded by the
 * instructor, assignments, quizzes, the final grade, placement status and certificates, all scoped to their own enrollment.
 * The role list is the platform's own (RoleSeeder::ROLES plus the legacy `teacher`), so a new role is covered automatically.
 */
class UniversalLearnerLearningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** @return array<string, array{0: string}> */
    public static function everyRole(): array
    {
        $cases = [];
        foreach (array_unique([...array_column(RoleSeeder::ROLES, 'name'), 'teacher']) as $role) {
            $cases[$role] = [$role];
        }

        return $cases;
    }

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /**
     * A course taught by a real instructor, with a lesson, a live session, an assignment, a quiz and an issued certificate, and the
     * learner enrolled through the public endpoint.
     *
     * @return array{learner: User, teacher: User, course: Course, lesson: Lesson, session: LmsSession, assignment: Assignment, quiz: Quiz, certificate: Certificate}
     */
    private function scenario(string $role): array
    {
        $teacher = $this->userWithRole('instructor');
        $profile = Instructor::create(['user_id' => $teacher->id, 'name' => $teacher->name]);
        $course = Course::factory()->free()->create(['instructor_id' => $profile->id, 'registration_open' => true, 'status' => 'published']);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'وحدة', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'درس', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE]);
        $session = LmsSession::create(['course_id' => $course->id, 'instructor_id' => $profile->id, 'title' => 'جلسة', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);
        $assignment = Assignment::create(['course_id' => $course->id, 'title' => 'واجب', 'status' => 'active', 'max_score' => 10, 'resubmission_allowed' => true, 'is_visible' => true]);
        $quiz = Quiz::create(['course_id' => $course->id, 'title' => 'اختبار', 'status' => Quiz::STATUS_ACTIVE, 'passing_score' => 50, 'max_attempts' => 3, 'published_at' => now()->subHour()]);

        $learner = $this->userWithRole($role);
        $this->actingAs($learner, 'sanctum')->postJson("/api/courses/{$course->id}/register", [])->assertCreated();
        $certificate = Certificate::factory()->create(['user_id' => $learner->id, 'course_id' => $course->id, 'status' => 'issued']);

        return compact('learner', 'teacher', 'course', 'lesson', 'session', 'assignment', 'quiz', 'certificate');
    }

    #[DataProvider('everyRole')]
    public function test_lessons_and_progress_work_for_every_role(string $role): void
    {
        ['learner' => $learner, 'lesson' => $lesson, 'course' => $course] = $this->scenario($role);

        $this->actingAs($learner, 'sanctum')->postJson("/api/student/lessons/{$lesson->id}/complete")->assertOk()->assertJsonPath('data.lesson_id', $lesson->id);
        $this->assertDatabaseHas('lesson_completions', ['user_id' => $learner->id, 'lesson_id' => $lesson->id]);

        $this->actingAs($learner, 'sanctum')->getJson('/api/student/progress')->assertOk();
        $this->actingAs($learner, 'sanctum')->getJson("/api/student/courses/{$course->id}/final-grade")->assertOk();
        $this->actingAs($learner, 'sanctum')->getJson('/api/student/dashboard')->assertOk();
    }

    #[DataProvider('everyRole')]
    public function test_an_instructor_records_attendance_for_a_learner_of_any_role_and_the_learner_sees_it(string $role): void
    {
        ['learner' => $learner, 'teacher' => $teacher, 'session' => $session] = $this->scenario($role);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/instructor/attendance/{$session->id}", ['attendances' => [['user_id' => $learner->id, 'status' => 'present']]])
            ->assertOk();

        $this->assertSame('present', Attendance::where('user_id', $learner->id)->where('session_id', $session->id)->value('status'));

        $mine = $this->actingAs($learner, 'sanctum')->getJson('/api/student/attendance')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->actingAs($learner, 'sanctum')->getJson('/api/student/attendance/summary')->assertOk();
    }

    #[DataProvider('everyRole')]
    public function test_assignments_can_be_read_and_submitted_for_every_role(string $role): void
    {
        ['learner' => $learner, 'assignment' => $assignment] = $this->scenario($role);

        $this->actingAs($learner, 'sanctum')->getJson('/api/student/assignments')->assertOk();
        $this->actingAs($learner, 'sanctum')->getJson("/api/student/assignments/{$assignment->id}")->assertOk();

        $this->actingAs($learner, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'إجابتي'])->assertSuccessful();
        $this->assertDatabaseHas('assignment_submissions', ['assignment_id' => $assignment->id, 'user_id' => $learner->id]);
    }

    #[DataProvider('everyRole')]
    public function test_quizzes_are_available_and_scored_for_every_role(string $role): void
    {
        ['learner' => $learner, 'quiz' => $quiz, 'course' => $course] = $this->scenario($role);

        $this->actingAs($learner, 'sanctum')->getJson("/api/student/courses/{$course->id}/quizzes")->assertOk();
        $this->actingAs($learner, 'sanctum')->getJson("/api/student/quizzes/{$quiz->id}")->assertOk();

        $this->actingAs($learner, 'sanctum')->postJson("/api/student/quizzes/{$quiz->id}/submit", ['answers' => ['1' => 'a']])
            ->assertOk()->assertJsonPath('data.attempt_number', 1);
        $this->assertDatabaseHas('quiz_attempts', ['quiz_id' => $quiz->id, 'user_id' => $learner->id]);
    }

    #[DataProvider('everyRole')]
    public function test_placement_status_is_available_for_every_role(string $role): void
    {
        ['learner' => $learner, 'course' => $course] = $this->scenario($role);

        $this->actingAs($learner, 'sanctum')->getJson("/api/student/courses/{$course->id}/placement-test/status")->assertOk();
    }

    #[DataProvider('everyRole')]
    public function test_certificates_belong_to_their_owner_whatever_the_role(string $role): void
    {
        ['learner' => $learner, 'certificate' => $certificate] = $this->scenario($role);
        $stranger = $this->userWithRole('student');

        $ids = array_column($this->actingAs($learner, 'sanctum')->getJson('/api/student/certificates')->assertOk()->json('data'), 'id');
        $this->assertSame([$certificate->id], $ids);
        $this->actingAs($learner, 'sanctum')->getJson("/api/student/certificates/{$certificate->id}")->assertOk();

        $this->actingAs($stranger, 'sanctum')->getJson("/api/student/certificates/{$certificate->id}")->assertStatus(403);
        $this->assertSame([], $this->actingAs($stranger, 'sanctum')->getJson('/api/student/certificates')->assertOk()->json('data'));
    }

    #[DataProvider('everyRole')]
    public function test_a_learner_cannot_use_another_courses_content_whatever_the_role(string $role): void
    {
        ['learner' => $learner] = $this->scenario($role);
        $other = Course::factory()->free()->create(['registration_open' => true, 'status' => 'published']);
        $module = CourseModule::create(['course_id' => $other->id, 'title' => 'وحدة', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'درس', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE]);
        $quiz = Quiz::create(['course_id' => $other->id, 'title' => 'اختبار', 'status' => Quiz::STATUS_ACTIVE, 'passing_score' => 50, 'max_attempts' => 3, 'published_at' => now()->subHour()]);
        $assignment = Assignment::create(['course_id' => $other->id, 'title' => 'واجب', 'status' => 'active', 'max_score' => 10, 'is_visible' => true]);

        $this->actingAs($learner, 'sanctum')->postJson("/api/student/lessons/{$lesson->id}/complete")->assertForbidden();
        $this->actingAs($learner, 'sanctum')->postJson("/api/student/quizzes/{$quiz->id}/submit", ['answers' => ['1' => 'a']])->assertForbidden();
        // No account, the admin tier included, submits to an assignment of a course it is not enrolled in.
        $this->actingAs($learner, 'sanctum')->postJson("/api/student/assignments/{$assignment->id}/submit", ['text_answer' => 'x'])->assertForbidden();
    }
}
