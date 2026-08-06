<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\OralAssessmentSlot;
use App\Models\PlacementTest;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Models\User;
use App\Services\ClassAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassScopedLearningTest extends TestCase
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

    private function classGroup(Course $course, Instructor $instructor, array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id'     => $course->id,
            'instructor_id' => $instructor->id,
            'level_code'    => 'B1',
            'name'          => 'B1 - Section A',
            'capacity'      => 2,
            'status'        => 'ready',
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

    public function test_automatic_assignment_matches_by_level_and_prefers_lowest_occupancy(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'B1-A', 'capacity' => 5]);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B1-B', 'capacity' => 5]);
        // Fill groupA with one existing student so groupB has lower occupancy.
        $existing = $this->student();
        ClassGroupStudent::create([
            'class_group_id' => $groupA->id, 'user_id' => $existing->id, 'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $student = $this->student();
        $service = app(ClassAssignmentService::class);
        $result = $service->autoAssign($student, $course, 'B1');

        $this->assertTrue($result['assigned']);
        $this->assertSame($groupB->id, $result['class_group_student']->class_group_id);
    }

    public function test_automatic_assignment_skips_full_classes(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $full = $this->classGroup($course, $instructor, ['capacity' => 1]);
        $open = $this->classGroup($course, $instructor, ['name' => 'B1-open', 'capacity' => 1]);

        $existing = $this->student();
        ClassGroupStudent::create([
            'class_group_id' => $full->id, 'user_id' => $existing->id, 'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $student = $this->student();
        $result = app(ClassAssignmentService::class)->autoAssign($student, $course, 'B1');

        $this->assertTrue($result['assigned']);
        $this->assertSame($open->id, $result['class_group_student']->class_group_id);
    }

    public function test_automatic_assignment_leaves_student_waiting_when_no_matching_class(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $this->classGroup($course, $instructor, ['level_code' => 'A2']); // wrong level

        $student = $this->student();
        $result = app(ClassAssignmentService::class)->autoAssign($student, $course, 'B1');

        $this->assertFalse($result['assigned']);
        $this->assertSame('no_matching_group', $result['reason']);
        $this->assertDatabaseMissing('class_group_students', ['user_id' => $student->id]);
    }

    public function test_automatic_assignment_does_not_double_assign(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $student = $this->student();
        $service = app(ClassAssignmentService::class);
        $service->autoAssign($student, $course, 'B1');
        $result = $service->autoAssign($student, $course, 'B1');

        $this->assertFalse($result['assigned']);
        $this->assertSame('already_assigned', $result['reason']);
        $this->assertSame(1, ClassGroupStudent::where('user_id', $student->id)->count());
    }

    public function test_assignment_writes_history_row(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $this->classGroup($course, $instructor);
        $student = $this->student();

        $result = app(ClassAssignmentService::class)->autoAssign($student, $course, 'B1');

        $this->assertDatabaseHas('class_group_student_histories', [
            'class_group_student_id' => $result['class_group_student']->id,
            'user_id'                => $student->id,
            'action'                 => 'assigned',
            'reason'                 => 'automatic',
        ]);
    }

    public function test_completing_oral_interview_triggers_automatic_assignment_end_to_end(): void
    {
        [$instructorUser, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $this->classGroup($course, $instructor, ['level_code' => 'B1']);
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
            'booking_status' => 'booked', 'is_booked' => true, 'booked_by' => $student->id,
        ]);
        $booking = OralAssessmentBooking::create([
            'user_id' => $student->id, 'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'placement_test_attempt_id' => $attempt->id, 'slot_id' => $slot->id,
            'status' => OralAssessmentBooking::STATUS_BOOKED,
        ]);

        $response = $this->actingAs($instructorUser, 'sanctum')
            ->patchJson("/api/instructor/oral-assessments/{$booking->id}/complete", [
                'final_level' => 'B1',
                'oral_score'  => 80,
            ]);

        $response->assertOk();
        $response->assertJsonPath('class_assignment.assigned', true);
        $this->assertDatabaseHas('class_group_students', [
            'user_id' => $student->id, 'status' => 'assigned',
        ]);
    }

    public function test_student_only_sees_own_class_group_content(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'B1-A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B1-B']);

        $studentA = $this->student();
        $this->enroll($studentA, $course);
        ClassGroupStudent::create([
            'class_group_id' => $groupA->id, 'user_id' => $studentA->id, 'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $assignmentA = CourseAssignment::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id,
            'title' => 'Class A only', 'status' => 'published', 'is_visible' => true,
        ]);
        $assignmentB = CourseAssignment::create([
            'course_id' => $course->id, 'class_group_id' => $groupB->id,
            'title' => 'Class B only', 'status' => 'published', 'is_visible' => true,
        ]);
        $unscoped = CourseAssignment::create([
            'course_id' => $course->id, 'class_group_id' => null,
            'title' => 'Whole course', 'status' => 'published', 'is_visible' => true,
        ]);

        $response = $this->actingAs($studentA, 'sanctum')->getJson('/api/student/assignments');
        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Class A only'));
        $this->assertTrue($titles->contains('Whole course'));
        $this->assertFalse($titles->contains('Class B only'));
    }
}
