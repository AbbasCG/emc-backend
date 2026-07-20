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

/**
 * Cross-endpoint consistency for the canonical student summary (Ticket 2).
 * Every instructor endpoint listed below must report the identical written
 * score, oral score/breakdown, final level, and class assignment for the
 * same student + course — no controller may compute these independently.
 */
class InstructorStudentDataConsistencyTest extends TestCase
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

    /** Fully assigned student: written test + evaluated oral booking + class membership. */
    private function fullyPlacedStudent(User $user, Instructor $instructor, Course $course): array
    {
        $student = $this->student();
        $this->enroll($student, $course);

        $test = PlacementTest::create([
            'title' => 'English Placement', 'language' => 'en',
            'duration_minutes' => 30, 'total_questions' => 70, 'is_active' => true,
        ]);
        $attempt = PlacementTestAttempt::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'placement_test_id' => $test->id,
            'score' => 42, 'total_questions' => 70, 'percentage' => 60.0,
            'estimated_level' => 'B1', 'status' => 'completed', 'submitted_at' => now(),
        ]);

        $slot = OralAssessmentSlot::create([
            'instructor_id' => $instructor->id, 'course_id' => $course->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30),
            'booking_status' => 'completed', 'is_booked' => true, 'booked_by' => $student->id,
        ]);
        $booking = OralAssessmentBooking::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'placement_test_attempt_id' => $attempt->id, 'slot_id' => $slot->id,
            'status' => 'completed', 'final_level' => 'B1', 'oral_score' => 78,
            'pronunciation_score' => 8, 'grammar_score' => 7, 'vocabulary_score' => 8,
            'fluency_score' => 7, 'comprehension_score' => 8, 'confidence_score' => 8,
            'evaluated_at' => now(),
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

        return [$student, $group];
    }

    public function test_written_score_oral_score_and_final_level_are_identical_across_all_endpoints(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        [$student, $group] = $this->fullyPlacedStudent($user, $instructor, $course);

        $myStudents        = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students')->assertOk();
        $courseStudents     = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students")->assertOk();
        $placementStudents  = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/placement-students")->assertOk();
        $placementTests     = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/placement-tests')->assertOk();
        $classStudents      = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$group->id}/students")->assertOk();

        $myRow        = collect($myStudents->json('data'))->firstWhere('student_id', $student->id);
        $courseRow    = collect($courseStudents->json('data'))->firstWhere('student_id', $student->id);
        $placementRow = collect($placementStudents->json('data'))->firstWhere('student_id', $student->id);
        $testsRow     = collect($placementTests->json('data'))->firstWhere('student_id', $student->id);
        $classRow     = collect($classStudents->json('data.students'))->firstWhere('id', $student->id);

        $this->assertNotNull($myRow);
        $this->assertNotNull($courseRow);
        $this->assertNotNull($placementRow);
        $this->assertNotNull($testsRow);
        $this->assertNotNull($classRow, 'student missing from class-students endpoint');

        // Written score identical everywhere it's reported.
        foreach (['myRow' => $myRow, 'courseRow' => $courseRow, 'placementRow' => $placementRow, 'testsRow' => $testsRow] as $label => $row) {
            $this->assertSame(42, $row['written_score'], "written_score mismatch on {$label}");
            $this->assertSame('B1', $row['written_level'], "written_level mismatch on {$label}");
        }

        // Oral score identical everywhere.
        foreach (['myRow' => $myRow, 'courseRow' => $courseRow, 'placementRow' => $placementRow, 'testsRow' => $testsRow] as $label => $row) {
            $this->assertSame(78, $row['oral_score'], "oral_score mismatch on {$label}");
        }

        // Oral breakdown (rubric) identical everywhere it's exposed — this is
        // the exact defect this ticket describes ("oral score present but
        // breakdown missing"). Must be present, not silently omitted.
        foreach (['myRow' => $myRow, 'courseRow' => $courseRow, 'placementRow' => $placementRow, 'testsRow' => $testsRow] as $label => $row) {
            $this->assertNotNull($row['oral_assessment_full'], "oral_assessment_full missing on {$label}");
            $this->assertSame(78, $row['oral_assessment_full']['oral_score'], "rubric oral_score mismatch on {$label}");
            $this->assertCount(6, $row['oral_assessment_full']['rubric'], "rubric incomplete on {$label}");
        }

        // Final level identical everywhere.
        foreach (['myRow' => $myRow, 'courseRow' => $courseRow, 'placementRow' => $placementRow, 'testsRow' => $testsRow] as $label => $row) {
            $this->assertSame('B1', $row['final_level'], "final_level mismatch on {$label}");
        }

        // Class assignment identical everywhere — never "assigned" on one page
        // and "waiting" on another for the same student+course.
        foreach (['myRow' => $myRow, 'courseRow' => $courseRow, 'placementRow' => $placementRow, 'testsRow' => $testsRow] as $label => $row) {
            $this->assertTrue($row['is_assigned'], "is_assigned mismatch on {$label}");
            $this->assertSame('assigned', $row['class_assignment']['status'], "class_assignment status mismatch on {$label}");
            $this->assertSame('B1 Class 01', $row['class_assignment']['class_name'], "class_name mismatch on {$label}");
            $this->assertSame($group->id, $row['class_assignment']['class_group_id'], "class_group_id mismatch on {$label}");
        }
    }

    public function test_unassigned_student_reports_canonical_waiting_state_everywhere(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $myStudents    = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students')->assertOk();
        $courseStudents = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students")->assertOk();

        $myRow     = collect($myStudents->json('data'))->firstWhere('student_id', $student->id);
        $courseRow = collect($courseStudents->json('data'))->firstWhere('student_id', $student->id);

        foreach (['myRow' => $myRow, 'courseRow' => $courseRow] as $label => $row) {
            $this->assertFalse($row['is_assigned'], "is_assigned mismatch on {$label}");
            $this->assertSame('waiting_class_assignment', $row['class_assignment']['status'], "status mismatch on {$label}");
            $this->assertNull($row['class_assignment']['class_name'], "class_name should be null on {$label}");
        }
    }

    public function test_removed_membership_is_ignored_on_my_students_and_course_students(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class 01', 'capacity' => 10, 'status' => 'ready',
        ]);
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => 'removed', 'assigned_at' => now(),
        ]);

        $myStudents    = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students')->assertOk();
        $courseStudents = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students")->assertOk();

        $myRow     = collect($myStudents->json('data'))->firstWhere('student_id', $student->id);
        $courseRow = collect($courseStudents->json('data'))->firstWhere('student_id', $student->id);

        $this->assertFalse($myRow['is_assigned']);
        $this->assertFalse($courseRow['is_assigned']);
    }

    public function test_multi_course_student_receives_course_specific_class_on_my_students(): void
    {
        [$user, $instructor] = $this->instructor();
        $courseA = $this->course($instructor, ['title' => 'Course A']);
        $courseB = $this->course($instructor, ['title' => 'Course B']);

        $student = $this->student();
        $this->enroll($student, $courseA);
        $this->enroll($student, $courseB);

        $groupA = ClassGroup::create([
            'course_id' => $courseA->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'A-Class', 'capacity' => 10, 'status' => 'ready',
        ]);
        ClassGroupStudent::create([
            'class_group_id' => $groupA->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);
        // Student has no membership in courseB — must show waiting, not courseA's class.

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students')->assertOk();
        $rows = collect($response->json('data'))->where('student_id', $student->id);

        $rowA = $rows->firstWhere('course_id', $courseA->id);
        $rowB = $rows->firstWhere('course_id', $courseB->id);

        $this->assertSame('A-Class', $rowA['class_assignment']['class_name']);
        $this->assertNull($rowB['class_assignment']['class_name']);
        $this->assertFalse($rowB['is_assigned']);
    }

    public function test_another_instructor_cannot_access_my_students_data(): void
    {
        [, $ownerInstructor] = $this->instructor();
        [$otherUser, ] = $this->instructor();
        $course = $this->course($ownerInstructor);
        $student = $this->student();
        $this->enroll($student, $course);

        // /api/instructor/students is scoped by the caller's own instructor_id
        // automatically — the other instructor simply gets an empty list, not
        // the owner's data, since Course::where('instructor_id', ...) excludes it.
        $response = $this->actingAs($otherUser, 'sanctum')->getJson('/api/instructor/students')->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    public function test_my_students_endpoint_has_no_n_plus_1_queries(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        foreach (range(1, 5) as $i) {
            [$student] = $this->fullyPlacedStudent($user, $instructor, $course);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($user, 'sanctum')->getJson('/api/instructor/students')->assertOk();
        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThan(20, $queryCount, "Expected a small constant query count, got {$queryCount} for 5 students.");
    }
}
