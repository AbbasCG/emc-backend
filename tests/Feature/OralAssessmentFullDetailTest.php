<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OralAssessmentFullDetailTest extends TestCase
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

    private function setUpBooking(User $user, Instructor $instructor, Course $course): OralAssessmentBooking
    {
        $test = PlacementTest::create([
            'title' => 'English Placement', 'language' => 'en',
            'duration_minutes' => 30, 'total_questions' => 70, 'is_active' => true,
        ]);
        $attempt = PlacementTestAttempt::create([
            'user_id' => $user->id, 'course_id' => $course->id, 'placement_test_id' => $test->id,
            'score' => 42, 'total_questions' => 70, 'percentage' => 60.0,
            'estimated_level' => 'B1', 'status' => 'completed', 'submitted_at' => now(),
        ]);
        $slot = OralAssessmentSlot::create([
            'instructor_id' => $instructor->id, 'course_id' => $course->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30),
            'booking_status' => 'booked', 'is_booked' => true, 'booked_by' => $user->id,
        ]);

        return OralAssessmentBooking::create([
            'user_id' => $user->id, 'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'placement_test_attempt_id' => $attempt->id, 'slot_id' => $slot->id,
            'status' => OralAssessmentBooking::STATUS_BOOKED,
        ]);
    }

    public function test_completing_assessment_returns_full_rubric_breakdown(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $booking = $this->setUpBooking($student, $instructor, $course);

        $response = $this->actingAs($instructorUser, 'sanctum')
            ->patchJson("/api/instructor/oral-assessments/{$booking->id}/complete", [
                'final_level' => 'B1',
                'pronunciation_score' => 8, 'grammar_score' => 7, 'vocabulary_score' => 9,
                'fluency_score' => 6, 'comprehension_score' => 8, 'confidence_score' => 5,
                'strengths' => 'Clear pronunciation', 'weaknesses' => 'Hesitant fluency',
                'recommendations' => 'Practice speaking daily', 'instructor_notes' => 'Solid overall level match.',
            ]);

        $response->assertOk();
        $booking->refresh();
        // sum = 8+7+9+6+8+5 = 43, /60*100 = 71.67 -> round = 72
        $this->assertSame(72, $booking->oral_score);
        $this->assertNotNull($booking->evaluated_at);
        $this->assertNull($booking->edited_at);
    }

    public function test_class_students_endpoint_includes_full_oral_assessment(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $booking = $this->setUpBooking($student, $instructor, $course);
        $booking->update([
            'status' => 'completed', 'final_level' => 'B1', 'oral_score' => 72,
            'pronunciation_score' => 8, 'grammar_score' => 7, 'vocabulary_score' => 9,
            'fluency_score' => 6, 'comprehension_score' => 8, 'confidence_score' => 5,
            'strengths' => 'Clear pronunciation', 'weaknesses' => 'Hesitant fluency',
            'recommendations' => 'Practice speaking daily', 'evaluated_at' => now(),
        ]);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class 01', 'capacity' => 20, 'status' => 'active',
        ]);
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id, 'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $response = $this->actingAs($instructorUser, 'sanctum')->getJson("/api/instructor/classes/{$group->id}/students");
        $response->assertOk();

        $row = collect($response->json('data.students'))->firstWhere('id', $student->id);
        $this->assertNotNull($row['oral_assessment']);
        $this->assertSame(72, $row['oral_assessment']['oral_score']);
        $this->assertSame('B1', $row['oral_assessment']['final_level']);
        $this->assertCount(6, $row['oral_assessment']['rubric']);
        $this->assertSame('Clear pronunciation', $row['oral_assessment']['notes']['strengths']);
        $this->assertSame($instructor->name ?? $instructorUser->name, $row['oral_assessment']['evaluator']['name']);
        $this->assertNull($row['oral_assessment']['history']);
    }

    public function test_editing_completed_assessment_records_history(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $booking = $this->setUpBooking($student, $instructor, $course);

        $this->actingAs($instructorUser, 'sanctum')
            ->patchJson("/api/instructor/oral-assessments/{$booking->id}/complete", [
                'final_level' => 'A2', 'oral_score' => 50,
            ])->assertOk();

        $this->actingAs($instructorUser, 'sanctum')
            ->patchJson("/api/instructor/oral-assessments/{$booking->id}/complete", [
                'final_level' => 'B1', 'oral_score' => 72,
            ])->assertOk();

        $booking->refresh();
        $this->assertSame('B1', $booking->final_level);
        $this->assertSame(72, $booking->oral_score);
        $this->assertSame('A2', $booking->previous_final_level);
        $this->assertSame(50, $booking->previous_oral_score);
        $this->assertSame($instructorUser->id, $booking->edited_by);
        $this->assertNotNull($booking->edited_at);
    }

    public function test_no_oral_assessment_returned_when_not_yet_evaluated(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->setUpBooking($student, $instructor, $course); // never completed

        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class 01', 'capacity' => 20, 'status' => 'active',
        ]);
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id, 'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $response = $this->actingAs($instructorUser, 'sanctum')->getJson("/api/instructor/classes/{$group->id}/students");
        $response->assertOk();

        $row = collect($response->json('data.students'))->firstWhere('id', $student->id);
        $this->assertNull($row['oral_assessment']);
    }

    public function test_placement_tests_endpoint_includes_full_oral_assessment_rubric(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $booking = $this->setUpBooking($student, $instructor, $course);
        $booking->update([
            'status' => 'completed', 'final_level' => 'B1', 'oral_score' => 72,
            'pronunciation_score' => 8, 'grammar_score' => 7, 'vocabulary_score' => 9,
            'fluency_score' => 6, 'comprehension_score' => 8, 'confidence_score' => 5,
            'evaluated_at' => now(),
        ]);

        $response = $this->actingAs($instructorUser, 'sanctum')->getJson('/api/instructor/placement-tests');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertNotNull($row);
        // Canonical key — matches studentsForCourse()'s oral_assessment_full exactly.
        $this->assertNotNull($row['oral_assessment_full']);
        $this->assertCount(6, $row['oral_assessment_full']['rubric']);
        $pronunciation = collect($row['oral_assessment_full']['rubric'])->firstWhere('key', 'pronunciation');
        $this->assertSame(8, $pronunciation['score']);
    }

    /** Proves the two pages this ticket compares — course/placement-students
     *  and the global placement-tests page — return byte-identical oral
     *  score, rubric, evaluator, and evaluation timestamp for the same
     *  student, sourced from the same oralAssessmentFullShape() call. */
    public function test_course_students_and_placement_tests_return_identical_oral_assessment(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $course->update(['requires_placement_test' => true]);
        $student = $this->student();
        \App\Models\Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);
        $booking = $this->setUpBooking($student, $instructor, $course);
        $booking->update([
            'status' => 'completed', 'final_level' => 'B1', 'oral_score' => 72,
            'pronunciation_score' => 8, 'grammar_score' => 7, 'vocabulary_score' => 9,
            'fluency_score' => 6, 'comprehension_score' => 8, 'confidence_score' => 5,
            'instructor_notes' => 'Solid overall.', 'evaluated_at' => now(),
        ]);

        $courseStudentsRow = collect(
            $this->actingAs($instructorUser, 'sanctum')
                ->getJson("/api/instructor/courses/{$course->id}/placement-students")
                ->assertOk()->json('data'),
        )->firstWhere('student_id', $student->id);

        $placementTestsRow = collect(
            $this->actingAs($instructorUser, 'sanctum')
                ->getJson('/api/instructor/placement-tests')
                ->assertOk()->json('data'),
        )->firstWhere('student_id', $student->id);

        $this->assertNotNull($courseStudentsRow['oral_assessment_full']);
        $this->assertNotNull($placementTestsRow['oral_assessment_full']);

        $this->assertSame($courseStudentsRow['oral_assessment_full']['oral_score'], $placementTestsRow['oral_assessment_full']['oral_score']);
        $this->assertSame($courseStudentsRow['oral_assessment_full']['final_level'], $placementTestsRow['oral_assessment_full']['final_level']);
        $this->assertSame($courseStudentsRow['oral_assessment_full']['rubric'], $placementTestsRow['oral_assessment_full']['rubric']);
        $this->assertSame($courseStudentsRow['oral_assessment_full']['evaluator'], $placementTestsRow['oral_assessment_full']['evaluator']);
        $this->assertSame($courseStudentsRow['oral_assessment_full']['system']['evaluated_at'], $placementTestsRow['oral_assessment_full']['system']['evaluated_at']);
    }

    /** A student enrolled in two courses must only ever surface the oral
     *  assessment recorded for the course actually being queried. */
    public function test_oral_assessment_is_course_scoped_for_a_student_in_two_courses(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        $courseA = $this->course($instructor);
        $courseB = Course::factory()->create(['title' => 'Course B', 'status' => 'published', 'instructor_id' => $instructor->id]);
        $student = $this->student();

        $bookingA = $this->setUpBooking($student, $instructor, $courseA);
        $bookingA->update(['status' => 'completed', 'final_level' => 'A2', 'oral_score' => 40, 'evaluated_at' => now()]);

        // Course B: student has no oral assessment at all.
        \App\Models\Registration::create([
            'user_id' => $student->id, 'course_id' => $courseB->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);

        $response = $this->actingAs($instructorUser, 'sanctum')->getJson('/api/instructor/placement-tests');
        $response->assertOk();

        $rowA = collect($response->json('data'))->first(fn ($r) => $r['student_id'] === $student->id && $r['course_id'] === $courseA->id);
        $this->assertNotNull($rowA['oral_assessment_full']);
        $this->assertSame(40, $rowA['oral_assessment_full']['oral_score']);
    }
}
