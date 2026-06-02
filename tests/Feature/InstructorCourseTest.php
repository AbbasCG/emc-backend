<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests instructor-specific course access.
 *
 * Key architecture fact: Course.instructor_id references instructors.id
 * (the instructor profile record), NOT users.id.
 * Access to instructor routes requires role:teacher OR role:instructor.
 */
class InstructorCourseTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Create an instructor user, their profile, and a course assigned to them. */
    private function createInstructorWithCourse(): array
    {
        $user = User::factory()->create(['role' => 'instructor']);

        $profile = Instructor::create([
            'user_id' => $user->id,
            'name'    => $user->name,
            'title'   => 'Senior Instructor',
            'bio'     => 'Expert in web development.',
        ]);

        $course = Course::factory()->create([
            'instructor_id' => $profile->id,
            'status'        => 'active',
        ]);

        return [$user, $profile, $course];
    }

    // ─── Dashboard ────────────────────────────────────────────────────────────

    public function test_instructor_can_access_instructor_dashboard(): void
    {
        [$user] = $this->createInstructorWithCourse();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/instructor/dashboard')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ─── Course listing ───────────────────────────────────────────────────────

    public function test_instructor_can_list_own_courses(): void
    {
        [$user] = $this->createInstructorWithCourse();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/instructor/courses')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ─── Course content ───────────────────────────────────────────────────────

    public function test_instructor_can_view_assigned_course_content(): void
    {
        [$user, , $course] = $this->createInstructorWithCourse();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/instructor/courses/{$course->id}/content")
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_instructor_cannot_access_content_of_unassigned_course(): void
    {
        [$user] = $this->createInstructorWithCourse();

        // A course with no instructor assigned
        $unassigned = Course::factory()->create(['instructor_id' => null]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/instructor/courses/{$unassigned->id}/content");

        $this->assertContains($response->status(), [403, 404],
            "Expected 403/404 for unassigned course content, got {$response->status()}");
    }

    public function test_instructor_cannot_access_content_assigned_to_another_instructor(): void
    {
        [$userA] = $this->createInstructorWithCourse();
        [, , $courseB] = $this->createInstructorWithCourse();

        // userA tries to access courseB which belongs to a different instructor profile
        $response = $this->actingAs($userA, 'sanctum')
            ->getJson("/api/instructor/courses/{$courseB->id}/content");

        $this->assertContains($response->status(), [403, 404],
            "Expected 403/404 for cross-instructor access, got {$response->status()}");
    }

    // ─── Module CRUD ──────────────────────────────────────────────────────────

    public function test_instructor_can_create_module_in_assigned_course(): void
    {
        [$user, , $course] = $this->createInstructorWithCourse();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules", [
                'title'      => 'Module 1: Introduction',
                'sort_order' => 1,
            ]);

        $response->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('course_modules', [
            'course_id' => $course->id,
            'title'     => 'Module 1: Introduction',
        ]);
    }

    public function test_instructor_can_update_own_module(): void
    {
        [$user, , $course] = $this->createInstructorWithCourse();

        $module = CourseModule::create([
            'course_id'  => $course->id,
            'title'      => 'Original Title',
            'sort_order' => 1,
        ]);

        // Use the flat route — nested route passes {course} as extra param which confuses binding.
        // Flat route: PUT /api/instructor/modules/{module}
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/modules/{$module->id}", [
                'title'      => 'Updated Title',
                'sort_order' => 1,
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('course_modules', [
            'id'    => $module->id,
            'title' => 'Updated Title',
        ]);
    }

    public function test_instructor_can_delete_own_module(): void
    {
        [$user, , $course] = $this->createInstructorWithCourse();

        $module = CourseModule::create([
            'course_id'  => $course->id,
            'title'      => 'Module To Delete',
            'sort_order' => 1,
        ]);

        // Use the flat route — DELETE /api/instructor/modules/{module}
        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/instructor/modules/{$module->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        // CourseModule uses SoftDeletes — assert the deleted_at is set
        $this->assertSoftDeleted('course_modules', ['id' => $module->id]);
    }

    public function test_instructor_cannot_create_module_for_unassigned_course(): void
    {
        [$user] = $this->createInstructorWithCourse();
        $unassigned = Course::factory()->create(['instructor_id' => null]);

        // POST is a nested route with Course binding — this DOES work correctly (Course resolves fine)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/courses/{$unassigned->id}/modules", [
                'title'      => 'Unauthorized Module',
                'sort_order' => 1,
            ]);

        // The controller's authorise() checks course ownership; unassigned → 403
        $this->assertContains($response->status(), [403, 404, 422],
            "Expected 403/404 for module create on unassigned course, got {$response->status()}");

        $this->assertDatabaseMissing('course_modules', ['title' => 'Unauthorized Module']);
    }

    // ─── Student blocked ──────────────────────────────────────────────────────

    public function test_student_cannot_access_instructor_course_content(): void
    {
        [, , $course] = $this->createInstructorWithCourse();
        $student       = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/instructor/courses/{$course->id}/content")
            ->assertStatus(403);
    }

    public function test_student_cannot_create_course_modules(): void
    {
        [, , $course] = $this->createInstructorWithCourse();
        $student       = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules", ['title' => 'Hack'])
            ->assertStatus(403);
    }
}
