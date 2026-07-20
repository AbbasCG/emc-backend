<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\User;
use App\Services\AttendanceStatisticsService;
use App\Support\AttendanceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 6 finalization pass: AttendanceStatisticsService (streaks/risk),
 * student summary endpoint, instructor reports, and PDF export.
 */
class AttendanceStatisticsAndReportsTest extends TestCase
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

    private function makeSession(Course $course, Instructor $instructor, string $date): LmsSession
    {
        return LmsSession::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'title' => "S-{$date}", 'session_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);
    }

    // ── Statistics service ──────────────────────────────────────────────

    public function test_empty_history_returns_zeroed_statistics(): void
    {
        $svc = app(AttendanceStatisticsService::class);
        $stats = $svc->forStudent(999999);
        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['attendance_percentage']);
        $this->assertSame('low', $stats['risk_level']); // 0% but no absence streak — not misclassified as "high" by streak alone
    }

    public function test_percentage_counts_present_and_late_matching_student_progress_service(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $s1 = $this->makeSession($course, $instructor, '2026-08-01');
        $s2 = $this->makeSession($course, $instructor, '2026-08-02');
        $s3 = $this->makeSession($course, $instructor, '2026-08-03');
        $s4 = $this->makeSession($course, $instructor, '2026-08-04');
        Attendance::create(['session_id' => $s1->id, 'user_id' => $student->id, 'status' => AttendanceStatus::PRESENT]);
        Attendance::create(['session_id' => $s2->id, 'user_id' => $student->id, 'status' => AttendanceStatus::LATE]);
        Attendance::create(['session_id' => $s3->id, 'user_id' => $student->id, 'status' => AttendanceStatus::ABSENT]);
        Attendance::create(['session_id' => $s4->id, 'user_id' => $student->id, 'status' => AttendanceStatus::EXCUSED]);

        $stats = app(AttendanceStatisticsService::class)->forStudent($student->id, $course->id);
        // present + late = 2 of 4 = 50% (matches StudentProgressService's own present+late definition).
        $this->assertSame(50, $stats['attendance_percentage']);
    }

    public function test_current_absence_streak_counts_consecutive_trailing_absences(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $dates = ['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'];
        $statuses = [AttendanceStatus::PRESENT, AttendanceStatus::ABSENT, AttendanceStatus::ABSENT, AttendanceStatus::ABSENT];
        foreach ($dates as $i => $date) {
            $s = $this->makeSession($course, $instructor, $date);
            Attendance::create(['session_id' => $s->id, 'user_id' => $student->id, 'status' => $statuses[$i]]);
        }

        $stats = app(AttendanceStatisticsService::class)->forStudent($student->id, $course->id);
        $this->assertSame(3, $stats['current_absence_streak']);
        $this->assertSame(0, $stats['current_attendance_streak']);
        $this->assertSame('high', $stats['risk_level']); // >=3 consecutive absences
    }

    public function test_streak_resets_after_a_present_record(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $dates = ['2026-08-01', '2026-08-02', '2026-08-03'];
        $statuses = [AttendanceStatus::ABSENT, AttendanceStatus::ABSENT, AttendanceStatus::PRESENT];
        foreach ($dates as $i => $date) {
            $s = $this->makeSession($course, $instructor, $date);
            Attendance::create(['session_id' => $s->id, 'user_id' => $student->id, 'status' => $statuses[$i]]);
        }

        $stats = app(AttendanceStatisticsService::class)->forStudent($student->id, $course->id);
        $this->assertSame(1, $stats['current_attendance_streak']);
        $this->assertSame(0, $stats['current_absence_streak']);
        $this->assertSame(2, $stats['longest_absence_streak']);
    }

    public function test_same_day_rows_ordered_deterministically_by_id(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $s1 = $this->makeSession($course, $instructor, '2026-08-01');
        $s2 = LmsSession::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'title' => 'S-same-day-2', 'session_date' => '2026-08-01', 'start_time' => '14:00', 'end_time' => '15:00', 'status' => 'scheduled',
        ]);
        $a1 = Attendance::create(['session_id' => $s1->id, 'user_id' => $student->id, 'status' => AttendanceStatus::ABSENT]);
        $a2 = Attendance::create(['session_id' => $s2->id, 'user_id' => $student->id, 'status' => AttendanceStatus::PRESENT]);

        $stats = app(AttendanceStatisticsService::class)->forStudent($student->id, $course->id);
        // Deterministic (by id, not row-scan order): a2 (higher id, present) is "most recent" -> current streak = attendance, not absence.
        $this->assertGreaterThan($a1->id, $a2->id);
        $this->assertSame(1, $stats['current_attendance_streak']);
    }

    public function test_one_record_history(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $s = $this->makeSession($course, $instructor, '2026-08-01');
        Attendance::create(['session_id' => $s->id, 'user_id' => $student->id, 'status' => AttendanceStatus::PRESENT]);

        $stats = app(AttendanceStatisticsService::class)->forStudent($student->id, $course->id);
        $this->assertSame(1, $stats['total']);
        $this->assertSame(100, $stats['attendance_percentage']);
        $this->assertSame(1, $stats['current_attendance_streak']);
    }

    // ── Student summary endpoint ────────────────────────────────────────

    public function test_student_summary_endpoint_derives_student_from_auth_not_request(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $s = $this->makeSession($course, $instructor, '2026-08-01');
        Attendance::create(['session_id' => $s->id, 'user_id' => $student->id, 'status' => AttendanceStatus::PRESENT]);

        // Attempt to forge a different user_id — must be ignored entirely (endpoint has no user_id param at all).
        $response = $this->actingAs($student, 'sanctum')->getJson('/api/student/attendance/summary?course_id=' . $course->id);
        $response->assertOk();
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_student_cannot_see_another_students_summary(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $studentA = $this->student();
        $studentB = $this->student();
        $this->enroll($studentA, $course);
        $this->enroll($studentB, $course);
        $s = $this->makeSession($course, $instructor, '2026-08-01');
        Attendance::create(['session_id' => $s->id, 'user_id' => $studentA->id, 'status' => AttendanceStatus::PRESENT]);

        $response = $this->actingAs($studentB, 'sanctum')->getJson('/api/student/attendance/summary?course_id=' . $course->id);
        $response->assertOk();
        $this->assertSame(0, $response->json('data.total'));
    }

    // ── Instructor reports ──────────────────────────────────────────────

    public function test_reports_endpoint_returns_backend_computed_summary(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $s = $this->makeSession($course, $instructor, now()->toDateString());
        Attendance::create(['session_id' => $s->id, 'user_id' => $student->id, 'status' => AttendanceStatus::PRESENT]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/attendance/reports');
        $response->assertOk();
        $response->assertJsonStructure(['summary' => ['total', 'attendance_percentage', 'risk_level'], 'data', 'meta']);
        $this->assertSame(1, $response->json('summary.total'));
    }

    public function test_reports_endpoint_filters_by_student(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $s1 = $this->student();
        $s2 = $this->student();
        $this->enroll($s1, $course);
        $this->enroll($s2, $course);
        $session = $this->makeSession($course, $instructor, now()->toDateString());
        Attendance::create(['session_id' => $session->id, 'user_id' => $s1->id, 'status' => AttendanceStatus::PRESENT]);
        Attendance::create(['session_id' => $session->id, 'user_id' => $s2->id, 'status' => AttendanceStatus::ABSENT]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/attendance/reports?student_id=' . $s1->id);
        $response->assertOk();
        $this->assertSame(1, $response->json('summary.total'));
        $this->assertSame('present', $response->json('data.0.status'));
    }

    public function test_reports_endpoint_cannot_be_forged_into_another_instructors_course(): void
    {
        [$userA, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseB = $this->course($instructorB);
        $studentB = $this->student();
        $this->enroll($studentB, $courseB);
        $session = $this->makeSession($courseB, $instructorB, now()->toDateString());
        Attendance::create(['session_id' => $session->id, 'user_id' => $studentB->id, 'status' => AttendanceStatus::PRESENT]);

        // Instructor A tries to filter into instructor B's course_id.
        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/instructor/attendance/reports?course_id=' . $courseB->id);
        $response->assertOk();
        $this->assertSame(0, $response->json('summary.total'));
    }

    // ── PDF export ───────────────────────────────────────────────────────

    public function test_pdf_export_returns_pdf_content_type(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $session = $this->makeSession($course, $instructor, now()->toDateString());
        Attendance::create(['session_id' => $session->id, 'user_id' => $student->id, 'status' => AttendanceStatus::PRESENT]);

        $response = $this->actingAs($user, 'sanctum')->get('/api/instructor/attendance/export-pdf');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }
}
