<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\PlacementTestQuestion;
use App\Models\Registration;
use App\Models\User;
use App\Services\PlacementScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlacementTestTest extends TestCase
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

    private function instructor(): User
    {
        return User::factory()->create(['role' => 'teacher', 'is_active' => true]);
    }

    private function englishCourse(array $overrides = []): Course
    {
        return Course::factory()->create(array_merge([
            'title'                  => 'General English',
            'requires_placement_test'=> true,
            'placement_test_type'    => 'english',
            'status'                 => 'published',
        ], $overrides));
    }

    private function placementTest(int $questionCount = 70): PlacementTest
    {
        $test = PlacementTest::create([
            'title'            => 'EMC English Placement Test',
            'language'         => 'en',
            'duration_minutes' => 30,
            'total_questions'  => $questionCount,
            'is_active'        => true,
        ]);

        for ($i = 1; $i <= $questionCount; $i++) {
            PlacementTestQuestion::create([
                'placement_test_id' => $test->id,
                'question_text'     => "Question {$i}",
                'option_a'          => 'Option A',
                'option_b'          => 'Option B',
                'option_c'          => 'Option C',
                'option_d'          => 'Option D',
                'correct_option'    => 'a',
                'order'             => $i,
                'level_band'        => 'beginner',
            ]);
        }

        return $test;
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

    private function completedAttempt(User $student, Course $course, PlacementTest $test, int $score = 35): PlacementTestAttempt
    {
        return PlacementTestAttempt::create([
            'user_id'           => $student->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'score'             => $score,
            'total_questions'   => $test->total_questions,
            'percentage'        => round(($score / $test->total_questions) * 100, 2),
            'estimated_level'   => (new PlacementScoringService())->mapLevel($score),
            'started_at'        => now()->subHour(),
            'submitted_at'      => now(),
            'answers'           => [],
            'status'            => 'completed',
        ]);
    }

    // ─── Scoring service unit tests ───────────────────────────────────────────

    #[DataProvider('levelMappingProvider')]
    public function test_scoring_service_maps_levels_correctly(int $score, string $expected): void
    {
        $service = new PlacementScoringService();
        $this->assertSame($expected, $service->mapLevel($score));
    }

    public static function levelMappingProvider(): array
    {
        return [
            'score 0'  => [0,  'Starter'],
            'score 1'  => [1,  'Starter'],
            'score 6'  => [6,  'Starter'],
            'score 7'  => [7,  'A1'],
            'score 20' => [20, 'A1'],
            'score 21' => [21, 'A2'],
            'score 34' => [34, 'A2'],
            'score 35' => [35, 'B1'],
            'score 48' => [48, 'B1'],
            'score 49' => [49, 'B2'],
            'score 62' => [62, 'B2'],
            'score 63' => [63, 'C1'],
            'score 70' => [70, 'C1'],
            'score 99' => [99, 'C1'],
        ];
    }

    public function test_scoring_service_calculates_correct_score(): void
    {
        $test = $this->placementTest(4);
        // Override questions: Q1=a, Q2=b, Q3=c, Q4=d
        $questions = $test->questions()->orderBy('order')->get();
        $questions[0]->update(['correct_option' => 'a']);
        $questions[1]->update(['correct_option' => 'b']);
        $questions[2]->update(['correct_option' => 'c']);
        $questions[3]->update(['correct_option' => 'd']);

        $service = new PlacementScoringService();

        // 3 correct out of 4
        $answers = [
            $questions[0]->id => 'a', // correct
            $questions[1]->id => 'b', // correct
            $questions[2]->id => 'a', // wrong
            $questions[3]->id => 'd', // correct
        ];
        $result = $service->grade($test, $answers);

        $this->assertSame(3, $result['score']);
        $this->assertSame(4, $result['total_questions']);
        $this->assertSame(75.0, $result['percentage']);
    }

    // ─── Status endpoint ──────────────────────────────────────────────────────

    public function test_status_returns_placement_required_when_no_attempt(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertOk()
            ->assertJsonPath('data.placement_status', 'not_started')
            ->assertJsonPath('data.attempt', null);
    }

    public function test_status_returns_written_completed_after_test(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test, 40);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertOk()
            ->assertJsonPath('data.placement_status', 'written_completed')
            ->assertJsonPath('data.attempt.estimated_level', 'B1');
    }

    public function test_unenrolled_student_cannot_access_status(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertForbidden();
    }

    // ─── Start endpoint ───────────────────────────────────────────────────────

    public function test_student_can_start_placement_test(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $this->placementTest();
        $this->enroll($student, $course);

        $startResponse = $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertCreated()
            ->assertJsonPath('data.attempt.status', 'in_progress');

        $this->assertDatabaseHas('placement_test_attempts', [
            'user_id'   => $student->id,
            'course_id' => $course->id,
            'status'    => 'in_progress',
        ]);

        // Questions must not expose correct_option
        $questions = $startResponse->json('data.test.questions');
        $this->assertNotNull($questions);
        $this->assertArrayNotHasKey('correct_option', $questions[0]);
        $this->assertArrayHasKey('options', $questions[0]);
    }

    public function test_start_is_idempotent_for_in_progress_attempt(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);

        PlacementTestAttempt::create([
            'user_id'           => $student->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'total_questions'   => 70,
            'started_at'        => now()->subMinutes(5),
            'status'            => 'in_progress',
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertOk(); // 200 not 201 — returned existing attempt

        $this->assertSame(1, PlacementTestAttempt::where('user_id', $student->id)->count());
    }

    public function test_completed_test_cannot_be_restarted(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertStatus(409);
    }

    public function test_non_placement_course_returns_422_on_start(): void
    {
        $student = $this->student();
        $course  = Course::factory()->create(['requires_placement_test' => false]);
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/start")
            ->assertStatus(422);
    }

    // ─── Submit endpoint ──────────────────────────────────────────────────────

    public function test_student_can_submit_and_score_is_calculated(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest(10);
        $this->enroll($student, $course);

        // All questions have correct_option = 'a' from factory helper
        PlacementTestAttempt::create([
            'user_id'           => $student->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'total_questions'   => 10,
            'started_at'        => now()->subMinutes(5),
            'status'            => 'in_progress',
        ]);

        $questionIds = $test->questions()->pluck('id');
        // Answer 6 correctly (a), 4 wrong (b)
        $answers = [];
        foreach ($questionIds as $i => $id) {
            $answers[$id] = $i < 6 ? 'a' : 'b';
        }

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/submit", [
                'answers' => $answers,
            ])
            ->assertOk()
            ->assertJsonPath('data.attempt.status', 'completed')
            ->assertJsonPath('data.attempt.score', 6);

        $this->assertDatabaseHas('placement_test_attempts', [
            'user_id'   => $student->id,
            'course_id' => $course->id,
            'score'     => 6,
            'status'    => 'completed',
        ]);
    }

    public function test_level_beginner_assigned_for_low_score(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest(70);
        $this->enroll($student, $course);

        PlacementTestAttempt::create([
            'user_id'           => $student->id,
            'course_id'         => $course->id,
            'placement_test_id' => $test->id,
            'total_questions'   => 70,
            'started_at'        => now(),
            'status'            => 'in_progress',
        ]);

        $questionIds = $test->questions()->pluck('id');
        // Answer only 3 correctly → Beginner (1-6)
        $answers = [];
        foreach ($questionIds as $i => $id) {
            $answers[$id] = $i < 3 ? 'a' : 'b';
        }

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/submit", ['answers' => $answers])
            ->assertOk()
            ->assertJsonPath('data.attempt.estimated_level', 'Starter');
    }

    public function test_second_submit_is_blocked(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/submit", [
                'answers' => [$test->questions()->first()->id => 'a'],
            ])
            ->assertStatus(409);
    }

    public function test_answers_validation_rejects_invalid_option(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest(2);
        $this->enroll($student, $course);

        PlacementTestAttempt::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'placement_test_id' => $test->id, 'total_questions' => 2,
            'started_at' => now(), 'status' => 'in_progress',
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/placement-test/submit", [
                'answers' => [$test->questions()->first()->id => 'z'], // invalid
            ])
            ->assertStatus(422);
    }

    // ─── Oral assessment booking ──────────────────────────────────────────────

    public function test_student_can_list_available_slots(): void
    {
        $student      = $this->student();
        $course       = $this->englishCourse();
        $test         = $this->placementTest();
        $instructorU  = $this->instructor();
        $instructorP  = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);
        $this->slot($course, $instructorP);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/oral-assessment/slots")
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['slots' => [['id', 'starts_at', 'ends_at', 'is_booked']]]]);
    }

    public function test_student_cannot_list_slots_without_written_test(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/oral-assessment/slots")
            ->assertStatus(422);
    }

    public function test_oral_booking_works(): void
    {
        $student     = $this->student();
        $course      = $this->englishCourse();
        $test        = $this->placementTest();
        $instructorU = $this->instructor();
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);
        $slot = $this->slot($course, $instructorP);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/oral-assessment/book", [
                'slot_id' => $slot->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'booked');

        $this->assertDatabaseHas('oral_assessment_bookings', [
            'user_id'   => $student->id,
            'course_id' => $course->id,
            'slot_id'   => $slot->id,
            'status'    => 'booked',
        ]);

        // Slot must be marked as booked
        $this->assertDatabaseHas('oral_assessment_slots', [
            'id'             => $slot->id,
            'is_booked'      => 1,
            'booking_status' => 'booked',
        ]);
    }

    public function test_duplicate_oral_booking_is_blocked(): void
    {
        $student     = $this->student();
        $course      = $this->englishCourse();
        $test        = $this->placementTest();
        $instructorU = $this->instructor();
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test);
        $slot    = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                  => $student->id,
            'course_id'                => $course->id,
            'instructor_id'            => $instructorP->id,
            'placement_test_attempt_id'=> $attempt->id,
            'slot_id'                  => $slot->id,
            'status'                   => 'booked',
        ]);
        $slot->update(['is_booked' => true, 'booking_status' => 'booked']);

        $slot2 = $this->slot($course, $instructorP);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/oral-assessment/book", [
                'slot_id' => $slot2->id,
            ])
            ->assertStatus(422);
    }

    public function test_booking_already_booked_slot_fails(): void
    {
        $student     = $this->student();
        $student2    = $this->student();
        $course      = $this->englishCourse();
        $test        = $this->placementTest();
        $instructorU = $this->instructor();
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $this->enroll($student2, $course);
        $this->completedAttempt($student, $course, $test);
        $attempt2 = $this->completedAttempt($student2, $course, $test, 40);
        $slot     = $this->slot($course, $instructorP);

        // Student 2 books the slot first
        OralAssessmentBooking::create([
            'user_id'                  => $student2->id,
            'course_id'                => $course->id,
            'instructor_id'            => $instructorP->id,
            'placement_test_attempt_id'=> $attempt2->id,
            'slot_id'                  => $slot->id,
            'status'                   => 'booked',
        ]);
        $slot->update(['is_booked' => true, 'booking_status' => 'booked']);

        // Student 1 tries to book the same slot
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/courses/{$course->id}/oral-assessment/book", [
                'slot_id' => $slot->id,
            ])
            ->assertStatus(422);
    }

    // ─── Course access middleware ─────────────────────────────────────────────

    public function test_course_access_blocked_before_placement_test(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $this->enroll($student, $course);

        // Simulate content-access route that uses PlacementTestRequired middleware
        \Route::get('/_test/courses/{course}/content', function () {
            return response()->json(['success' => true]);
        })->middleware(['auth:sanctum', 'placement.required']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/_test/courses/{$course->id}/content")
            ->assertForbidden()
            ->assertJsonPath('placement_status', 'placement_required');
    }

    public function test_course_access_blocked_after_written_test_but_before_oral_booking(): void
    {
        $student = $this->student();
        $course  = $this->englishCourse();
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test);

        \Route::get('/_test2/courses/{course}/content', function () {
            return response()->json(['success' => true]);
        })->middleware(['auth:sanctum', 'placement.required']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/_test2/courses/{$course->id}/content")
            ->assertForbidden()
            ->assertJsonPath('placement_status', 'written_completed');
    }

    public function test_course_access_granted_after_oral_booking(): void
    {
        $student     = $this->student();
        $course      = $this->englishCourse();
        $test        = $this->placementTest();
        $instructorU = $this->instructor();
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test);
        $slot    = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                  => $student->id,
            'course_id'                => $course->id,
            'instructor_id'            => $instructorP->id,
            'placement_test_attempt_id'=> $attempt->id,
            'slot_id'                  => $slot->id,
            'status'                   => 'booked',
        ]);

        \Route::get('/_test3/courses/{course}/content', function () {
            return response()->json(['success' => true]);
        })->middleware(['auth:sanctum', 'placement.required']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/_test3/courses/{$course->id}/content")
            ->assertOk();
    }

    public function test_non_placement_course_allows_access_without_test(): void
    {
        $student = $this->student();
        $course  = Course::factory()->create(['requires_placement_test' => false]);

        \Route::get('/_test4/courses/{course}/content', function () {
            return response()->json(['success' => true]);
        })->middleware(['auth:sanctum', 'placement.required']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/_test4/courses/{$course->id}/content")
            ->assertOk();
    }

    // ─── Instructor view ──────────────────────────────────────────────────────

    public function test_instructor_sees_student_placement_status(): void
    {
        $instructorU = $this->instructor();
        $instructorP = $this->instructorProfile($instructorU);
        $student     = $this->student();
        $course      = Course::factory()->create([
            'requires_placement_test' => true,
            'instructor_id'           => $instructorP->id,
        ]);
        $test    = $this->placementTest();
        $this->enroll($student, $course);
        $this->completedAttempt($student, $course, $test, 48);

        $this->actingAs($instructorU, 'sanctum')
            ->getJson("/api/instructor/courses/{$course->id}/placement-students")
            ->assertOk()
            ->assertJsonStructure([
                'success', 'data' => [['student_id', 'student_name', 'status', 'placement_attempt']],
                'meta',
            ])
            ->assertJsonPath('data.0.status', 'waiting_oral')
            ->assertJsonPath('data.0.placement_attempt.estimated_level', 'B1');
    }

    public function test_instructor_can_complete_oral_assessment(): void
    {
        $instructorU = $this->instructor();
        $instructorP = $this->instructorProfile($instructorU);
        $student     = $this->student();
        $course      = $this->englishCourse();
        $test        = $this->placementTest();
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test, 40);
        $slot    = $this->slot($course, $instructorP);

        $booking = OralAssessmentBooking::create([
            'user_id'                  => $student->id,
            'course_id'                => $course->id,
            'instructor_id'            => $instructorP->id,
            'placement_test_attempt_id'=> $attempt->id,
            'slot_id'                  => $slot->id,
            'status'                   => 'booked',
        ]);

        $this->actingAs($instructorU, 'sanctum')
            ->patchJson("/api/instructor/oral-assessments/{$booking->id}/complete", [
                'oral_score'       => 78,
                'final_level'      => 'B2',
                'instructor_notes' => 'Strong spoken English, minor grammar gaps.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.final_level', 'B2')
            ->assertJsonPath('data.oral_score', 78);

        $this->assertDatabaseHas('oral_assessment_bookings', [
            'id'          => $booking->id,
            'status'      => 'completed',
            'final_level' => 'B2',
        ]);
    }

    public function test_instructor_can_correct_completed_booking_with_audit_snapshot(): void
    {
        $instructorU = $this->instructor();
        $instructorP = $this->instructorProfile($instructorU);
        $student     = $this->student();
        $course      = $this->englishCourse();
        $test        = $this->placementTest();
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test);
        $slot    = $this->slot($course, $instructorP);

        $booking = OralAssessmentBooking::create([
            'user_id'                  => $student->id,
            'course_id'                => $course->id,
            'instructor_id'            => $instructorP->id,
            'placement_test_attempt_id'=> $attempt->id,
            'slot_id'                  => $slot->id,
            'status'                   => 'completed',
            'oral_score'               => 80,
            'final_level'              => 'C1',
            'evaluated_at'             => now()->subMinute(),
        ]);

        $this->actingAs($instructorU, 'sanctum')
            ->patchJson("/api/instructor/oral-assessments/{$booking->id}/complete", [
                'oral_score'  => 50,
                'final_level' => 'Starter',
            ])
            ->assertOk();

        $this->assertDatabaseHas('oral_assessment_bookings', [
            'id'                   => $booking->id,
            'oral_score'           => 50,
            'final_level'          => 'Starter',
            'previous_oral_score'  => 80,
            'previous_final_level' => 'C1',
            'edited_by'            => $instructorU->id,
        ]);
    }

    public function test_status_oral_booked_after_booking(): void
    {
        $student     = $this->student();
        $course      = $this->englishCourse();
        $test        = $this->placementTest();
        $instructorU = $this->instructor();
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test);
        $slot    = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                  => $student->id,
            'course_id'                => $course->id,
            'instructor_id'            => $instructorP->id,
            'placement_test_attempt_id'=> $attempt->id,
            'slot_id'                  => $slot->id,
            'status'                   => 'booked',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertOk()
            ->assertJsonPath('data.placement_status', 'oral_booked')
            ->assertJsonPath('data.can_start_learning', false)
            ->assertJsonPath('data.final_level', null);
    }

    public function test_status_oral_completed_returns_correct_flags_and_final_level(): void
    {
        $student     = $this->student();
        $course      = $this->englishCourse();
        $test        = $this->placementTest();
        $instructorU = $this->instructor();
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test);
        $slot    = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                  => $student->id,
            'course_id'                => $course->id,
            'instructor_id'            => $instructorP->id,
            'placement_test_attempt_id'=> $attempt->id,
            'slot_id'                  => $slot->id,
            'status'                   => 'completed',
            'final_level'              => 'B1',
            'oral_score'               => 72,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertOk()
            ->assertJsonPath('data.placement_status', 'oral_completed')
            ->assertJsonPath('data.can_start_learning', true)
            ->assertJsonPath('data.can_book_oral', false)
            ->assertJsonPath('data.final_level', 'B1')
            ->assertJsonPath('data.oral_booking.status', 'completed')
            ->assertJsonPath('data.oral_booking.final_level', 'B1');
    }

    public function test_status_oral_booking_shape_is_flat(): void
    {
        $student     = $this->student();
        $course      = $this->englishCourse();
        $test        = $this->placementTest();
        $instructorU = $this->instructor();
        $instructorP = $this->instructorProfile($instructorU);
        $this->enroll($student, $course);
        $attempt = $this->completedAttempt($student, $course, $test);
        $slot    = $this->slot($course, $instructorP);

        OralAssessmentBooking::create([
            'user_id'                  => $student->id,
            'course_id'                => $course->id,
            'instructor_id'            => $instructorP->id,
            'placement_test_attempt_id'=> $attempt->id,
            'slot_id'                  => $slot->id,
            'status'                   => 'booked',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/courses/{$course->id}/placement-test/status")
            ->assertOk()
            ->assertJsonPath('data.oral_booking.starts_at', $slot->starts_at->toIso8601String())
            ->assertJsonPath('data.oral_booking.ends_at', $slot->ends_at->toIso8601String())
            ->assertJsonPath('data.oral_booking.instructor_name', $instructorP->name);
    }
}
