<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for two real bugs found in SessionAttendanceController::update():
 *  1. rows.*.user_id was only validated as `exists:users,id` — any user in the
 *     system, not just students enrolled in the session's course, could be
 *     marked present/absent/late/excused.
 *  2. class_group_id was never written on the created Attendance row, so
 *     InstructorClassController::groupAttendance() (which filters by
 *     class_group_id) silently showed nothing for sessions saved this way.
 *  3. Cancelled sessions could still have attendance recorded.
 */
class SessionAttendanceSecurityTest extends TestCase
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

    private function makeSession(Course $course, Instructor $instructor, array $overrides = []): LmsSession
    {
        return LmsSession::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'title' => 'Session 1', 'session_date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ], $overrides));
    }

    public function test_attendance_cannot_be_marked_for_a_non_enrolled_user(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $session = $this->makeSession($course, $instructor);

        $enrolledStudent = $this->student();
        $this->enroll($enrolledStudent, $course);

        $outsider = $this->student(); // not enrolled in this course at all

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$session->id}", [
            'attendances' => [
                ['user_id' => $enrolledStudent->id, 'status' => 'present'],
                ['user_id' => $outsider->id, 'status' => 'present'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('attendances', ['user_id' => $outsider->id, 'session_id' => $session->id]);
        $this->assertDatabaseMissing('attendances', ['user_id' => $enrolledStudent->id, 'session_id' => $session->id]);
    }

    public function test_attendance_saved_for_enrolled_student_stores_class_group_id(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class 01', 'capacity' => 10, 'status' => 'active',
        ]);
        $session = $this->makeSession($course, $instructor, ['class_group_id' => $group->id]);

        $student = $this->student();
        $this->enroll($student, $course);
        \App\Models\ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$session->id}", [
            'attendances' => [['user_id' => $student->id, 'status' => 'present']],
        ]);
        $response->assertOk();

        $this->assertDatabaseHas('attendances', [
            'user_id' => $student->id, 'session_id' => $session->id, 'class_group_id' => $group->id,
        ]);
    }

    public function test_cancelled_session_rejects_attendance(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $session = $this->makeSession($course, $instructor, ['status' => 'cancelled']);

        $student = $this->student();
        $this->enroll($student, $course);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$session->id}", [
            'attendances' => [['user_id' => $student->id, 'status' => 'present']],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('attendances', ['user_id' => $student->id, 'session_id' => $session->id]);
    }

    public function test_attendance_cannot_be_marked_for_a_student_in_a_different_class_same_course(): void
    {
        // Recovery gap: enrollment in the course was checked, but a session's
        // own class_group_id was never cross-checked against the student's
        // actual active ClassGroupStudent membership — a student enrolled in
        // the course but assigned to a DIFFERENT class than the session's
        // class could still be marked present/absent for that session.
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = \App\Models\ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'A', 'capacity' => 10, 'status' => 'active',
        ]);
        $groupB = \App\Models\ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B', 'capacity' => 10, 'status' => 'active',
        ]);
        $sessionForA = $this->makeSession($course, $instructor, ['class_group_id' => $groupA->id]);

        $studentInB = $this->student();
        $this->enroll($studentInB, $course); // enrolled in the course...
        \App\Models\ClassGroupStudent::create([
            'class_group_id' => $groupB->id, 'user_id' => $studentInB->id, // ...but only a member of class B
            'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$sessionForA->id}", [
            'attendances' => [['user_id' => $studentInB->id, 'status' => 'present']],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('attendances', ['user_id' => $studentInB->id, 'session_id' => $sessionForA->id]);
    }
}
