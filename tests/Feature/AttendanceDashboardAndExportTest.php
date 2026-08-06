<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use App\Support\AttendanceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 6: attendance dashboard, CSV export, and the repeated-absence /
 * low-attendance notifications — all reusing the existing instructor
 * ownership scoping and StudentProgress.attendance_percentage (never
 * recalculated independently).
 */
class AttendanceDashboardAndExportTest extends TestCase
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

    private function makeSession(Course $course, Instructor $instructor, array $overrides = []): LmsSession
    {
        return LmsSession::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'title' => 'S1', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ], $overrides));
    }

    // ── Dashboard ────────────────────────────────────────────────────────

    public function test_dashboard_returns_structure_even_with_no_courses(): void
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/attendance/dashboard');
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['today_sessions', 'current_attendance_percentage', 'at_risk_students', 'top_attendance', 'worst_attendance']]);
    }

    public function test_dashboard_counts_todays_sessions(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $this->makeSession($course, $instructor, ['session_date' => now()->toDateString()]);
        $this->makeSession($course, $instructor, ['session_date' => now()->subWeek()->toDateString()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/attendance/dashboard');
        $response->assertOk();
        $this->assertSame(1, $response->json('data.today_sessions'));
    }

    public function test_dashboard_identifies_at_risk_students_from_existing_progress(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $lowStudent = $this->student();
        $this->enroll($lowStudent, $course);
        StudentProgress::create(['user_id' => $lowStudent->id, 'course_id' => $course->id, 'attendance_percentage' => 40, 'progress_percentage' => 40]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/attendance/dashboard');
        $response->assertOk();
        $atRiskIds = collect($response->json('data.at_risk_students'))->pluck('user_id');
        $this->assertTrue($atRiskIds->contains($lowStudent->id));
    }

    public function test_dashboard_excludes_another_instructors_data(): void
    {
        [$userA, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseB = $this->course($instructorB);
        $this->makeSession($courseB, $instructorB, ['session_date' => now()->toDateString()]);

        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/instructor/attendance/dashboard');
        $response->assertOk();
        $this->assertSame(0, $response->json('data.today_sessions'));
    }

    // ── Export ──────────────────────────────────────────────────────────

    public function test_export_returns_csv_with_expected_header(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $session = $this->makeSession($course, $instructor);
        Attendance::create(['session_id' => $session->id, 'user_id' => $student->id, 'status' => AttendanceStatus::PRESENT]);

        $response = $this->actingAs($user, 'sanctum')->get('/api/instructor/attendance/export');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString($student->name, $content);
        // Ticket 6 finalization: CSV now uses translated human-readable
        // Arabic status labels instead of the raw stored English value.
        $this->assertStringContainsString('حاضر', $content);
    }

    public function test_export_excludes_another_instructors_sessions(): void
    {
        [$userA, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $courseB = $this->course($instructorB);
        $studentB = $this->student();
        $this->enroll($studentB, $courseB);
        $sessionB = $this->makeSession($courseB, $instructorB);
        Attendance::create(['session_id' => $sessionB->id, 'user_id' => $studentB->id, 'status' => AttendanceStatus::PRESENT]);

        $response = $this->actingAs($userA, 'sanctum')->get('/api/instructor/attendance/export');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringNotContainsString($studentB->name, $content);
    }

    // ── Notifications ───────────────────────────────────────────────────

    public function test_repeated_absence_notification_fires_after_three_consecutive_absences(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        // Two prior absences already recorded directly (simulating past saves).
        $s1 = $this->makeSession($course, $instructor, ['session_date' => now()->subDays(2)->toDateString()]);
        $s2 = $this->makeSession($course, $instructor, ['session_date' => now()->subDay()->toDateString()]);
        Attendance::create(['session_id' => $s1->id, 'user_id' => $student->id, 'status' => AttendanceStatus::ABSENT]);
        Attendance::create(['session_id' => $s2->id, 'user_id' => $student->id, 'status' => AttendanceStatus::ABSENT]);

        $s3 = $this->makeSession($course, $instructor, ['session_date' => now()->toDateString()]);

        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$s3->id}", [
            'rows' => [['user_id' => $student->id, 'status' => 'absent']],
        ])->assertOk();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $student->id, 'type' => 'attendance_repeated_absence',
        ]);
    }

    public function test_no_repeated_absence_notification_when_not_three_in_a_row(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $session = $this->makeSession($course, $instructor);

        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$session->id}", [
            'rows' => [['user_id' => $student->id, 'status' => 'absent']],
        ])->assertOk();

        $this->assertDatabaseMissing('platform_notifications', [
            'user_id' => $student->id, 'type' => 'attendance_repeated_absence',
        ]);
    }

    public function test_class_attendance_completed_notification_fires_once_on_finalization(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $session = $this->makeSession($course, $instructor);

        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$session->id}", [
            'rows' => [['user_id' => $student->id, 'status' => 'present']],
        ])->assertOk();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $user->id, 'type' => 'class_attendance_completed',
        ]);
        $this->assertDatabaseCount('platform_notifications', 2); // attendanceMarked (student) + classAttendanceCompleted (instructor), not duplicated
    }

    public function test_low_attendance_warning_fires_only_on_the_crossing_not_every_subsequent_absence(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        // First 10 sessions: all present (well above 70%).
        for ($i = 1; $i <= 10; $i++) {
            $s = $this->makeSession($course, $instructor, ['session_date' => now()->subDays(20 - $i)->toDateString()]);
            \App\Models\Attendance::create(['session_id' => $s->id, 'user_id' => $student->id, 'status' => 'present']);
        }
        \App\Models\StudentProgress::create(['user_id' => $student->id, 'course_id' => $course->id, 'attendance_percentage' => 100, 'progress_percentage' => 100]);

        // Enough consecutive absences to cross below 70% for the first time.
        $s1 = $this->makeSession($course, $instructor, ['session_date' => now()->subDays(5)->toDateString()]);
        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$s1->id}", ['rows' => [['user_id' => $student->id, 'status' => 'absent']]])->assertOk();
        $s2 = $this->makeSession($course, $instructor, ['session_date' => now()->subDays(4)->toDateString()]);
        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$s2->id}", ['rows' => [['user_id' => $student->id, 'status' => 'absent']]])->assertOk();
        $s3 = $this->makeSession($course, $instructor, ['session_date' => now()->subDays(3)->toDateString()]);
        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$s3->id}", ['rows' => [['user_id' => $student->id, 'status' => 'absent']]])->assertOk();
        $s4 = $this->makeSession($course, $instructor, ['session_date' => now()->subDays(2)->toDateString()]);
        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$s4->id}", ['rows' => [['user_id' => $student->id, 'status' => 'absent']]])->assertOk();
        // 10 present / 14 total = 71% — not yet crossed below 70%.
        $s5 = $this->makeSession($course, $instructor, ['session_date' => now()->subDays(1)->toDateString()]);
        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$s5->id}", ['rows' => [['user_id' => $student->id, 'status' => 'absent']]])->assertOk();
        // 10 present / 15 total = 66% — crosses below 70% for the first time.

        $this->assertSame(1, \App\Models\PlatformNotification::where('user_id', $student->id)->where('type', 'attendance_low_warning')->count());

        // One more absence while still below 70% must NOT resend the warning.
        $s6 = $this->makeSession($course, $instructor, ['session_date' => now()->toDateString()]);
        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$s6->id}", ['rows' => [['user_id' => $student->id, 'status' => 'absent']]])->assertOk();
        $this->assertSame(1, \App\Models\PlatformNotification::where('user_id', $student->id)->where('type', 'attendance_low_warning')->count());
    }
}
