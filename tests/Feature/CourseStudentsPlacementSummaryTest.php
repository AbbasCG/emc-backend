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
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseStudentsPlacementSummaryTest extends TestCase
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

    private function course(Instructor $instructor, array $overrides = []): Course
    {
        return Course::factory()->create(array_merge([
            'title' => 'English Course', 'status' => 'published',
            'instructor_id' => $instructor->id, 'requires_placement_test' => true,
        ], $overrides));
    }

    private function enroll(User $student, Course $course): Registration
    {
        return Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    /** Creates a student with a fully completed written test, linked to the given course. */
    private function withCompletedWrittenTest(User $student, Course $course): PlacementTestAttempt
    {
        $test = PlacementTest::create([
            'title' => 'English Placement', 'language' => 'en',
            'duration_minutes' => 30, 'total_questions' => 70, 'is_active' => true,
        ]);

        return PlacementTestAttempt::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'placement_test_id' => $test->id,
            'score' => 42, 'total_questions' => 70, 'percentage' => 60.0,
            'estimated_level' => 'B1', 'status' => 'completed', 'submitted_at' => now(),
        ]);
    }

    public function test_completed_test_result_appears_on_course_students_endpoint(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->withCompletedWrittenTest($student, $course);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students");
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertNotNull($row);
        $this->assertSame(42, $row['written_score']);
        $this->assertSame(70, $row['total_questions']);
        $this->assertSame(60, (int) $row['percentage']);
        $this->assertSame('B1', $row['written_level']);
        $this->assertSame('written_completed', $row['placement_status']);
    }

    public function test_course_students_and_placement_tests_endpoints_return_the_same_score(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->withCompletedWrittenTest($student, $course);

        $studentsResponse   = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students");
        $placementResponse  = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/placement-students");

        $studentsRow  = collect($studentsResponse->json('data'))->firstWhere('student_id', $student->id);
        $placementRow = collect($placementResponse->json('data'))->firstWhere('student_id', $student->id);

        $this->assertSame($placementRow['written_score'], $studentsRow['written_score']);
        $this->assertSame($placementRow['written_level'], $studentsRow['written_level']);
        $this->assertSame($placementRow['placement_status'], $studentsRow['placement_status']);
    }

    public function test_final_approved_level_and_assigned_class_are_returned_and_consistent(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $attempt = $this->withCompletedWrittenTest($student, $course);

        $slot = OralAssessmentSlot::create([
            'instructor_id' => $instructor->id, 'course_id' => $course->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30),
            'booking_status' => 'completed', 'is_booked' => true, 'booked_by' => $student->id,
        ]);
        $booking = OralAssessmentBooking::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'placement_test_attempt_id' => $attempt->id, 'slot_id' => $slot->id,
            'status' => 'completed', 'final_level' => 'B1', 'oral_score' => 80,
        ]);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class 01', 'capacity' => 10, 'status' => 'ready',
        ]);
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'placement_attempt_id' => $attempt->id, 'oral_assessment_booking_id' => $booking->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students");
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertSame('B1', $row['final_level']);
        $this->assertTrue($row['is_assigned']);
        $this->assertSame('assigned', $row['class_assignment']['status']);
        $this->assertSame('B1 Class 01', $row['class_assignment']['class_name']);
        $this->assertSame($instructor->name ?? $user->name, $row['class_assignment']['instructor_name']);
        // assigned_by was left null (ClassAssignmentService's convention for automatic assignment).
        $this->assertSame('automatic', $row['class_assignment']['method']);
        $this->assertNull($row['class_assignment']['assigned_by_name']);
        $this->assertSame(
            ['level_match', 'same_course', 'capacity_available', 'lowest_occupancy'],
            $row['class_assignment']['reason_details'],
        );
    }

    public function test_manually_assigned_student_reports_manual_method_and_assigner_name(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->withCompletedWrittenTest($student, $course);

        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class 01', 'capacity' => 10, 'status' => 'ready',
        ]);
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now(), 'assigned_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students");
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertSame('manual', $row['class_assignment']['method']);
        $this->assertSame($user->name, $row['class_assignment']['assigned_by_name']);
        $this->assertSame([], $row['class_assignment']['reason_details']);
    }

    public function test_unassigned_student_returns_waiting_status(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->withCompletedWrittenTest($student, $course);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students");
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertFalse($row['is_assigned']);
        $this->assertSame('waiting_class_assignment', $row['class_assignment']['status']);
        $this->assertNull($row['class_assignment']['class_name']);
    }

    public function test_instructor_cannot_access_another_instructors_course_students(): void
    {
        [, $ownerInstructor] = $this->instructor();
        [$otherUser, ] = $this->instructor();
        $course = $this->course($ownerInstructor);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/instructor/courses/{$course->id}/students")
            ->assertStatus(403);
    }

    public function test_placement_data_from_another_course_is_not_exposed(): void
    {
        [$user, $instructor] = $this->instructor();
        $courseA = $this->course($instructor, ['title' => 'Course A']);
        $courseB = $this->course($instructor, ['title' => 'Course B']);

        $student = $this->student();
        $this->enroll($student, $courseA);
        $this->enroll($student, $courseB);
        // Student only completed the written test for course A.
        $this->withCompletedWrittenTest($student, $courseA);

        $responseB = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$courseB->id}/students");
        $responseB->assertOk();

        $rowB = collect($responseB->json('data'))->firstWhere('student_id', $student->id);
        $this->assertNull($rowB['written_score']);
        $this->assertSame('not_started', $rowB['placement_status']);
    }

    public function test_course_students_endpoint_has_no_n_plus_1_queries(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        foreach (range(1, 5) as $i) {
            $student = $this->student();
            $this->enroll($student, $course);
            $this->withCompletedWrittenTest($student, $course);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students")->assertOk();
        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Bulk-keyed maps (registrations, attempts, bookings, class assignments)
        // should stay roughly constant regardless of student count — well under
        // one query per student, which is what an N+1 regression would produce.
        $this->assertLessThan(15, $queryCount, "Expected a small constant query count, got {$queryCount} for 5 students.");
    }
}
