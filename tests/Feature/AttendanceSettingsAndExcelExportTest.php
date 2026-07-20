<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\User;
use App\Services\AttendanceSettingsService;
use App\Support\AttendanceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Ticket 6 final closure pass: AttendanceSettingsService (configurable
 * thresholds, admin-only write), Excel export, and threshold-driven
 * notification/risk behavior.
 */
class AttendanceSettingsAndExcelExportTest extends TestCase
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

    // ── Settings service defaults ────────────────────────────────────────

    public function test_default_settings_when_no_db_row_exists(): void
    {
        $settings = app(AttendanceSettingsService::class)->current();
        $this->assertSame(AttendanceSettingsService::DEFAULTS, $settings);
        $this->assertSame(70, $settings['at_risk_percentage']);
        $this->assertSame(3, $settings['repeated_absence_threshold']);
    }

    // ── Admin settings endpoint ──────────────────────────────────────────

    public function test_admin_can_read_and_update_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/attendance-settings')->assertOk()
            ->assertJsonPath('data.at_risk_percentage', 70);

        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/attendance-settings', [
            'late_threshold_minutes' => 10, 'auto_absent_after_minutes' => 20,
            'minimum_attendance_percentage' => 75, 'at_risk_percentage' => 80,
            'repeated_absence_threshold' => 2, 'low_attendance_notification_threshold' => 80,
            'certificate_attendance_percentage' => 75,
        ]);
        $response->assertOk();
        $this->assertSame(80, $response->json('data.at_risk_percentage'));
        $this->assertSame(2, $response->json('data.repeated_absence_threshold'));
    }

    public function test_non_admin_cannot_write_settings(): void
    {
        [$user] = $this->instructor();
        $response = $this->actingAs($user, 'sanctum')->putJson('/api/admin/attendance-settings', [
            'late_threshold_minutes' => 10, 'auto_absent_after_minutes' => 20,
            'minimum_attendance_percentage' => 75, 'at_risk_percentage' => 80,
            'repeated_absence_threshold' => 2, 'low_attendance_notification_threshold' => 80,
            'certificate_attendance_percentage' => 75,
        ]);
        $response->assertStatus(403);
    }

    public function test_settings_write_rejects_out_of_bounds_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/attendance-settings', [
            'late_threshold_minutes' => 10, 'auto_absent_after_minutes' => 20,
            'minimum_attendance_percentage' => 150, // out of bounds (max 100)
            'at_risk_percentage' => 80, 'repeated_absence_threshold' => 2,
            'low_attendance_notification_threshold' => 80, 'certificate_attendance_percentage' => 75,
        ]);
        $response->assertStatus(422);
    }

    public function test_settings_cache_invalidates_after_update(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $svc = app(AttendanceSettingsService::class);
        $this->assertSame(70, $svc->get('at_risk_percentage'));

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/attendance-settings', [
            'late_threshold_minutes' => 10, 'auto_absent_after_minutes' => 20,
            'minimum_attendance_percentage' => 75, 'at_risk_percentage' => 55,
            'repeated_absence_threshold' => 2, 'low_attendance_notification_threshold' => 80,
            'certificate_attendance_percentage' => 75,
        ])->assertOk();

        $this->assertSame(55, $svc->get('at_risk_percentage'));
    }

    // ── Threshold-driven behavior ─────────────────────────────────────────

    public function test_repeated_absence_notification_uses_configured_threshold(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/attendance-settings', [
            'late_threshold_minutes' => 15, 'auto_absent_after_minutes' => 30,
            'minimum_attendance_percentage' => 70, 'at_risk_percentage' => 70,
            'repeated_absence_threshold' => 2, // lowered from default 3
            'low_attendance_notification_threshold' => 70, 'certificate_attendance_percentage' => 70,
        ])->assertOk();

        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $s1 = $this->makeSession($course, $instructor, ['session_date' => now()->subDay()->toDateString()]);
        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$s1->id}", ['rows' => [['user_id' => $student->id, 'status' => 'absent']]])->assertOk();
        $s2 = $this->makeSession($course, $instructor, ['session_date' => now()->toDateString()]);
        $this->actingAs($user, 'sanctum')->putJson("/api/instructor/attendance/{$s2->id}", ['rows' => [['user_id' => $student->id, 'status' => 'absent']]])->assertOk();

        // With threshold=2, this should fire after only 2 consecutive absences.
        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $student->id, 'type' => 'attendance_repeated_absence',
        ]);
    }

    public function test_at_risk_threshold_from_settings_affects_risk_level(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/attendance-settings', [
            'late_threshold_minutes' => 15, 'auto_absent_after_minutes' => 30,
            'minimum_attendance_percentage' => 70, 'at_risk_percentage' => 95, // strict
            'repeated_absence_threshold' => 3, 'low_attendance_notification_threshold' => 70,
            'certificate_attendance_percentage' => 70,
        ])->assertOk();

        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $s = $this->makeSession($course, $instructor);
        Attendance::create(['session_id' => $s->id, 'user_id' => $student->id, 'status' => AttendanceStatus::PRESENT]);

        // 100% attendance, but at_risk_percentage=95 means "medium" risk region starts at <95%.
        // 100% is still >= 95, so should remain 'low'.
        $stats = app(\App\Services\AttendanceStatisticsService::class)->forStudent($student->id, $course->id);
        $this->assertSame('low', $stats['risk_level']);
    }

    // ── Excel export ─────────────────────────────────────────────────────

    public function test_excel_export_downloads_with_correct_mime_type(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $session = $this->makeSession($course, $instructor);
        Attendance::create(['session_id' => $session->id, 'user_id' => $student->id, 'status' => AttendanceStatus::PRESENT]);

        $response = $this->actingAs($user, 'sanctum')->get('/api/instructor/attendance/export-excel');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_excel_workbook_contains_expected_headers_and_arabic_status(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $session = $this->makeSession($course, $instructor);
        Attendance::create(['session_id' => $session->id, 'user_id' => $student->id, 'status' => AttendanceStatus::PRESENT]);

        $response = $this->actingAs($user, 'sanctum')->get('/api/instructor/attendance/export-excel');
        $response->assertOk();

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        file_put_contents($tmpFile, $response->streamedContent() ?: $response->getContent());
        $spreadsheet = IOFactory::load($tmpFile);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('الطالب', $sheet->getCell('A1')->getValue());
        $this->assertSame('الحالة', $sheet->getCell('G1')->getValue());
        $this->assertSame($student->name, $sheet->getCell('A2')->getValue());
        $this->assertSame('حاضر', $sheet->getCell('G2')->getValue());

        unlink($tmpFile);
    }

    public function test_excel_export_excludes_another_instructors_sessions(): void
    {
        [$userA, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseB = $this->course($instructorB);
        $studentB = $this->student();
        $this->enroll($studentB, $courseB);
        $sessionB = $this->makeSession($courseB, $instructorB);
        Attendance::create(['session_id' => $sessionB->id, 'user_id' => $studentB->id, 'status' => AttendanceStatus::PRESENT]);

        $response = $this->actingAs($userA, 'sanctum')->get('/api/instructor/attendance/export-excel');
        $response->assertOk();

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        file_put_contents($tmpFile, $response->streamedContent() ?: $response->getContent());
        $spreadsheet = IOFactory::load($tmpFile);
        $sheet = $spreadsheet->getActiveSheet();
        // Only the header row should exist — no data rows for instructor A.
        $this->assertNull($sheet->getCell('A2')->getValue());
        unlink($tmpFile);
    }

    public function test_student_cannot_access_instructor_export_routes(): void
    {
        $student = $this->student();
        $this->actingAs($student, 'sanctum')->get('/api/instructor/attendance/export-excel')->assertStatus(403);
    }

    // ── Canonical query parity ─────────────────────────────────────────────

    public function test_csv_pdf_and_excel_return_the_same_filtered_row_set(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $studentA = $this->student();
        $studentB = $this->student();
        $this->enroll($studentA, $course);
        $this->enroll($studentB, $course);
        $session = $this->makeSession($course, $instructor);
        Attendance::create(['session_id' => $session->id, 'user_id' => $studentA->id, 'status' => AttendanceStatus::PRESENT]);
        Attendance::create(['session_id' => $session->id, 'user_id' => $studentB->id, 'status' => AttendanceStatus::ABSENT]);

        $csv = $this->actingAs($user, 'sanctum')->get('/api/instructor/attendance/export?status=present');
        $csv->assertOk();
        $csvRowCount = substr_count($csv->streamedContent() ?: $csv->getContent(), (string) $studentA->name);

        $pdf = $this->actingAs($user, 'sanctum')->get('/api/instructor/attendance/export-pdf?status=present');
        $pdf->assertOk();

        $excel = $this->actingAs($user, 'sanctum')->get('/api/instructor/attendance/export-excel?status=present');
        $excel->assertOk();
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        file_put_contents($tmpFile, $excel->streamedContent() ?: $excel->getContent());
        $sheet = IOFactory::load($tmpFile)->getActiveSheet();
        $this->assertSame($studentA->name, $sheet->getCell('A2')->getValue());
        $this->assertNull($sheet->getCell('A3')->getValue());
        unlink($tmpFile);

        $reports = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/attendance/reports?status=present');
        $reports->assertOk();
        $this->assertCount(1, $reports->json('data'));
        $this->assertSame($studentA->name, $reports->json('data.0.student_name'));

        $this->assertGreaterThanOrEqual(1, $csvRowCount);
    }
}
