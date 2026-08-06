<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\CourseSession;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 7 audit found a real, still-open bug: CourseMaterial.session_id /
 * course_session_id / module_id were never cross-validated against the
 * material's own course_id/class_group_id, at either write time or
 * authorization time. A material could be linked to a session belonging to
 * a different course or a different class within the same course, and a
 * student in that other class could still fetch it because the policy only
 * ever compared material.class_group_id directly.
 */
class MaterialScopeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

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

    private function classGroup(Course $course, Instructor $instructor, array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'Class', 'capacity' => 20, 'status' => 'active',
        ], $overrides));
    }

    private function assignToClass(ClassGroup $group, User $student): void
    {
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);
    }

    // ── Write-time validation (admin create/update) ────────────────────────

    public function test_admin_cannot_create_material_linking_a_session_from_a_different_course(): void
    {
        $admin = $this->admin();
        [, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $courseB = $this->course($instructorB);
        $sessionB = LmsSession::create([
            'course_id' => $courseB->id, 'instructor_id' => $instructorB->id,
            'title' => 'B session', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/materials', [
            'course_id' => $courseA->id,
            'session_id' => $sessionB->id,
            'title' => 'Cross-course material',
            'type' => 'link',
            'external_url' => 'https://example.com/x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['session_id']);
    }

    public function test_admin_cannot_create_material_linking_a_session_from_a_different_class(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);
        $sessionB = LmsSession::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id, 'class_group_id' => $groupB->id,
            'title' => 'B session', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/materials', [
            'course_id' => $course->id,
            'class_group_id' => $groupA->id,
            'session_id' => $sessionB->id,
            'title' => 'Cross-class material',
            'type' => 'link',
            'external_url' => 'https://example.com/x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['session_id']);
    }

    public function test_admin_cannot_update_material_to_link_a_module_from_a_different_course(): void
    {
        $admin = $this->admin();
        [, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $courseB = $this->course($instructorB);
        $moduleB = CourseModule::create(['course_id' => $courseB->id, 'title' => 'B module', 'sort_order' => 1, 'status' => 'published', 'is_active' => true]);

        $material = CourseMaterial::create(['course_id' => $courseA->id, 'title' => 'Material', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/materials/{$material->id}", [
            'module_id' => $moduleB->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['module_id']);
    }

    public function test_admin_can_create_material_with_consistent_scope(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $session = LmsSession::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id, 'class_group_id' => $group->id,
            'title' => 'Session', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/materials', [
            'course_id' => $course->id,
            'class_group_id' => $group->id,
            'session_id' => $session->id,
            'title' => 'Consistent material',
            'type' => 'link',
            'external_url' => 'https://example.com/x',
        ]);

        $response->assertStatus(201);
    }

    // ── Material → Lesson linkage ───────────────────────────────────────────

    public function test_admin_can_create_material_linked_to_a_lesson_in_the_same_module(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'Module', 'sort_order' => 1, 'status' => 'published', 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'Lesson', 'sort_order' => 1, 'status' => 'published']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/materials', [
            'course_id' => $course->id,
            'module_id' => $module->id,
            'lesson_id' => $lesson->id,
            'title' => 'Lesson material',
            'type' => 'link',
            'external_url' => 'https://example.com/x',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.lesson_id', $lesson->id);
    }

    public function test_admin_cannot_link_a_lesson_belonging_to_a_different_module(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $moduleA = CourseModule::create(['course_id' => $course->id, 'title' => 'A', 'sort_order' => 1, 'status' => 'published', 'is_active' => true]);
        $moduleB = CourseModule::create(['course_id' => $course->id, 'title' => 'B', 'sort_order' => 2, 'status' => 'published', 'is_active' => true]);
        $lessonB = Lesson::create(['module_id' => $moduleB->id, 'title' => 'B Lesson', 'sort_order' => 1, 'status' => 'published']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/materials', [
            'course_id' => $course->id,
            'module_id' => $moduleA->id,
            'lesson_id' => $lessonB->id,
            'title' => 'Mismatched material',
            'type' => 'link',
            'external_url' => 'https://example.com/x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lesson_id']);
    }

    public function test_admin_cannot_link_a_lesson_from_a_different_course(): void
    {
        $admin = $this->admin();
        [, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $courseB = $this->course($instructorB);
        $moduleB = CourseModule::create(['course_id' => $courseB->id, 'title' => 'B', 'sort_order' => 1, 'status' => 'published', 'is_active' => true]);
        $lessonB = Lesson::create(['module_id' => $moduleB->id, 'title' => 'B Lesson', 'sort_order' => 1, 'status' => 'published']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/materials', [
            'course_id' => $courseA->id,
            'lesson_id' => $lessonB->id,
            'title' => 'Cross-course lesson material',
            'type' => 'link',
            'external_url' => 'https://example.com/x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lesson_id']);
    }

    public function test_admin_cannot_link_a_forged_nonexistent_lesson_id(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/materials', [
            'course_id' => $course->id,
            'lesson_id' => 999999,
            'title' => 'Forged lesson material',
            'type' => 'link',
            'external_url' => 'https://example.com/x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lesson_id']);
    }

    // ── Authorization-time cross-class leakage via session_id ──────────────

    public function test_student_cannot_view_material_whose_session_belongs_to_a_different_class(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);

        $studentA = $this->student();
        $this->enroll($studentA, $course);
        $this->assignToClass($groupA, $studentA);

        $sessionB = LmsSession::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id, 'class_group_id' => $groupB->id,
            'title' => 'B session', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        // Material has no class_group_id of its own (course-wide on paper),
        // but is linked to a session that belongs to class B only.
        $material = CourseMaterial::create([
            'course_id' => $course->id, 'session_id' => $sessionB->id,
            'title' => 'Session-linked material', 'type' => 'pdf', 'is_visible' => true,
        ]);

        $this->actingAs($studentA, 'sanctum')
            ->getJson("/api/student/materials/{$material->id}")
            ->assertStatus(403);
    }

    public function test_student_can_view_material_whose_session_belongs_to_their_own_class(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $student = $this->student();
        $this->enroll($student, $course);
        $this->assignToClass($group, $student);

        $session = LmsSession::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id, 'class_group_id' => $group->id,
            'title' => 'Session', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'session_id' => $session->id,
            'title' => 'Session-linked material', 'type' => 'pdf', 'is_visible' => true,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/materials/{$material->id}")
            ->assertOk();
    }
}
