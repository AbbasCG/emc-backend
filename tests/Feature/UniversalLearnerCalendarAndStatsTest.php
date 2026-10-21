<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Services\LearnerCounts;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two integration points of "learning is not a role":
 *   - the general /calendar shows every account the sessions of what it is enrolled in, and hands out a join link only to the people
 *     enrolled in that course;
 *   - the administrative learner counts mean people actually learning, not accounts that hold the student role.
 * Plus the English Institute module, which keeps its own student-only gate.
 */
class UniversalLearnerCalendarAndStatsTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://meet.example/private-room';

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

    private function courseWithSession(string $title): array
    {
        $teacher = $this->userWithRole('instructor');
        $profile = Instructor::create(['user_id' => $teacher->id, 'name' => $teacher->name]);
        $course = Course::factory()->free()->create(['instructor_id' => $profile->id, 'registration_open' => true, 'status' => 'published', 'title' => $title]);
        $session = LmsSession::create([
            'course_id' => $course->id, 'instructor_id' => $profile->id, 'title' => "جلسة {$title}", 'session_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled', 'meeting_url' => self::LINK,
        ]);

        return [$course, $session];
    }

    /** @return array<string, array<string, mixed>> the calendar's events keyed by id */
    private function calendarOf(User $user): array
    {
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/calendar/events?start_date='.now()->toDateString().'&end_date='.now()->addDays(10)->toDateString())->assertOk();

        return collect($response->json('data'))->keyBy('id')->all();
    }

    // ── calendar ─────────────────────────────────────────────────────────────

    #[DataProvider('everyRole')]
    public function test_every_role_sees_the_sessions_of_what_it_is_enrolled_in_with_the_join_link(string $role): void
    {
        [$course, $session] = $this->courseWithSession('أ');
        $user = $this->userWithRole($role);
        $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$course->id}/register", [])->assertCreated();

        $events = $this->calendarOf($user);

        $this->assertArrayHasKey("lms_session_{$session->id}", $events, "[{$role}] must see an enrolled session");
        $this->assertSame(self::LINK, $events["lms_session_{$session->id}"]['meeting_link']);
        $this->assertTrue($events["lms_session_{$session->id}"]['can_join']);
    }

    #[DataProvider('everyRole')]
    public function test_a_join_link_is_never_given_to_someone_who_is_not_enrolled_in_that_course(string $role): void
    {
        [, $foreign] = $this->courseWithSession('ب');
        $user = $this->userWithRole($role);

        $event = $this->calendarOf($user)["lms_session_{$foreign->id}"] ?? null;

        // Some roles see public sessions in their calendar (they always did); none sees the private link of one they are not in,
        // except the admin tier, which manages every session.
        if (! in_array($role, ['admin', 'super_admin', 'tech_admin'], true) && $event !== null) {
            $this->assertNull($event['meeting_link'], "[{$role}] must not receive another course's join link");
            $this->assertFalse($event['can_join']);
        }
        $this->assertStringNotContainsString(self::LINK, json_encode($this->actingAs($user, 'sanctum')->getJson('/api/calendar/export.ics')->getContent()) ?: '', 'the ICS export carries no foreign link either');
    }

    public function test_a_session_of_a_course_not_paid_for_is_not_shown_with_its_link(): void
    {
        [$course, $session] = $this->courseWithSession('ج');
        $user = $this->userWithRole('volunteer');
        Registration::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'pending_payment']);

        $event = $this->calendarOf($user)["lms_session_{$session->id}"] ?? null;

        $this->assertTrue($event === null || $event['meeting_link'] === null);
    }

    public function test_an_instructor_sees_what_they_teach_and_what_they_attend_without_duplicates(): void
    {
        [$taught, $teachingSession] = $this->courseWithSession('د');
        $instructor = User::find(Instructor::where('id', $taught->instructor_id)->value('user_id'));
        [$attended, $attendedSession] = $this->courseWithSession('هـ');
        $this->actingAs($instructor, 'sanctum')->postJson("/api/courses/{$attended->id}/register", [])->assertCreated();

        $response = $this->actingAs($instructor, 'sanctum')->getJson('/api/calendar/events?start_date='.now()->toDateString().'&end_date='.now()->addDays(10)->toDateString())->assertOk();
        $ids = array_column($response->json('data'), 'id');

        $this->assertContains("lms_session_{$teachingSession->id}", $ids);
        $this->assertContains("lms_session_{$attendedSession->id}", $ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'no event appears twice');
    }

    public function test_the_student_calendar_is_unchanged(): void
    {
        [$course, $session] = $this->courseWithSession('و');
        [, $foreign] = $this->courseWithSession('ز');
        $student = $this->userWithRole('student');
        $this->actingAs($student, 'sanctum')->postJson("/api/courses/{$course->id}/register", [])->assertCreated();

        $events = $this->calendarOf($student);

        $this->assertArrayHasKey("lms_session_{$session->id}", $events);
        $this->assertArrayNotHasKey("lms_session_{$foreign->id}", $events);
    }

    // ── learner counts ───────────────────────────────────────────────────────

    public function test_learner_counts_include_enrolled_accounts_of_every_role_and_not_idle_non_students(): void
    {
        $course = Course::factory()->free()->create();
        $student = $this->userWithRole('student');            // a student account counts even before enrolling
        $volunteer = $this->userWithRole('volunteer');        // enrolled -> a learner
        $partner = $this->userWithRole('partner');            // enrolled -> a learner
        $pending = $this->userWithRole('hr_manager');         // only a pending payment -> not a learner
        $idle = $this->userWithRole('finance_manager');       // never enrolled -> not a learner
        $inactive = User::factory()->create(['role' => 'volunteer', 'is_active' => false]);
        foreach ([$volunteer, $partner, $inactive] as $u) {
            Registration::factory()->create(['user_id' => $u->id, 'course_id' => $course->id, 'status' => 'registered']);
        }
        Registration::factory()->create(['user_id' => $pending->id, 'course_id' => $course->id, 'status' => 'pending_payment']);

        $counts = app(LearnerCounts::class);

        $this->assertSame(4, $counts->total());
        $this->assertSame(3, $counts->active());
        $this->assertEqualsCanonicalizing([$student->id, $volunteer->id, $partner->id, $inactive->id], $counts->query()->pluck('id')->all());
        $this->assertNotContains($idle->id, $counts->query()->pluck('id')->all());
        $this->assertNotContains($pending->id, $counts->query()->pluck('id')->all());
    }

    public function test_new_learners_follow_the_first_enrollment_for_non_students_and_the_account_for_students(): void
    {
        $course = Course::factory()->free()->create();
        $old = $this->userWithRole('volunteer');
        $thisMonth = $this->userWithRole('partner');
        $student = User::factory()->create(['role' => 'student', 'created_at' => now()->subMonths(5)]);
        $oldReg = Registration::factory()->create(['user_id' => $old->id, 'course_id' => $course->id, 'status' => 'registered']);
        $oldReg->forceFill(['created_at' => now()->subMonths(3)])->save();
        Registration::factory()->create(['user_id' => $thisMonth->id, 'course_id' => $course->id, 'status' => 'registered']);
        // Enrolling in a second course this month does not make the volunteer "new" again.
        $second = Course::factory()->free()->create();
        Registration::factory()->create(['user_id' => $old->id, 'course_id' => $second->id, 'status' => 'registered']);

        $counts = app(LearnerCounts::class);

        $this->assertSame(1, $counts->newBetween(now()->startOfMonth(), now()->endOfMonth()), 'only the partner is new this month');
        $this->assertSame(1, $counts->newBetween(now()->subMonths(5)->startOfMonth(), now()->subMonths(5)->endOfMonth()), 'the student, by account date');
        $this->assertSame(1, $counts->newBetween(now()->subMonths(3)->startOfMonth(), now()->subMonths(3)->endOfMonth()), 'the volunteer, by first enrollment');
        $this->assertNotNull($student);
    }

    public function test_the_admin_stats_endpoint_counts_learners_and_users_is_unchanged(): void
    {
        $admin = $this->userWithRole('admin');
        $course = Course::factory()->free()->create();
        $volunteer = $this->userWithRole('volunteer');
        $this->userWithRole('student');
        Registration::factory()->create(['user_id' => $volunteer->id, 'course_id' => $course->id, 'status' => 'registered']);
        $idle = $this->userWithRole('finance_manager');

        $data = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/stats')->assertOk()->json('data');

        $this->assertSame(2, $data['students']['total'], 'the student and the enrolled volunteer');
        $this->assertSame(User::count(), $data['users']['total'], 'the users card still counts every account');
        $this->assertSame(1, Registration::count());
        $this->assertNotNull($idle);
        $this->assertCount(6, $data['chart']);
    }

    // ── the separate English Institute module ────────────────────────────────

    #[DataProvider('everyRole')]
    public function test_the_institute_student_module_keeps_its_student_only_gate(string $role): void
    {
        $user = $this->userWithRole($role);

        $status = $this->actingAs($user, 'sanctum')->getJson('/api/institute/student/dashboard')->status();

        // super_admin and tech_admin pass every role check platform-wide; the student passes; nobody else does.
        $this->assertSame(in_array($role, ['student', 'super_admin', 'tech_admin'], true) ? 200 : 403, $status, "[{$role}] institute/student/dashboard");
    }

    public function test_the_institute_checkout_still_refuses_enrolling_in_what_the_user_teaches(): void
    {
        [$course] = $this->courseWithSession('ح');
        $teacher = User::find(Instructor::where('id', $course->instructor_id)->value('user_id'));
        $teacher->forceFill(['role' => 'student'])->save(); // a student account that also teaches (the only way through this gate)

        $this->actingAs($teacher, 'sanctum')->postJson('/api/institute/student/checkout', ['course_id' => $course->id])
            ->assertStatus(403)->assertJsonPath('code', 'instructor_teaches_program');
        $this->assertSame(0, Registration::where('user_id', $teacher->id)->count());
    }
}
