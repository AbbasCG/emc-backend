<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for a real IDOR in CourseMaterialPolicy: index() (via
 * visibleToClassGroupIn()) correctly hid class-scoped materials from students
 * outside that class, but view()/download()/thumbnail()/medium() only checked
 * course enrollment — a student in the same course but a different class
 * group could fetch a class-scoped material directly by ID. Same pattern
 * already found and fixed in the Assignment (Ticket 5) and Attendance
 * (Ticket 6) systems this session.
 */
class ClassScopedMaterialSecurityTest extends TestCase
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

    private function enroll(User $student, Course $course): void
    {
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    private function classGroup(Course $course, Instructor $instructor, array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1 Class 01', 'capacity' => 20, 'status' => 'active',
        ], $overrides));
    }

    private function assignToClass(ClassGroup $group, User $student): void
    {
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);
    }

    public function test_student_outside_class_cannot_view_class_scoped_material(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);

        $outsider = $this->student();
        $this->enroll($outsider, $course);
        // enrolled in the course, but NOT a member of $groupA

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id,
            'title' => 'Class A Material', 'type' => 'pdf', 'is_visible' => true,
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/student/materials/{$material->id}")
            ->assertStatus(403);
    }

    public function test_student_outside_class_cannot_download_class_scoped_material(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);

        $outsider = $this->student();
        $this->enroll($outsider, $course);

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id,
            'title' => 'Class A Material', 'type' => 'pdf', 'is_visible' => true,
            'file_path' => 'materials/fake.pdf', 'disk' => 'private',
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->get("/api/materials/{$material->id}/download")
            ->assertStatus(403);
    }

    public function test_student_inside_class_can_view_class_scoped_material(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $member = $this->student();
        $this->enroll($member, $course);
        $this->assignToClass($group, $member);

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'class_group_id' => $group->id,
            'title' => 'Class Material', 'type' => 'pdf', 'is_visible' => true,
        ]);

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/student/materials/{$material->id}")
            ->assertOk();
    }

    public function test_course_wide_material_remains_visible_to_all_enrolled_students(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        // No class_group_id — course-wide material, no class gate applies.

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'title' => 'Course-wide Material',
            'type' => 'pdf', 'is_visible' => true,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/materials/{$material->id}")
            ->assertOk();
    }
}
