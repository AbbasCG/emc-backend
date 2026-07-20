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

/** Proves /instructor/students, /instructor/courses/{course}/students, and
 *  /instructor/placement-tests can never disagree about the same student's
 *  class assignment — the exact bug class found for "Test-7" this session. */
class InstructorStudentAssignmentConsistencyTest extends TestCase
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

    /** Fully assigns a student to a class within the given course — written
     *  test, completed oral interview, and an active class_group_students row. */
    private function assignToClass(User $student, Course $course, Instructor $instructor, string $className): void
    {
        $test = PlacementTest::create([
            'title' => 'English Placement', 'language' => 'en',
            'duration_minutes' => 30, 'total_questions' => 70, 'is_active' => true,
        ]);
        $attempt = PlacementTestAttempt::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'placement_test_id' => $test->id,
            'score' => 1, 'total_questions' => 70, 'percentage' => 1.4,
            'estimated_level' => 'Starter', 'status' => 'completed', 'submitted_at' => now(),
        ]);
        $slot = OralAssessmentSlot::create([
            'instructor_id' => $instructor->id, 'course_id' => $course->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30),
            'booking_status' => 'completed', 'is_booked' => true, 'booked_by' => $student->id,
        ]);
        $booking = OralAssessmentBooking::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'placement_test_attempt_id' => $attempt->id, 'slot_id' => $slot->id,
            'status' => 'completed', 'final_level' => 'Starter', 'oral_score' => 50,
        ]);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'Starter', 'name' => $className, 'capacity' => 20, 'status' => 'ready',
        ]);
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'placement_attempt_id' => $attempt->id, 'oral_assessment_booking_id' => $booking->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);
    }

    public function test_my_students_endpoint_returns_class_assignment(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->assignToClass($student, $course, $instructor, 'A1');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('class_assignment', $row);
        $this->assertSame('assigned', $row['class_assignment']['status']);
        $this->assertSame('A1', $row['class_assignment']['class_name']);
        $this->assertTrue($row['is_assigned']);
    }

    public function test_placement_tests_endpoint_returns_class_assignment(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->assignToClass($student, $course, $instructor, 'A1');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/placement-tests');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('class_assignment', $row);
        $this->assertSame('assigned', $row['class_assignment']['status']);
        $this->assertSame('A1', $row['class_assignment']['class_name']);
    }

    public function test_same_student_shows_the_same_class_on_every_instructor_endpoint(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->assignToClass($student, $course, $instructor, 'A1');

        $myStudents  = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students')->json('data');
        $courseStuds = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students")->json('data');
        $placement   = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/placement-students")->json('data');
        $tests       = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/placement-tests')->json('data');

        $rowA = collect($myStudents)->firstWhere('student_id', $student->id);
        $rowB = collect($courseStuds)->firstWhere('student_id', $student->id);
        $rowC = collect($placement)->firstWhere('student_id', $student->id);
        $rowD = collect($tests)->firstWhere('student_id', $student->id);

        foreach ([$rowA, $rowB, $rowC, $rowD] as $row) {
            $this->assertSame('assigned', $row['class_assignment']['status']);
            $this->assertSame('A1', $row['class_assignment']['class_name']);
        }
        $this->assertSame($rowA['class_assignment']['class_group_id'], $rowB['class_assignment']['class_group_id']);
        $this->assertSame($rowB['class_assignment']['class_group_id'], $rowC['class_assignment']['class_group_id']);
        $this->assertSame($rowC['class_assignment']['class_group_id'], $rowD['class_assignment']['class_group_id']);
    }

    public function test_unassigned_student_returns_canonical_waiting_object_on_my_students(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertFalse($row['is_assigned']);
        $this->assertSame('waiting_class_assignment', $row['class_assignment']['status']);
        $this->assertNull($row['class_assignment']['class_group_id']);
        $this->assertNull($row['class_assignment']['class_name']);
    }

    public function test_removed_membership_is_ignored_on_my_students(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->assignToClass($student, $course, $instructor, 'A1');

        ClassGroupStudent::where('user_id', $student->id)->update(['status' => 'removed']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertSame('waiting_class_assignment', $row['class_assignment']['status']);
    }

    public function test_student_in_two_courses_gets_the_course_specific_class_not_the_other_ones(): void
    {
        [$user, $instructor] = $this->instructor();
        $courseA = $this->course($instructor, ['title' => 'Course A']);
        $courseB = $this->course($instructor, ['title' => 'Course B']);
        $student = $this->student();
        $this->enroll($student, $courseA);
        $this->enroll($student, $courseB);

        // Assigned to a class in course A only.
        $this->assignToClass($student, $courseA, $instructor, 'A1');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students');
        $response->assertOk();

        $rows = collect($response->json('data'))->where('student_id', $student->id);
        $rowA = $rows->firstWhere('course_id', $courseA->id);
        $rowB = $rows->firstWhere('course_id', $courseB->id);

        $this->assertSame('assigned', $rowA['class_assignment']['status']);
        $this->assertSame('A1', $rowA['class_assignment']['class_name']);
        // Course B must NOT inherit course A's class assignment.
        $this->assertSame('waiting_class_assignment', $rowB['class_assignment']['status']);
        $this->assertNull($rowB['class_assignment']['class_name']);
    }

    public function test_another_instructor_cannot_access_the_data(): void
    {
        [, $ownerInstructor] = $this->instructor();
        [$otherUser, ] = $this->instructor();
        $course = $this->course($ownerInstructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $this->assignToClass($student, $course, $ownerInstructor, 'A1');

        $response = $this->actingAs($otherUser, 'sanctum')->getJson('/api/instructor/students');
        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    public function test_my_students_endpoint_has_no_n_plus_1_queries(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        foreach (range(1, 5) as $i) {
            $student = $this->student();
            $this->enroll($student, $course);
            $this->assignToClass($student, $course, $instructor, "Class-{$i}");
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students')->assertOk();
        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThan(15, $queryCount, "Expected a small constant query count, got {$queryCount} for 5 students.");
    }
}
