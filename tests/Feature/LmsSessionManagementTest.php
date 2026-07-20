<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupSchedule;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\User;
use App\Policies\LmsSessionPolicy;
use App\Services\ClassSessionGenerationService;
use App\Services\LmsSessionTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ticket 4, Block A/B/D coverage: canonical Resource shape, centralized
 * status transitions, LmsSessionPolicy (incl. the null-Instructor bypass
 * fix), recurring generation idempotency, calendar bounds, and student
 * class-scoped access.
 */
class LmsSessionManagementTest extends TestCase
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

    private function classGroup(Course $course, Instructor $instructor, array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1', 'capacity' => 20, 'status' => 'active',
        ], $overrides));
    }

    private function assign(ClassGroup $group, User $student): void
    {
        ClassGroupStudent::create(['class_group_id' => $group->id, 'user_id' => $student->id, 'status' => 'assigned', 'assigned_at' => now()]);
        Registration::create([
            'user_id' => $student->id, 'course_id' => $group->course_id,
            'full_name' => $student->name, 'email' => $student->email, 'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    // ── Transition matrix ──────────────────────────────────────────────────

    public function test_every_documented_legal_transition_is_allowed(): void
    {
        $svc = app(LmsSessionTransitionService::class);
        $pairs = [
            ['scheduled', 'live'], ['scheduled', 'cancelled'], ['scheduled', 'missed'],
            ['live', 'completed'], ['live', 'cancelled'],
            ['completed', 'archived'], ['cancelled', 'archived'], ['missed', 'archived'],
        ];
        foreach ($pairs as [$from, $to]) {
            $session = new LmsSession(['status' => $from]);
            $this->assertTrue($svc->canTransition($session, $to), "{$from} -> {$to} should be allowed");
        }
    }

    public function test_illegal_transitions_are_blocked(): void
    {
        $svc = app(LmsSessionTransitionService::class);
        $pairs = [
            ['completed', 'scheduled'], ['cancelled', 'live'], ['archived', 'scheduled'], ['missed', 'live'],
        ];
        foreach ($pairs as [$from, $to]) {
            $session = new LmsSession(['status' => $from]);
            $this->assertFalse($svc->canTransition($session, $to), "{$from} -> {$to} should be blocked");
        }
    }

    public function test_transition_throws_validation_exception_for_illegal_move(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $session = LmsSession::create([
            'course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id,
            'title' => 'S1', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
            'status' => 'archived',
        ]);

        $this->expectException(ValidationException::class);
        app(LmsSessionTransitionService::class)->transition($session, 'scheduled', $user);
    }

    public function test_transition_endpoint_rejects_illegal_transition_with_422(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $session = LmsSession::create([
            'course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id,
            'title' => 'S1', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/classes/{$group->id}/sessions/{$session->id}/transition", ['status' => 'scheduled']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('lms_sessions', ['id' => $session->id, 'status' => 'completed']);
    }

    public function test_transition_endpoint_applies_legal_transition(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $session = LmsSession::create([
            'course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id,
            'title' => 'S1', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/classes/{$group->id}/sessions/{$session->id}/transition", ['status' => 'live']);

        $response->assertOk();
        $this->assertDatabaseHas('lms_sessions', ['id' => $session->id, 'status' => 'live']);
    }

    // ── Policy ──────────────────────────────────────────────────────────────

    public function test_owning_instructor_can_view_session(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id, 'title' => 'S', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $this->assertTrue((new LmsSessionPolicy())->view($user, $session));
    }

    public function test_other_instructor_cannot_view_session(): void
    {
        [$userA, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $course = $this->course($instructorA);
        $group = $this->classGroup($course, $instructorA);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructorA->id, 'title' => 'S', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $userB = User::find($instructorB->user_id);
        $this->assertFalse((new LmsSessionPolicy())->view($userB, $session));
    }

    /** The specific regression test for the null-Instructor-profile bypass this pass closed. */
    public function test_instructor_role_user_without_instructor_profile_is_denied_not_bypassed(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id, 'title' => 'S', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $noProfileUser = User::factory()->create(['role' => 'instructor', 'is_active' => true]);

        $this->assertFalse((new LmsSessionPolicy())->view($noProfileUser, $session));
    }

    public function test_student_in_correct_class_can_view_session(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $student = $this->student();
        $this->assign($group, $student);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id, 'title' => 'S', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $this->assertTrue((new LmsSessionPolicy())->view($student, $session));
    }

    public function test_student_in_different_class_same_course_cannot_view_session(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);
        $studentB = $this->student();
        $this->assign($groupB, $studentB);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $groupA->id, 'instructor_id' => $instructor->id, 'title' => 'S', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $this->assertFalse((new LmsSessionPolicy())->view($studentB, $session));
    }

    public function test_removed_student_cannot_view_meeting_link(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $student = $this->student();
        ClassGroupStudent::create(['class_group_id' => $group->id, 'user_id' => $student->id, 'status' => 'removed', 'assigned_at' => now()]);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id, 'title' => 'S', 'meeting_url' => 'https://example.com/x', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $this->assertFalse((new LmsSessionPolicy())->viewMeetingLink($student, $session));
    }

    // ── CRUD ownership ─────────────────────────────────────────────────────

    public function test_create_derives_course_and_instructor_from_class_not_request(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/instructor/classes/{$group->id}/sessions", [
            'title' => 'S', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
            'course_id' => 999999, 'instructor_id' => 999999,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('lms_sessions', ['course_id' => $course->id, 'instructor_id' => $instructor->id, 'class_group_id' => $group->id]);
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/instructor/classes/{$group->id}/sessions", [
            'title' => 'S', 'session_date' => now()->toDateString(), 'start_time' => '11:00', 'end_time' => '10:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_delete_rejected_when_attendance_exists(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id, 'title' => 'S', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);
        \App\Models\Attendance::create(['session_id' => $session->id, 'class_group_id' => $group->id, 'user_id' => $this->student()->id, 'status' => 'present']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/instructor/classes/{$group->id}/sessions/{$session->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('lms_sessions', ['id' => $session->id]);
    }

    public function test_delete_allowed_for_scheduled_session_without_attendance(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id, 'title' => 'S', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/instructor/classes/{$group->id}/sessions/{$session->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('lms_sessions', ['id' => $session->id]);
    }

    // ── Recurrence ──────────────────────────────────────────────────────────

    public function test_weekly_generation_creates_sessions_on_matching_weekdays(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        ClassGroupSchedule::create(['class_group_id' => $group->id, 'day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '11:00', 'delivery_mode' => 'online', 'is_active' => true]);

        $from = \Carbon\Carbon::parse('2026-08-03'); // a Monday
        $to = $from->copy()->addWeeks(3);

        $result = app(ClassSessionGenerationService::class)->generate($group, $from, $to, $instructor->user_id ? User::find($instructor->user_id) : User::factory()->create());

        $this->assertSame(4, $result['created_count']);
    }

    public function test_multiple_weekdays_generate_multiple_sessions_per_week(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        ClassGroupSchedule::create(['class_group_id' => $group->id, 'day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '11:00', 'delivery_mode' => 'online', 'is_active' => true]);
        ClassGroupSchedule::create(['class_group_id' => $group->id, 'day_of_week' => 'wednesday', 'start_time' => '10:00', 'end_time' => '11:00', 'delivery_mode' => 'online', 'is_active' => true]);

        $from = \Carbon\Carbon::parse('2026-08-03'); // Monday
        $to = \Carbon\Carbon::parse('2026-08-05');   // same-week Wednesday — exactly 1 Monday + 1 Wednesday

        $result = app(ClassSessionGenerationService::class)->generate($group, $from, $to, User::factory()->create());

        $this->assertSame(2, $result['created_count']);
    }

    public function test_repeated_generation_is_idempotent(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        ClassGroupSchedule::create(['class_group_id' => $group->id, 'day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '11:00', 'delivery_mode' => 'online', 'is_active' => true]);

        $from = \Carbon\Carbon::parse('2026-08-03');
        $to = $from->copy()->addWeeks(2);
        $actor = User::factory()->create();

        $first = app(ClassSessionGenerationService::class)->generate($group, $from, $to, $actor);
        $second = app(ClassSessionGenerationService::class)->generate($group, $from, $to, $actor);

        $this->assertSame(3, $first['created_count']);
        $this->assertSame(0, $second['created_count']);
        $this->assertSame(3, $second['skipped_count']);
        $this->assertSame(3, LmsSession::where('class_group_id', $group->id)->count());
    }

    public function test_inactive_schedule_is_ignored(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        ClassGroupSchedule::create(['class_group_id' => $group->id, 'day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '11:00', 'delivery_mode' => 'online', 'is_active' => false]);

        $result = app(ClassSessionGenerationService::class)->generate($group, \Carbon\Carbon::parse('2026-08-03'), \Carbon\Carbon::parse('2026-08-17'), User::factory()->create());

        $this->assertSame(0, $result['created_count']);
    }

    public function test_generation_rejected_for_archived_class(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor, ['status' => 'archived']);
        ClassGroupSchedule::create(['class_group_id' => $group->id, 'day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '11:00', 'delivery_mode' => 'online', 'is_active' => true]);

        $result = app(ClassSessionGenerationService::class)->generate($group, \Carbon\Carbon::parse('2026-08-03'), \Carbon\Carbon::parse('2026-08-17'), User::factory()->create());

        $this->assertSame(0, $result['created_count']);
    }

    public function test_generation_preview_writes_nothing(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        ClassGroupSchedule::create(['class_group_id' => $group->id, 'day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '11:00', 'delivery_mode' => 'online', 'is_active' => true]);

        $preview = app(ClassSessionGenerationService::class)->preview($group, \Carbon\Carbon::parse('2026-08-03'), \Carbon\Carbon::parse('2026-08-17'));

        $this->assertCount(3, $preview);
        $this->assertSame(0, LmsSession::count());
    }

    // ── Calendar ────────────────────────────────────────────────────────────

    public function test_instructor_calendar_respects_date_range(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        LmsSession::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id, 'title' => 'In range', 'session_date' => '2026-08-05', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);
        LmsSession::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id, 'title' => 'Out of range', 'session_date' => '2026-09-05', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/instructor/sessions/calendar?from=2026-08-01&to=2026-08-31');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('In range'));
        $this->assertFalse($titles->contains('Out of range'));
    }

    public function test_instructor_calendar_excludes_another_instructors_sessions(): void
    {
        [$userA, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $groupA = $this->classGroup($courseA, $instructorA);
        $courseB = $this->course($instructorB);
        $groupB = $this->classGroup($courseB, $instructorB);
        LmsSession::create(['course_id' => $courseA->id, 'class_group_id' => $groupA->id, 'instructor_id' => $instructorA->id, 'title' => 'Mine', 'session_date' => '2026-08-05', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);
        LmsSession::create(['course_id' => $courseB->id, 'class_group_id' => $groupB->id, 'instructor_id' => $instructorB->id, 'title' => 'Theirs', 'session_date' => '2026-08-06', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/instructor/sessions/calendar?from=2026-08-01&to=2026-08-31');

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Mine'));
        $this->assertFalse($titles->contains('Theirs'));
    }

    public function test_instructor_calendar_requires_from_and_to(): void
    {
        [$user] = $this->instructor();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/sessions/calendar');
        $response->assertStatus(422);
    }

    // ── Student access ─────────────────────────────────────────────────────

    public function test_student_session_detail_returns_404_for_wrong_class(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);
        $studentB = $this->student();
        $this->assign($groupB, $studentB);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $groupA->id, 'instructor_id' => $instructor->id, 'title' => 'S', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $response = $this->actingAs($studentB, 'sanctum')->getJson("/api/student/sessions/{$session->id}");

        $response->assertStatus(404);
    }

    public function test_student_session_detail_returns_data_for_own_class(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $student = $this->student();
        $this->assign($group, $student);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id, 'title' => 'S', 'session_date' => now(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $response = $this->actingAs($student, 'sanctum')->getJson("/api/student/sessions/{$session->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $session->id);
    }

    public function test_student_calendar_scoped_to_own_class_membership(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);
        $studentA = $this->student();
        $this->assign($groupA, $studentA);
        LmsSession::create(['course_id' => $course->id, 'class_group_id' => $groupA->id, 'instructor_id' => $instructor->id, 'title' => 'Mine', 'session_date' => '2026-08-05', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);
        LmsSession::create(['course_id' => $course->id, 'class_group_id' => $groupB->id, 'instructor_id' => $instructor->id, 'title' => 'NotMine', 'session_date' => '2026-08-06', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        $response = $this->actingAs($studentA, 'sanctum')
            ->getJson('/api/student/sessions/calendar?from=2026-08-01&to=2026-08-31');

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Mine'));
        $this->assertFalse($titles->contains('NotMine'));
    }

    // ── Meeting link authorization ───────────────────────────────────────────

    public function test_meeting_link_hidden_from_unauthorized_student_in_resource(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);
        $studentB = $this->student();
        $this->assign($groupB, $studentB);
        $session = LmsSession::create(['course_id' => $course->id, 'class_group_id' => $groupA->id, 'instructor_id' => $instructor->id, 'title' => 'S', 'meeting_url' => 'https://example.com/meet', 'session_date' => '2026-08-05', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled']);

        // studentB is in the other class; sees the calendar item only if the
        // session were visible at all — here it is not their class, so the
        // API correctly returns nothing for it. Directly assert the Resource
        // never leaks the link even if constructed anyway.
        $this->actingAs($studentB, 'sanctum');
        $resource = \App\Http\Resources\LmsSessionResource::make($session);
        $array = $resource->toArray(request());
        $this->assertNull($array['meeting']['url']);
    }
}
