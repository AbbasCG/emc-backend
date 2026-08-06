<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseSession;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorClassWorkspaceTest extends TestCase
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

    private function classGroup(Course $course, Instructor $instructor, array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class 01', 'capacity' => 20, 'status' => 'active',
        ], $overrides));
    }

    private function assign(ClassGroup $group, User $student, string $status = 'assigned'): ClassGroupStudent
    {
        return ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => $status, 'assigned_at' => now(),
        ]);
    }

    public function test_assigned_student_appears_in_class_detail(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $student = $this->student();
        $this->assign($group, $student);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$group->id}/students");
        $response->assertOk();

        $row = collect($response->json('data.students'))->firstWhere('id', $student->id);
        $this->assertNotNull($row);
        $this->assertSame($student->name, $row['name']);
    }

    public function test_student_count_matches_returned_students(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $this->assign($group, $this->student());
        $this->assign($group, $this->student());

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$group->id}");
        $response->assertOk();

        $this->assertSame(2, $response->json('data.current_students_count'));
        $this->assertCount(2, $response->json('data.students'));
    }

    public function test_removed_membership_does_not_count_as_active(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $this->assign($group, $this->student(), 'assigned');
        $this->assign($group, $this->student(), 'removed');

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$group->id}");
        $response->assertOk();

        $this->assertSame(1, $response->json('data.current_students_count'));
    }

    public function test_instructor_cannot_access_another_instructors_class(): void
    {
        [, $ownerInstructor] = $this->instructor();
        [$otherUser, ] = $this->instructor();
        $course = $this->course($ownerInstructor);
        $group = $this->classGroup($course, $ownerInstructor);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/instructor/classes/{$group->id}")
            ->assertStatus(403);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/instructor/classes/{$group->id}/students")
            ->assertStatus(403);
    }

    public function test_class_students_endpoint_returns_only_class_members(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'B1-A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B1-B']);

        $studentA = $this->student();
        $studentB = $this->student();
        $this->assign($groupA, $studentA);
        $this->assign($groupB, $studentB);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$groupA->id}/students");
        $response->assertOk();

        $ids = collect($response->json('data.students'))->pluck('id');
        $this->assertTrue($ids->contains($studentA->id));
        $this->assertFalse($ids->contains($studentB->id));
    }

    public function test_class_sessions_endpoint_returns_only_class_sessions(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'B1-A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B1-B']);

        LmsSession::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id, 'instructor_id' => $instructor->id,
            'title' => 'Session for A', 'session_date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);
        LmsSession::create([
            'course_id' => $course->id, 'class_group_id' => $groupB->id, 'instructor_id' => $instructor->id,
            'title' => 'Session for B', 'session_date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$groupA->id}/sessions");
        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Session for A'));
        $this->assertFalse($titles->contains('Session for B'));
    }

    public function test_user_without_instructor_profile_cannot_mutate_another_instructors_class(): void
    {
        // Regression: store()/update()/destroy()/addStudent()/removeStudent()
        // used to guard with `if ($instructor && ...)`, which silently skipped
        // the ownership check entirely when the caller had no Instructor row
        // (e.g. an admin account) — letting them mutate any class group.
        [, $ownerInstructor] = $this->instructor();
        $course = $this->course($ownerInstructor);
        $group = $this->classGroup($course, $ownerInstructor);
        $intruder = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $victim = $this->student();

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/instructor/classes', ['course_id' => $course->id, 'name' => 'Hijacked'])
            ->assertStatus(403);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/instructor/classes/{$group->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/instructor/classes/{$group->id}/students", ['user_id' => $victim->id])
            ->assertStatus(403);

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/instructor/classes/{$group->id}/students/{$victim->id}")
            ->assertStatus(403);

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/instructor/classes/{$group->id}")
            ->assertStatus(403);
    }

    public function test_cannot_create_session_for_draft_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor, ['status' => 'draft']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/instructor/classes/{$group->id}/sessions", [
            'title' => 'Session 1', 'session_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00',
        ]);
        $response->assertStatus(422);
        $this->assertDatabaseMissing('lms_sessions', ['class_group_id' => $group->id]);
    }

    public function test_cannot_create_session_for_completed_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor, ['status' => 'completed']);

        $this->actingAs($user, 'sanctum')->postJson("/api/instructor/classes/{$group->id}/sessions", [
            'title' => 'Session 1', 'session_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00',
        ])->assertStatus(422);
    }

    public function test_cannot_record_attendance_for_draft_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor, ['status' => 'draft']);
        $session = LmsSession::create([
            'course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id,
            'title' => 'S', 'session_date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);
        $student = $this->student();
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$session->id}", [
            'attendances' => [['user_id' => $student->id, 'status' => 'present']],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('attendances', ['user_id' => $student->id, 'session_id' => $session->id]);
    }

    public function test_cannot_add_student_to_completed_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor, ['status' => 'completed']);
        $student = $this->student();

        $this->actingAs($user, 'sanctum')->postJson("/api/instructor/classes/{$group->id}/students", [
            'user_id' => $student->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('class_group_students', ['class_group_id' => $group->id, 'user_id' => $student->id]);
    }

    public function test_cannot_remove_student_from_archived_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor, ['status' => 'archived']);
        $student = $this->student();
        $this->assign($group, $student);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/instructor/classes/{$group->id}/students/{$student->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('class_group_students', [
            'class_group_id' => $group->id, 'user_id' => $student->id, 'status' => 'assigned',
        ]);
    }

    public function test_cannot_delete_completed_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor, ['status' => 'completed']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/instructor/classes/{$group->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('class_groups', ['id' => $group->id]);
    }

    public function test_cannot_edit_configuration_of_archived_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor, ['status' => 'archived']);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/instructor/classes/{$group->id}", ['name' => 'Renamed'])
            ->assertStatus(422);
    }

    public function test_status_only_transition_is_allowed_on_completed_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor, ['status' => 'completed']);

        // Reopening / archiving a completed class must remain possible even
        // though its configuration is otherwise frozen.
        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/instructor/classes/{$group->id}", ['status' => 'archived'])
            ->assertOk();

        $this->assertDatabaseHas('class_groups', ['id' => $group->id, 'status' => 'archived']);
    }

    public function test_class_detail_returns_permissions_and_next_session(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $activeGroup = $this->classGroup($course, $instructor, ['status' => 'active']);
        $draftGroup = $this->classGroup($course, $instructor, ['status' => 'draft', 'name' => 'Draft']);
        LmsSession::create([
            'course_id' => $course->id, 'class_group_id' => $activeGroup->id, 'instructor_id' => $instructor->id,
            'title' => 'Upcoming', 'session_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        $active = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$activeGroup->id}")->json('data');
        $this->assertTrue($active['permissions']['record_attendance']);
        $this->assertTrue($active['permissions']['create_session']);
        $this->assertNotNull($active['next_session']);
        $this->assertSame('Upcoming', $active['next_session']['title']);
        $this->assertArrayHasKey('pending_reviews', $active['counts']);
        $this->assertArrayHasKey('announcements', $active['counts']);

        $draft = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$draftGroup->id}")->json('data');
        $this->assertFalse($draft['permissions']['record_attendance']);
        $this->assertFalse($draft['permissions']['create_session']);
        $this->assertTrue($draft['permissions']['edit']); // draft is still editable
    }

    public function test_class_detail_attendance_and_progress_summaries_are_class_scoped(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);

        $studentA = $this->student();
        $this->assign($groupA, $studentA);
        $sessionA = LmsSession::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id, 'instructor_id' => $instructor->id,
            'title' => 'S', 'session_date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);
        Attendance::create([
            'session_id' => $sessionA->id, 'user_id' => $studentA->id,
            'class_group_id' => $groupA->id, 'status' => 'present',
        ]);
        \App\Models\StudentProgress::create([
            'user_id' => $studentA->id, 'course_id' => $course->id,
            'progress_percentage' => 80, 'status' => 'in_progress',
        ]);

        // Student in class B — must not affect class A's summaries.
        $studentB = $this->student();
        $this->assign($groupB, $studentB);
        Attendance::create([
            'session_id' => $sessionA->id, 'user_id' => $studentB->id,
            'class_group_id' => $groupB->id, 'status' => 'absent',
        ]);
        \App\Models\StudentProgress::create([
            'user_id' => $studentB->id, 'course_id' => $course->id,
            'progress_percentage' => 5, 'status' => 'in_progress',
        ]);

        $data = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$groupA->id}")->json('data');

        $this->assertSame(1, $data['attendance_summary']['present']);
        $this->assertSame(0, $data['attendance_summary']['absent']); // B's absent row must not leak into A
        $this->assertSame(100, $data['attendance_summary']['attendance_percentage']);

        $this->assertSame(1, $data['progress_summary']['students_total']);
        $this->assertSame(80, $data['progress_summary']['average_progress_percentage']); // not averaged with B's 5%
        $this->assertSame(0, $data['progress_summary']['at_risk_students']); // B's at-risk student excluded
    }

    public function test_class_materials_endpoint_returns_only_class_materials(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'B1-A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B1-B']);

        CourseMaterial::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id,
            'title' => 'Material for A', 'type' => 'pdf', 'is_visible' => true,
        ]);
        CourseMaterial::create([
            'course_id' => $course->id, 'class_group_id' => $groupB->id,
            'title' => 'Material for B', 'type' => 'pdf', 'is_visible' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$groupA->id}/materials");
        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Material for A'));
        $this->assertFalse($titles->contains('Material for B'));
    }

    public function test_class_assignments_endpoint_returns_only_class_assignments(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'B1-A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B1-B']);

        Assignment::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id,
            'title' => 'Homework for A', 'status' => 'published',
        ]);
        Assignment::create([
            'course_id' => $course->id, 'class_group_id' => $groupB->id,
            'title' => 'Homework for B', 'status' => 'published',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$groupA->id}/assignments");
        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Homework for A'));
        $this->assertFalse($titles->contains('Homework for B'));
    }

    public function test_instructor_can_create_a_session_for_own_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/instructor/classes/{$group->id}/sessions", [
            'title' => 'Session 1', 'session_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'meeting_url' => 'https://meet.example.com/x',
        ]);
        $response->assertStatus(201);

        $this->assertDatabaseHas('lms_sessions', [
            'class_group_id' => $group->id, 'course_id' => $course->id,
            'instructor_id' => $instructor->id, 'title' => 'Session 1', 'status' => 'scheduled',
        ]);
    }

    public function test_instructor_cannot_create_session_for_another_instructors_class(): void
    {
        [, $ownerInstructor] = $this->instructor();
        [$otherUser, ] = $this->instructor();
        $course = $this->course($ownerInstructor);
        $group = $this->classGroup($course, $ownerInstructor);

        $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/instructor/classes/{$group->id}/sessions", [
                'title' => 'Hijacked', 'session_date' => now()->addDay()->toDateString(),
                'start_time' => '10:00', 'end_time' => '11:00',
            ])
            ->assertStatus(403);
    }

    public function test_instructor_can_cancel_own_session(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $session = LmsSession::create([
            'course_id' => $course->id, 'class_group_id' => $group->id, 'instructor_id' => $instructor->id,
            'title' => 'S', 'session_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/instructor/classes/{$group->id}/sessions/{$session->id}", ['status' => 'cancelled']);
        $response->assertOk();

        $this->assertDatabaseHas('lms_sessions', ['id' => $session->id, 'status' => 'cancelled']);
    }

    public function test_instructor_cannot_update_session_belonging_to_different_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);
        $sessionForB = LmsSession::create([
            'course_id' => $course->id, 'class_group_id' => $groupB->id, 'instructor_id' => $instructor->id,
            'title' => 'S', 'session_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/instructor/classes/{$groupA->id}/sessions/{$sessionForB->id}", ['status' => 'cancelled'])
            ->assertStatus(404);
    }

    public function test_class_attendance_endpoint_returns_only_class_attendance(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'B1-A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B1-B']);

        $studentA = $this->student();
        $studentB = $this->student();
        $sessionA = LmsSession::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'title' => 'S', 'session_date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        Attendance::create([
            'session_id' => $sessionA->id, 'user_id' => $studentA->id,
            'class_group_id' => $groupA->id, 'status' => 'present',
        ]);
        Attendance::create([
            'session_id' => $sessionA->id, 'user_id' => $studentB->id,
            'class_group_id' => $groupB->id, 'status' => 'present',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$groupA->id}/attendance");
        $response->assertOk();

        $userIds = collect($response->json('data'))->pluck('user_id');
        $this->assertTrue($userIds->contains($studentA->id));
        $this->assertFalse($userIds->contains($studentB->id));
    }
}
