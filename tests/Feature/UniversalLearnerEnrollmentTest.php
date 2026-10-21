<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\StudentProgress;
use App\Models\User;
use App\Models\Workshop;
use App\Http\Resources\CourseResource;
use App\Services\EffectivePageAccessService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Universal enrollment: every authenticated, active account may learn, whatever its organizational role; the only person refused is
 * an instructor of the program in question, decided from the real teaching assignments.
 *
 * The role inventory is NOT written out here. It is read from the canonical role list (RoleSeeder::ROLES), plus the legacy
 * `teacher` alias, so a role added to the platform is covered the day it is added; and a role that exists nowhere yet is tested
 * explicitly to prove nothing has to be registered for it to learn.
 */
class UniversalLearnerEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function inventory(): array
    {
        return array_values(array_unique([...array_column(RoleSeeder::ROLES, 'name'), 'teacher']));
    }

    /** @return array<string, array{0: string}> */
    public static function everyRole(): array
    {
        $cases = [];
        foreach (self::inventory() as $role) {
            $cases[$role] = [$role];
        }

        return $cases;
    }

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function profileFor(User $user): Instructor
    {
        return Instructor::create(['user_id' => $user->id, 'name' => $user->name]);
    }

    private function course(array $attributes = []): Course
    {
        return Course::factory()->free()->create(['registration_open' => true, 'status' => 'published'] + $attributes);
    }

    private function workshopCourse(array $attributes = []): Course
    {
        return $this->course(['program_type' => 'workshop'] + $attributes);
    }

    private function enroll(User $user, Course $course)
    {
        return $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/register", []);
    }

    private function assertEnrolled(User $user, Course $course): void
    {
        $this->assertTrue(Registration::where('user_id', $user->id)->where('course_id', $course->id)->exists());
    }

    private function assertRefusedAsTeacher($response): void
    {
        $response->assertStatus(403)->assertJsonPath('code', 'instructor_teaches_program');
        $this->assertMatchesRegularExpression('/المدرّب|التدريس/u', (string) $response->json('message'));
        $this->assertSame($response->json('message'), $response->json('message_ar'));
    }

    // ── every role ───────────────────────────────────────────────────────────

    #[DataProvider('everyRole')]
    public function test_every_role_reaches_the_learner_experience(string $role): void
    {
        $user = $this->userWithRole($role);

        foreach (['/api/student/dashboard', '/api/student/registrations', '/api/student/courses', '/api/student/progress', '/api/student/certificates'] as $url) {
            $this->actingAs($user, 'sanctum')->getJson($url)->assertOk();
        }
    }

    #[DataProvider('everyRole')]
    public function test_every_role_has_the_learner_space_in_its_page_access_so_my_courses_shows_in_its_sidebar(string $role): void
    {
        $user = $this->userWithRole($role);

        $this->assertContains('personal.student_learning', app(EffectivePageAccessService::class)->allowedKeys($user));

        $manifest = $this->actingAs($user, 'sanctum')->getJson('/api/auth/me/page-access')->assertOk()->json('data.allowed_keys');
        $this->assertContains('personal.student_learning', $manifest);
    }

    #[DataProvider('everyRole')]
    public function test_every_role_can_enroll_in_an_eligible_course_and_keeps_its_role(string $role): void
    {
        $user = $this->userWithRole($role);
        $course = $this->course();

        $this->enroll($user, $course)->assertCreated()->assertJsonPath('type', 'free');

        $this->assertEnrolled($user, $course);
        $this->assertSame($role, $user->fresh()->role, 'enrolling never changes the account\'s role');
        $this->assertTrue(StudentProgress::where('user_id', $user->id)->where('course_id', $course->id)->exists());

        $mine = $this->actingAs($user, 'sanctum')->getJson('/api/student/registrations')->assertOk()->json('data');
        $this->assertSame([$course->id], array_column($mine, 'course_id'));
    }

    #[DataProvider('everyRole')]
    public function test_every_role_can_enroll_in_an_eligible_workshop(string $role): void
    {
        $user = $this->userWithRole($role);
        $workshop = $this->workshopCourse();

        $this->enroll($user, $workshop)->assertCreated();

        $this->assertEnrolled($user, $workshop);
        $this->assertSame($role, $user->fresh()->role);
    }

    #[DataProvider('everyRole')]
    public function test_every_role_reaches_paid_checkout_and_is_never_refused_for_its_role(string $role): void
    {
        $user = $this->userWithRole($role);
        $course = Course::factory()->paid(50)->create(['registration_open' => true, 'status' => 'published']);

        $status = $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/checkout", [])->status();

        // Whatever the gateway says in this environment, it is not "forbidden because of who you are".
        $this->assertNotContains($status, [401, 403], "[{$role}] must reach checkout");
    }

    #[DataProvider('everyRole')]
    public function test_learning_does_not_grant_teaching_or_management_to_any_role(string $role): void
    {
        $user = $this->userWithRole($role);
        $this->enroll($user, $this->course())->assertCreated();

        $mayTeach = in_array($role, ['instructor', 'teacher', 'admin', 'super_admin', 'tech_admin'], true);
        $this->actingAs($user, 'sanctum')->getJson('/api/instructor/courses')->assertStatus($mayTeach ? 200 : 403);
        if (! in_array($role, ['admin', 'super_admin', 'tech_admin', 'executive_admin'], true)) {
            $this->actingAs($user, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
        }
    }

    public function test_a_role_that_does_not_exist_yet_learns_with_nothing_registered_for_it(): void
    {
        $this->assertNull(Role::where('name', 'future_unknown_role')->first());
        $user = User::factory()->create(['role' => 'future_unknown_role', 'is_active' => true]);
        $course = $this->course();

        $this->actingAs($user, 'sanctum')->getJson('/api/student/dashboard')->assertOk();
        $this->enroll($user, $course)->assertCreated();
        $this->assertContains('personal.student_learning', app(EffectivePageAccessService::class)->allowedKeys($user));
        $this->assertSame('future_unknown_role', $user->fresh()->role);
    }

    public function test_a_suspended_account_and_a_guest_are_still_refused(): void
    {
        $this->getJson('/api/student/dashboard')->assertUnauthorized();

        $suspended = User::factory()->create(['role' => 'volunteer', 'is_active' => false]);
        $this->actingAs($suspended, 'sanctum')->getJson('/api/student/dashboard')->assertForbidden();
    }

    // ── instructors ──────────────────────────────────────────────────────────

    public function test_an_instructor_cannot_enroll_in_their_own_course_but_can_in_another(): void
    {
        $instructor = $this->userWithRole('instructor');
        $profile = $this->profileFor($instructor);
        $teaching = $this->course(['instructor_id' => $profile->id]);
        $other = $this->course(['instructor_id' => $this->profileFor($this->userWithRole('instructor'))->id]);

        $this->assertRefusedAsTeacher($this->enroll($instructor, $teaching));
        $this->assertFalse(Registration::where('user_id', $instructor->id)->exists());

        $this->enroll($instructor, $other)->assertCreated();
        $this->assertEnrolled($instructor, $other);
    }

    public function test_an_instructor_cannot_enroll_in_their_own_workshop(): void
    {
        $instructor = $this->userWithRole('instructor');
        $workshop = $this->workshopCourse(['instructor_id' => $this->profileFor($instructor)->id]);

        $this->assertRefusedAsTeacher($this->enroll($instructor, $workshop));
        $this->assertStringContainsString('ورشة', (string) $this->enroll($instructor, $workshop)->json('message'));
    }

    public function test_the_rule_follows_the_teaching_assignment_not_the_role_name(): void
    {
        $techAdmin = $this->userWithRole('tech_admin');
        $courseD = $this->course(['instructor_id' => $this->profileFor($techAdmin)->id]);
        $superAdmin = $this->userWithRole('super_admin');
        $courseE = $this->course(['instructor_id' => $this->profileFor($superAdmin)->id]);

        $this->assertRefusedAsTeacher($this->enroll($techAdmin, $courseD));
        $this->assertRefusedAsTeacher($this->enroll($superAdmin, $courseE));
        $this->enroll($techAdmin, $courseE)->assertCreated();
        $this->enroll($superAdmin, $courseD)->assertCreated();
    }

    public function test_an_instructor_account_that_teaches_nothing_may_enroll_anywhere(): void
    {
        $withProfile = $this->userWithRole('instructor');
        $this->profileFor($withProfile);
        $withoutProfile = $this->userWithRole('instructor');
        $course = $this->course();

        $this->enroll($withProfile, $course)->assertCreated();
        $this->enroll($withoutProfile, $course)->assertCreated();
    }

    public function test_every_kind_of_teaching_assignment_counts(): void
    {
        $viaWorkshop = $this->userWithRole('instructor');
        $viaClass = $this->userWithRole('instructor');
        $viaSession = $this->userWithRole('instructor');
        $workshopCourse = $this->course();
        $classCourse = $this->course();
        $sessionCourse = $this->course();

        Workshop::factory()->create(['course_id' => $workshopCourse->id, 'instructor_id' => $this->profileFor($viaWorkshop)->id]);
        ClassGroup::create(['course_id' => $classCourse->id, 'instructor_id' => $this->profileFor($viaClass)->id, 'name' => 'مجموعة', 'level_code' => 'A1', 'capacity' => 10, 'status' => 'active']);
        LmsSession::create(['course_id' => $sessionCourse->id, 'instructor_id' => $this->profileFor($viaSession)->id, 'title' => 'جلسة', 'session_date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $this->assertRefusedAsTeacher($this->enroll($viaWorkshop, $workshopCourse));
        $this->assertRefusedAsTeacher($this->enroll($viaClass, $classCourse));
        $this->assertRefusedAsTeacher($this->enroll($viaSession, $sessionCourse));
    }

    public function test_direct_api_attempts_cannot_get_around_the_rule(): void
    {
        $instructor = $this->userWithRole('instructor');
        $course = $this->course(['instructor_id' => $this->profileFor($instructor)->id]);
        $payload = ['course_id' => $course->id, 'full_name' => 'x', 'email' => 'someone@example.com', 'phone' => '+31600000000'];

        // The generic endpoint, with the account's own details or somebody else's e-mail typed in.
        $this->assertRefusedAsTeacher($this->actingAs($instructor, 'sanctum')->postJson('/api/register', ['course_id' => $course->id]));
        $this->assertRefusedAsTeacher($this->actingAs($instructor, 'sanctum')->postJson('/api/register', $payload));

        // Signing out and registering with the instructor's e-mail.
        $this->app['auth']->forgetGuards();
        $guest = $this->postJson('/api/register', ['email' => mb_strtoupper($instructor->email)] + $payload);
        $this->assertRefusedAsTeacher($guest);

        // Paid checkout of a course they teach.
        $paid = Course::factory()->paid(40)->create(['registration_open' => true, 'status' => 'published', 'instructor_id' => $instructor->instructorProfile->id]);
        $this->assertRefusedAsTeacher($this->actingAs($instructor, 'sanctum')->postJson("/api/courses/{$paid->id}/checkout", []));

        $this->assertSame(0, Registration::where('course_id', $course->id)->count());
    }

    public function test_an_admin_cannot_enroll_a_teacher_into_their_own_course_and_never_changes_anyones_role(): void
    {
        $admin = $this->userWithRole('admin');
        $teacher = $this->userWithRole('instructor');
        $course = $this->course(['instructor_id' => $this->profileFor($teacher)->id]);
        $volunteer = $this->userWithRole('volunteer');

        $this->assertRefusedAsTeacher($this->actingAs($admin, 'sanctum')->postJson("/api/admin/courses/{$course->id}/students", ['user_id' => $teacher->id]));

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/courses/{$course->id}/students", ['user_id' => $volunteer->id])->assertCreated();
        $this->assertSame('volunteer', $volunteer->fresh()->role, 'adding a volunteer to a course does not make them a student');
        $this->assertEnrolled($volunteer, $course);
    }

    public function test_a_learning_path_that_contains_a_course_the_user_teaches_is_refused(): void
    {
        $instructor = $this->userWithRole('instructor');
        $taught = $this->course(['instructor_id' => $this->profileFor($instructor)->id]);
        $path = LearningPath::create([
            'title' => 'مسار', 'slug' => 'mسار-'.uniqid(), 'status' => 'published', 'enrollment_open' => true,
            'price' => 0, 'currency' => 'EUR', 'duration' => 1, 'duration_unit' => 'weeks', 'language' => 'ar', 'level' => 'beginner',
            'short_description' => 'x', 'full_description' => 'x',
        ]);
        $path->courses()->attach($taught->id, ['sort_order' => 1]);

        $this->assertRefusedAsTeacher($this->actingAs($instructor, 'sanctum')->postJson("/api/learning-paths/{$path->slug}/enroll"));

        $other = $this->userWithRole('hr_manager');
        $this->actingAs($other, 'sanctum')->postJson("/api/learning-paths/{$path->slug}/enroll")->assertSuccessful();
    }

    // ── what stays as it was ─────────────────────────────────────────────────

    public function test_existing_student_enrollment_is_unchanged_including_duplicate_protection_and_closed_registration(): void
    {
        $student = $this->userWithRole('student');
        $course = $this->course();

        $this->enroll($student, $course)->assertCreated();
        $this->enroll($student, $course)->assertStatus(409);
        $this->assertSame(1, Registration::where('user_id', $student->id)->where('course_id', $course->id)->count());

        // Business rules that were there before still apply to everyone: a closed paid course refuses checkout.
        $closed = Course::factory()->paid(40)->create(['registration_open' => false, 'status' => 'published']);
        $this->actingAs($student, 'sanctum')->postJson("/api/courses/{$closed->id}/checkout", [])->assertStatus(422)->assertJsonPath('code', 'registration_closed');
    }

    public function test_the_learner_only_ever_sees_their_own_enrollments(): void
    {
        $a = $this->userWithRole('partner');
        $b = $this->userWithRole('finance_manager');
        $course = $this->course();
        $this->enroll($a, $course)->assertCreated();

        $this->assertSame([], $this->actingAs($b, 'sanctum')->getJson('/api/student/registrations')->assertOk()->json('data'));
        $this->assertCount(1, $this->actingAs($a, 'sanctum')->getJson('/api/student/registrations')->assertOk()->json('data'));
    }

    public function test_an_instructor_sees_what_they_teach_separately_from_what_they_attend(): void
    {
        $instructor = $this->userWithRole('instructor');
        $teaching = $this->course(['instructor_id' => $this->profileFor($instructor)->id]);
        $attending = $this->course();
        $this->enroll($instructor, $attending)->assertCreated();

        $taught = $this->actingAs($instructor, 'sanctum')->getJson('/api/instructor/courses')->assertOk()->json('data');
        $attended = $this->actingAs($instructor, 'sanctum')->getJson('/api/student/registrations')->assertOk()->json('data');

        $this->assertSame([$teaching->id], array_column($taught, 'id'));
        $this->assertSame([$attending->id], array_column($attended, 'course_id'));
    }

    // ── what a learner of any role can do with what they are enrolled in ─────

    #[DataProvider('everyRole')]
    public function test_an_enrolled_learner_of_any_role_may_submit_assignments_of_their_course_and_only_theirs(string $role): void
    {
        $user = $this->userWithRole($role);
        $mine = $this->course();
        $other = $this->course();
        $this->enroll($user, $mine)->assertCreated();
        $assignment = Assignment::create(['course_id' => $mine->id, 'title' => 'واجب', 'status' => 'active', 'max_score' => 10]);
        $foreign = Assignment::create(['course_id' => $other->id, 'title' => 'واجب آخر', 'status' => 'active', 'max_score' => 10]);

        $this->assertTrue(Gate::forUser($user)->allows('submit', $assignment));
        if (! in_array($role, ['super_admin', 'tech_admin'], true)) { // root authority (Gate::before) is the platform's own, older rule
            $this->assertFalse(Gate::forUser($user)->allows('submit', $foreign), 'having a role never opens the assignments of another course for submission');
        }
    }

    #[DataProvider('everyRole')]
    public function test_a_certificate_belongs_to_its_owner_whatever_their_role(string $role): void
    {
        $owner = $this->userWithRole($role);
        $stranger = $this->userWithRole('student');
        $certificate = Certificate::factory()->create(['user_id' => $owner->id, 'status' => 'issued']);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $certificate));
        $this->assertTrue(Gate::forUser($owner)->allows('download', $certificate));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $certificate));
        $this->assertFalse(Gate::forUser($stranger)->allows('download', $certificate));

        $ids = array_column($this->actingAs($owner, 'sanctum')->getJson('/api/student/certificates')->assertOk()->json('data'), 'id');
        $this->assertSame([$certificate->id], $ids);
    }

    #[DataProvider('everyRole')]
    public function test_the_meeting_link_is_shown_to_an_enrolled_learner_of_any_role_and_to_nobody_else(string $role): void
    {
        $enrolled = $this->userWithRole($role);
        $outsider = $this->userWithRole('student');
        $course = $this->course(['meeting_link' => 'https://meet.example/room-1', 'is_online' => true]);
        if (! in_array($role, ['admin', 'super_admin', 'tech_admin', 'executive_admin', 'instructor', 'teacher', 'programs_manager'], true)) {
            $this->assertNull($this->linkSeenBy($outsider, $course));
            $this->assertNull($this->linkSeenBy($enrolled, $course), 'not enrolled yet: no link');
        }

        $this->enroll($enrolled, $course)->assertCreated();

        $this->assertSame('https://meet.example/room-1', $this->linkSeenBy($enrolled, $course->fresh()));
        if (! in_array($role, ['admin', 'super_admin', 'tech_admin', 'executive_admin', 'instructor', 'teacher', 'programs_manager'], true)) {
            $this->assertNull($this->linkSeenBy($outsider, $course->fresh()));
        }
    }

    private function linkSeenBy(User $user, Course $course): ?string
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        return (new CourseResource($course))->toArray($request)['meeting_link'];
    }
}
