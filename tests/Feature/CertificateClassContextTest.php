<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use App\Services\CertificateEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 1 Phase 5: certificates/eligibility don't have a class_group_id
 * column (no migration added — deliberately, to avoid risk to the live
 * automated issuance pipeline). Instead, class context is resolved
 * deterministically on read via ClassGroupStudent, purely additive.
 */
class CertificateClassContextTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): Instructor
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);

        return Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    public function test_resolves_the_students_actual_active_class_for_the_course(): void
    {
        $instructor = $this->instructor();
        $course = Course::factory()->create(['status' => 'published', 'instructor_id' => $instructor->id]);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class', 'capacity' => 10, 'status' => 'completed',
        ]);
        $student = $this->student();
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $result = (new CertificateEligibilityService())->resolveClassGroupForUser($student->id, $course->id);

        $this->assertNotNull($result);
        $this->assertSame($group->id, $result['class_group_id']);
        $this->assertSame('B1 Class', $result['name']);
        $this->assertSame('completed', $result['status']);
    }

    public function test_returns_null_when_student_has_no_active_class_in_that_course(): void
    {
        $instructor = $this->instructor();
        $course = Course::factory()->create(['status' => 'published', 'instructor_id' => $instructor->id]);
        $student = $this->student();

        $result = (new CertificateEligibilityService())->resolveClassGroupForUser($student->id, $course->id);

        $this->assertNull($result);
    }

    public function test_does_not_resolve_a_different_courses_class(): void
    {
        $instructor = $this->instructor();
        $courseA = Course::factory()->create(['status' => 'published', 'instructor_id' => $instructor->id]);
        $courseB = Course::factory()->create(['status' => 'published', 'instructor_id' => $instructor->id]);
        $groupB = ClassGroup::create([
            'course_id' => $courseB->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'Course B Class', 'capacity' => 10, 'status' => 'active',
        ]);
        $student = $this->student();
        ClassGroupStudent::create([
            'class_group_id' => $groupB->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);

        // Student is in a class for course B, not course A.
        $result = (new CertificateEligibilityService())->resolveClassGroupForUser($student->id, $courseA->id);

        $this->assertNull($result);
    }

    public function test_removed_membership_is_not_resolved(): void
    {
        $instructor = $this->instructor();
        $course = Course::factory()->create(['status' => 'published', 'instructor_id' => $instructor->id]);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1', 'capacity' => 10, 'status' => 'active',
        ]);
        $student = $this->student();
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => 'removed', 'assigned_at' => now(),
        ]);

        $result = (new CertificateEligibilityService())->resolveClassGroupForUser($student->id, $course->id);

        $this->assertNull($result);
    }

    public function test_forCourse_includes_class_group_context_without_changing_eligibility(): void
    {
        $instructor = $this->instructor();
        $course = Course::factory()->create([
            'status' => 'published', 'instructor_id' => $instructor->id,
        ]);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1', 'capacity' => 10, 'status' => 'active',
        ]);
        $student = $this->student();
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);
        StudentProgress::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'progress_percentage' => 90, 'attendance_percentage' => 90, 'status' => 'in_progress',
        ]);

        $results = (new CertificateEligibilityService())->forCourse($course->id);
        $row = collect($results)->firstWhere('user_id', $student->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['is_eligible']); // unchanged eligibility logic
        $this->assertSame($group->id, $row['class_group']['class_group_id']);
    }
}
