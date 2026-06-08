<?php

namespace Tests\Feature;

use App\Events\InstructorAssignedEvent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\PlatformNotification;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\PlacementTestQuestion;
use App\Models\Registration;
use App\Models\User;
use App\Services\PlacementScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlacementCourseAccessTest extends TestCase
{
    use RefreshDatabase;

    // ─── Factories ────────────────────────────────────────────────────────────

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function placementCourse(array $overrides = []): Course
    {
        return Course::factory()->create(array_merge([
            'title'                  => 'English Course',
            'requires_placement_test'=> true,
            'placement_test_type'    => 'english_cefr',
            'status'                 => 'published',
        ], $overrides));
    }

    private function regularCourse(): Course
    {
        return Course::factory()->create([
            'title'                  => 'Regular Course',
            'requires_placement_test'=> false,
            'status'                 => 'published',
        ]);
    }

    private function enroll(User $student, Course $course): Registration
    {
        return Registration::create([
            'user_id'   => $student->id,
            'course_id' => $course->id,
            'full_name' => $student->name,
            'email'     => $student->email,
            'phone'     => '0500000000',
            'status'    => 'Registered',
        ]);
    }

    private function instructorProfile(User $user): Instructor
    {
        return Instructor::create([
            'user_id' => $user->id,
            'name'    => $user->name,
            'title'   => 'Instructor',
        ]);
    }

    private function placementTest(int $count = 5): PlacementTest
    {
        $test = PlacementTest::create([
            'title'            => 'English Placement',
            'language'         => 'en',
            'duration_minutes' => 30,
            'total_questions'  => $count,
            'is_active'        => true,
        ]);
        for ($i = 1; $i <= $count; $i++) {
            PlacementTestQuestion::create([
                'placement_test_id' => $test->id,
                'question_text'     => "Q{$i}",
                'option_a'          => 'A', 'option_b' => 'B',
                'option_c'          => 'C', 'option_d' => 'D',
                'correct_option'    => 'a',
                'order'             => $i,
            ]);
        }
        return $test;
    }

    private function completedAttempt(User $student, Course $course, PlacementTest $test, int $score = 35): PlacementTestAttempt
    {
        return PlacementTestAttempt::create([
            'user_id'           => $student->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'score'             => $score,
            'total_questions'   => $test->total_questions,
            'percentage'        => round(($score / max($test->total_questions, 1)) * 100, 2),
            'estimated_level'   => (new PlacementScoringService())->mapLevel($score),
            'started_at'        => now()->subHour(),
            'submitted_at'      => now(),
            'answers'           => [],
            'status'            => 'completed',
        ]);
    }

    private function slot(Course $course, Instructor $instructor): OralAssessmentSlot
    {
        return OralAssessmentSlot::create([
            'course_id'      => $course->id,
            'instructor_id'  => $instructor->id,
            'starts_at'      => now()->addDays(2),
            'ends_at'        => now()->addDays(2)->addMinutes(30),
            'booking_status' => 'available',
            'is_booked'      => false,
        ]);
    }

    // ─── GET /api/student/courses — placement status fields ───────────────────

    public function test_student_courses_returns_placement_status_not_started(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/courses')
            ->assertOk()
            ->assertJsonPath('data.0.requires_placement_test', true)
            ->assertJsonPath('data.0.placement_status', 'not_started')
            ->assertJsonPath('data.0.can_start_learning', false)
            ->assertJsonPath('data.0.placement_attempt', null);
    }

    public function test_student_courses_returns_placement_status_in_progress(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);

        PlacementTestAttempt::create([
            'user_id'           => $student->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'score'             => 0,
            'total_questions'   => $test->total_questions,
            'percentage'        => 0,
            'status'            => 'in_progress',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/courses')
            ->assertOk()
            ->assertJsonPath('data.0.placement_status', 'in_progress')
            ->assertJsonPath('data.0.can_start_learning', false);
    }

    public function test_student_courses_returns_placement_status_written_completed(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/courses')
            ->assertOk()
            ->assertJsonPath('data.0.placement_status', 'written_completed')
            ->assertJsonPath('data.0.can_start_learning', false)
            ->assertJsonPath('data.0.estimated_level', 'B1');
    }

    public function test_student_courses_returns_placement_status_oral_booked(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $test        = $this->placementTest();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test);
        $oralSlot = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                   => $student->id,
            'course_id'                 => $course->id,
            'instructor_id'             => $instructorP->id,
            'placement_test_attempt_id' => $attempt->id,
            'slot_id'                   => $oralSlot->id,
            'status'                    => 'booked',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/courses')
            ->assertOk()
            ->assertJsonPath('data.0.placement_status', 'oral_booked')
            ->assertJsonPath('data.0.can_start_learning', false)
            ->assertJsonPath('data.0.oral_booking_status', 'booked');
    }

    public function test_student_courses_returns_placement_status_completed(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $test        = $this->placementTest();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $attempt  = $this->completedAttempt($student, $course, $test);
        $oralSlot = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                   => $student->id,
            'course_id'                 => $course->id,
            'instructor_id'             => $instructorP->id,
            'placement_test_attempt_id' => $attempt->id,
            'slot_id'                   => $oralSlot->id,
            'status'                    => 'completed',
            'final_level'               => 'B1',
            'oral_score'                => 70,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/courses')
            ->assertOk()
            ->assertJsonPath('data.0.placement_status', 'completed')
            ->assertJsonPath('data.0.can_start_learning', true)
            ->assertJsonPath('data.0.oral_booking_status', 'completed');
    }

    public function test_student_courses_no_placement_fields_for_regular_course(): void
    {
        $student = $this->student();
        $course  = $this->regularCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/courses')
            ->assertOk()
            ->assertJsonPath('data.0.requires_placement_test', false)
            ->assertJsonPath('data.0.placement_status', null)
            ->assertJsonPath('data.0.can_start_learning', true);
    }

    // ─── GET /api/student/exams ───────────────────────────────────────────────

    public function test_student_exams_returns_only_completed_attempts(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test, score: 45);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/exams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $attempt->id)
            ->assertJsonPath('data.0.score', 45)
            ->assertJsonPath('data.0.course.id', $course->id);
    }

    public function test_student_exams_excludes_in_progress_attempts(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);

        PlacementTestAttempt::create([
            'user_id'           => $student->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'score'             => 0,
            'total_questions'   => $test->total_questions,
            'percentage'        => 0,
            'status'            => 'in_progress',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/exams')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_student_exams_returns_empty_for_student_with_no_attempts(): void
    {
        $student = $this->student();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/exams')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_student_exams_includes_oral_booking_status(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $test        = $this->placementTest();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $attempt  = $this->completedAttempt($student, $course, $test);
        $oralSlot = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                   => $student->id,
            'course_id'                 => $course->id,
            'instructor_id'             => $instructorP->id,
            'placement_test_attempt_id' => $attempt->id,
            'slot_id'                   => $oralSlot->id,
            'status'                    => 'completed',
            'final_level'               => 'B2',
            'oral_score'                => 80,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/exams')
            ->assertOk()
            ->assertJsonPath('data.0.oral_booking_status', 'completed')
            ->assertJsonPath('data.0.final_level', 'B2');
    }

    // ─── Placement middleware — learn/modules routes ──────────────────────────

    public function test_learn_route_blocked_when_placement_required_and_not_started(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/learn")
            ->assertStatus(403)
            ->assertJsonPath('placement_status', 'placement_required');
    }

    public function test_modules_route_blocked_when_placement_required_and_not_started(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/modules")
            ->assertStatus(403)
            ->assertJsonPath('placement_status', 'placement_required');
    }

    public function test_learn_route_blocked_at_written_completed_before_oral_booked(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/learn")
            ->assertStatus(403)
            ->assertJsonPath('placement_status', 'written_completed');
    }

    public function test_learn_route_not_blocked_by_middleware_for_regular_course(): void
    {
        $student = $this->student();
        $course  = $this->regularCourse();
        $this->enroll($student, $course);

        // Middleware should pass through; controller may return other errors (e.g. 404/422)
        // but NOT a placement_status 403
        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/learn");

        $this->assertNotEquals(403, $response->status());
        $response->assertJsonMissing(['placement_status']);
    }

    // ─── Notifications — instructor assigned ─────────────────────────────────

    public function test_assigning_instructor_notifies_enrolled_students(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);

        event(new InstructorAssignedEvent($course, $instructorP, $this->admin()->id));

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $student->id,
            'type'    => 'course_schedule_updated',
        ]);
    }

    public function test_assigning_instructor_does_not_notify_students_not_enrolled(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);

        // student NOT enrolled in $course
        event(new InstructorAssignedEvent($course, $instructorP, $this->admin()->id));

        $this->assertDatabaseMissing('platform_notifications', [
            'user_id' => $student->id,
            'type'    => 'course_schedule_updated',
        ]);
    }

    public function test_assigning_instructor_notifies_instructor_themselves(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse();

        event(new InstructorAssignedEvent($course, $instructorP, $this->admin()->id));

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $instructorU->id,
            'type'    => 'instructor_assigned',
        ]);
    }

    // ─── Notifications — oral assessment booked ───────────────────────────────

    public function test_oral_booking_sends_notification_to_student(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $test        = $this->placementTest();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);
        $oralSlot = $this->slot($course, $instructorP);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/oral-assessment/book", [
                'slot_id' => $oralSlot->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $student->id,
            'type'    => 'oral_assessment_booked',
        ]);
    }

    public function test_oral_booking_does_not_send_duplicate_notification(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $test        = $this->placementTest();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);
        $oralSlot = $this->slot($course, $instructorP);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/oral-assessment/book", [
                'slot_id' => $oralSlot->id,
            ])
            ->assertStatus(201);

        $notificationCount = PlatformNotification::where('user_id', $student->id)
            ->where('type', 'oral_assessment_booked')
            ->count();

        $this->assertEquals(1, $notificationCount);
    }

    // ─── Status endpoint — new response shape ─────────────────────────────────

    public function test_status_returns_not_started_with_correct_flags(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertOk()
            ->assertJsonPath('data.placement_status', 'not_started')
            ->assertJsonPath('data.can_take_written_test', true)
            ->assertJsonPath('data.can_book_oral', false)
            ->assertJsonPath('data.can_start_learning', false)
            ->assertJsonPath('data.attempt', null)
            ->assertJsonPath('data.oral_booking', null);
    }

    public function test_status_returns_written_completed_with_attempt_data(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test, score: 45);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertOk()
            ->assertJsonPath('data.placement_status', 'written_completed')
            ->assertJsonPath('data.can_take_written_test', false)
            ->assertJsonPath('data.can_book_oral', true)
            ->assertJsonPath('data.can_start_learning', false)
            ->assertJsonPath('data.attempt.id', $attempt->id)
            ->assertJsonPath('data.attempt.score', 45)
            ->assertJsonPath('data.attempt.estimated_level', 'B1')
            ->assertJsonPath('data.oral_booking', null);
    }

    public function test_status_returns_oral_booked_with_booking_data(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $test        = $this->placementTest();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $attempt  = $this->completedAttempt($student, $course, $test);
        $oralSlot = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                   => $student->id,
            'course_id'                 => $course->id,
            'instructor_id'             => $instructorP->id,
            'placement_test_attempt_id' => $attempt->id,
            'slot_id'                   => $oralSlot->id,
            'status'                    => 'booked',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertOk()
            ->assertJsonPath('data.placement_status', 'oral_booked')
            ->assertJsonPath('data.can_take_written_test', false)
            ->assertJsonPath('data.can_book_oral', false)
            ->assertJsonPath('data.can_start_learning', false)
            ->assertJsonPath('data.oral_booking.status', 'booked');
    }

    public function test_status_returns_in_progress_for_started_but_not_submitted(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);

        PlacementTestAttempt::create([
            'user_id'           => $student->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'score'             => 0,
            'total_questions'   => $test->total_questions,
            'percentage'        => 0,
            'status'            => 'in_progress',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertOk()
            ->assertJsonPath('data.placement_status', 'in_progress')
            ->assertJsonPath('data.can_take_written_test', true)
            ->assertJsonPath('data.can_book_oral', false);
    }

    // ─── Start endpoint — 409 when already completed ──────────────────────────

    public function test_start_returns_409_with_attempt_when_already_completed(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test, score: 42);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.placement_status', 'written_completed')
            ->assertJsonPath('data.can_take_written_test', false)
            ->assertJsonPath('data.can_book_oral', true)
            ->assertJsonPath('data.can_start_learning', false)
            ->assertJsonPath('data.attempt.id', $attempt->id)
            ->assertJsonPath('data.attempt.score', 42)
            ->assertJsonPath('data.oral_booking', null);
    }

    // ─── Result endpoint ──────────────────────────────────────────────────────

    public function test_result_returns_saved_score_and_level(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $test    = $this->placementTest();   // 5 questions → valid scores 0-5
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test, score: 4);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/result")
            ->assertOk()
            ->assertJsonPath('data.attempt.id', $attempt->id)
            ->assertJsonPath('data.attempt.score', 4)
            ->assertJsonPath('data.attempt.estimated_level', 'Starter')
            ->assertJsonPath('data.can_book_oral', true)
            ->assertJsonPath('data.oral_booking', null);
    }

    public function test_result_returns_404_when_not_yet_completed(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/result")
            ->assertStatus(404);
    }

    public function test_result_includes_oral_booking_when_booked(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $test        = $this->placementTest();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $attempt  = $this->completedAttempt($student, $course, $test);
        $oralSlot = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                   => $student->id,
            'course_id'                 => $course->id,
            'instructor_id'             => $instructorP->id,
            'placement_test_attempt_id' => $attempt->id,
            'slot_id'                   => $oralSlot->id,
            'status'                    => 'booked',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/result")
            ->assertOk()
            ->assertJsonPath('data.oral_booking.status', 'booked')
            ->assertJsonPath('data.can_book_oral', false);
    }

    // ─── Oral slots endpoint — new response shape ─────────────────────────────

    public function test_slots_returns_wrapped_slots_array(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $test        = $this->placementTest();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);
        $this->slot($course, $instructorP);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/oral-assessment/slots")
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['slots']])
            ->assertJsonCount(1, 'data.slots');
    }

    public function test_slots_returns_flat_instructor_fields(): void
    {
        $student     = $this->student();
        $course      = $this->placementCourse();
        $test        = $this->placementTest();
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);
        $this->slot($course, $instructorP);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/oral-assessment/slots")
            ->assertOk()
            ->assertJsonPath('data.slots.0.instructor_id', $instructorP->id)
            ->assertJsonPath('data.slots.0.instructor_name', $instructorP->name)
            ->assertJsonPath('data.slots.0.is_booked', false);
    }

    public function test_slots_blocked_before_written_test_completed(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/oral-assessment/slots")
            ->assertStatus(422);
    }

    public function test_slots_returns_empty_when_none_exist(): void
    {
        $student = $this->student();
        $course  = $this->placementCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/oral-assessment/slots")
            ->assertOk()
            ->assertJsonCount(0, 'data.slots');
    }

    // ─── Instructor placement students — field names ──────────────────────────

    public function test_instructor_sees_placement_students_with_correct_field_names(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);
        $student     = $this->student();
        $test        = $this->placementTest();
        $this->enroll($student, $course);
        $attempt     = $this->completedAttempt($student, $course, $test, score: 3);

        $this->actingAs($instructorU, 'sanctum')
            ->getJson("/api/instructor/courses/{$course->id}/placement-students")
            ->assertOk()
            ->assertJsonPath('data.0.student_name', $student->name)
            ->assertJsonPath('data.0.student_email', $student->email)
            ->assertJsonPath('data.0.enrollment_status', 'registered')
            ->assertJsonPath('data.0.status', 'waiting_oral')
            ->assertJsonPath('data.0.placement_attempt.id', $attempt->id)
            ->assertJsonPath('data.0.placement_attempt.score', 3)
            ->assertJsonPath('data.0.placement_attempt.total_questions', $test->total_questions)
            ->assertJsonPath('data.0.oral_booking', null);
    }

    public function test_instructor_placement_students_meta_counts_are_correct(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);
        $test        = $this->placementTest();

        $s1 = $this->student(); $this->enroll($s1, $course);
        $s2 = $this->student(); $this->enroll($s2, $course);
        $this->completedAttempt($s2, $course, $test, score: 3);

        $this->actingAs($instructorU, 'sanctum')
            ->getJson("/api/instructor/courses/{$course->id}/placement-students")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.not_started', 1)
            ->assertJsonPath('meta.waiting_oral', 1);
    }

    public function test_instructor_cannot_see_another_instructors_placement_students(): void
    {
        $instructorU1 = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP1 = $this->instructorProfile($instructorU1);
        $course       = $this->placementCourse(['instructor_id' => $instructorP1->id]);

        $instructorU2 = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $this->instructorProfile($instructorU2);

        $this->actingAs($instructorU2, 'sanctum')
            ->getJson("/api/instructor/courses/{$course->id}/placement-students")
            ->assertStatus(403);
    }

    public function test_instructor_placement_students_shows_oral_booking_flat_fields(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);
        $student     = $this->student();
        $test        = $this->placementTest();
        $this->enroll($student, $course);
        $attempt  = $this->completedAttempt($student, $course, $test, score: 3);
        $oralSlot = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                   => $student->id,
            'course_id'                 => $course->id,
            'instructor_id'             => $instructorP->id,
            'placement_test_attempt_id' => $attempt->id,
            'slot_id'                   => $oralSlot->id,
            'status'                    => 'booked',
        ]);

        $this->actingAs($instructorU, 'sanctum')
            ->getJson("/api/instructor/courses/{$course->id}/placement-students")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'oral_scheduled')
            ->assertJsonPath('data.0.oral_booking.status', 'booked')
            ->assertJsonPath('data.0.oral_booking.starts_at', $oralSlot->starts_at->toIso8601String())
            ->assertJsonPath('data.0.oral_booking.ends_at', $oralSlot->ends_at->toIso8601String());
    }

    // ─── Instructor courses — placement stats ─────────────────────────────────

    public function test_instructor_courses_index_returns_placement_stats(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);
        $test        = $this->placementTest();

        $s1 = $this->student(); $this->enroll($s1, $course);
        $s2 = $this->student(); $this->enroll($s2, $course);
        $s3 = $this->student(); $this->enroll($s3, $course);

        // s1 = not started; s2 = written_completed (oral_pending); s3 = oral_booked
        $attempt2 = $this->completedAttempt($s2, $course, $test, score: 3);
        $attempt3 = $this->completedAttempt($s3, $course, $test, score: 3);
        $oralSlot = $this->slot($course, $instructorP);
        OralAssessmentBooking::create([
            'user_id'                   => $s3->id,
            'course_id'                 => $course->id,
            'instructor_id'             => $instructorP->id,
            'placement_test_attempt_id' => $attempt3->id,
            'slot_id'                   => $oralSlot->id,
            'status'                    => 'booked',
        ]);

        $this->actingAs($instructorU, 'sanctum')
            ->getJson('/api/instructor/courses')
            ->assertOk()
            ->assertJsonPath('data.0.enrolled_students_count', 3)
            ->assertJsonPath('data.0.written_completed_count', 2)
            ->assertJsonPath('data.0.oral_pending_count', 1)
            ->assertJsonPath('data.0.oral_booked_count', 1);
    }

    // ─── Notification — placement test submitted ──────────────────────────────

    public function test_submitting_placement_test_notifies_course_instructor(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);
        $student     = $this->student();
        $test        = $this->placementTest(5);
        $this->enroll($student, $course);

        PlacementTestAttempt::create([
            'user_id'           => $student->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'total_questions'   => $test->total_questions,
            'score'             => 0,
            'percentage'        => 0,
            'started_at'        => now()->subMinutes(5),
            'status'            => 'in_progress',
        ]);

        $answers = $test->questions->pluck('id')->mapWithKeys(fn ($id) => [$id => 'a'])->all();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/submit", [
                'answers' => $answers,
            ])
            ->assertOk();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $instructorU->id,
            'type'    => 'placement_test_completed',
        ]);
    }

    public function test_submitting_placement_test_no_crash_when_course_has_no_instructor(): void
    {
        $course  = $this->placementCourse(['instructor_id' => null]);
        $student = $this->student();
        $test    = $this->placementTest(5);
        $this->enroll($student, $course);

        PlacementTestAttempt::create([
            'user_id'           => $student->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'total_questions'   => $test->total_questions,
            'score'             => 0,
            'percentage'        => 0,
            'started_at'        => now()->subMinutes(5),
            'status'            => 'in_progress',
        ]);

        $answers = $test->questions->pluck('id')->mapWithKeys(fn ($id) => [$id => 'a'])->all();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/submit", [
                'answers' => $answers,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('platform_notifications', [
            'type' => 'placement_test_completed',
        ]);
    }

    public function test_completing_oral_assessment_notifies_student(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $student     = $this->student();
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);
        $test        = $this->placementTest(5);
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test, score: 3);
        $slot    = $this->slot($course, $instructorP);

        $booking = OralAssessmentBooking::create([
            'user_id'                   => $student->id,
            'course_id'                 => $course->id,
            'instructor_id'             => $instructorP->id,
            'placement_test_attempt_id' => $attempt->id,
            'slot_id'                   => $slot->id,
            'status'                    => 'booked',
        ]);

        $this->actingAs($instructorU, 'sanctum')
            ->patchJson("/api/instructor/oral-assessments/{$booking->id}/complete", [
                'oral_score'  => 80,
                'final_level' => 'B1',
            ])
            ->assertOk();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $student->id,
            'type'    => 'oral_assessment_completed',
        ]);
    }

    // ─── Instructor availability — CRUD ───────────────────────────────────────

    public function test_instructor_can_list_their_availability_slots(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);
        $slot        = $this->slot($course, $instructorP);

        $this->actingAs($instructorU, 'sanctum')
            ->getJson('/api/instructor/availability')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $slot->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonPath('data.0.is_booked', false)
            ->assertJsonPath('data.0.booking_status', 'available');
    }

    public function test_instructor_can_create_availability_slot(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);

        $futureDate = now()->addDays(7)->format('Y-m-d');
        $weekday    = strtolower(now()->addDays(7)->format('l'));

        $this->actingAs($instructorU, 'sanctum')
            ->postJson('/api/instructor/availability', [
                'course_id'     => $course->id,
                'date_from'     => $futureDate,
                'date_to'       => $futureDate,
                'weekdays'      => [$weekday],
                'start_time'    => '09:00',
                'end_time'      => '10:00',
                'slot_duration' => 60,
                'meeting_link'  => 'https://zoom.us/j/test',
                'notes'         => 'Test note',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.slots.0.course_id', $course->id)
            ->assertJsonPath('data.slots.0.is_booked', false)
            ->assertJsonPath('data.slots.0.meeting_link', 'https://zoom.us/j/test')
            ->assertJsonPath('data.slots.0.notes', 'Test note');

        $this->assertDatabaseHas('oral_assessment_slots', [
            'instructor_id'  => $instructorP->id,
            'course_id'      => $course->id,
            'booking_status' => 'available',
        ]);
    }

    public function test_instructor_can_delete_unbooked_slot(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);
        $slot        = $this->slot($course, $instructorP);

        $this->actingAs($instructorU, 'sanctum')
            ->deleteJson("/api/instructor/availability/{$slot->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('oral_assessment_slots', ['id' => $slot->id]);
    }

    public function test_instructor_cannot_delete_booked_slot(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);
        $slot        = OralAssessmentSlot::create([
            'course_id'      => $course->id,
            'instructor_id'  => $instructorP->id,
            'starts_at'      => now()->addDays(2),
            'ends_at'        => now()->addDays(2)->addMinutes(30),
            'booking_status' => 'booked',
            'is_booked'      => true,
        ]);

        $this->actingAs($instructorU, 'sanctum')
            ->deleteJson("/api/instructor/availability/{$slot->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('oral_assessment_slots', ['id' => $slot->id]);
    }

    public function test_instructor_cannot_delete_another_instructors_slot(): void
    {
        $instructorU1 = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP1 = $this->instructorProfile($instructorU1);
        $instructorU2 = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP2 = $this->instructorProfile($instructorU2);
        $course       = $this->placementCourse(['instructor_id' => $instructorP1->id]);
        $slot         = $this->slot($course, $instructorP1);

        $this->actingAs($instructorU2, 'sanctum')
            ->deleteJson("/api/instructor/availability/{$slot->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('oral_assessment_slots', ['id' => $slot->id]);
    }

    // ─── Notification — oral assessment booked ────────────────────────────────

    public function test_booking_oral_slot_notifies_instructor(): void
    {
        $instructorU = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP = $this->instructorProfile($instructorU);
        $course      = $this->placementCourse(['instructor_id' => $instructorP->id]);
        $student     = $this->student();
        $test        = $this->placementTest(5);
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test, score: 3);
        $slot = $this->slot($course, $instructorP);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/oral-assessment/book", [
                'slot_id' => $slot->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $instructorU->id,
            'type'    => 'oral_assessment_booked',
        ]);
    }

    // ─── Student oral slots — filtered by course instructor ───────────────────

    public function test_student_only_sees_slots_from_assigned_instructor(): void
    {
        $instructorU1 = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP1 = $this->instructorProfile($instructorU1);
        $instructorU2 = User::factory()->create(['role' => 'teacher', 'is_active' => true]);
        $instructorP2 = $this->instructorProfile($instructorU2);

        // Course assigned to instructor 1
        $course  = $this->placementCourse(['instructor_id' => $instructorP1->id]);
        $student = $this->student();
        $test    = $this->placementTest(5);
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test, score: 3);

        // Slot from assigned instructor 1
        $slotAssigned = $this->slot($course, $instructorP1);

        // Slot from a different instructor 2 (same course but different instructor)
        $slotOther = OralAssessmentSlot::create([
            'course_id'      => $course->id,
            'instructor_id'  => $instructorP2->id,
            'starts_at'      => now()->addDays(3),
            'ends_at'        => now()->addDays(3)->addMinutes(30),
            'booking_status' => 'available',
            'is_booked'      => false,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/oral-assessment/slots")
            ->assertOk()
            ->assertJsonCount(1, 'data.slots')
            ->assertJsonPath('data.slots.0.id', $slotAssigned->id);
    }
}
