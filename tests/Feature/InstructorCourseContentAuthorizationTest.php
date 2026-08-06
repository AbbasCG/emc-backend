<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Block 3 audit result: InstructorCourseContentController's authorise()/
 * authoriseByCourse()/enforceCourseScope() were read in full and found
 * to already correctly implement every required guarantee — no
 * null-Instructor-profile bypass, no cross-instructor mutation, no
 * cross-course ID forgery via either nested or flat routes, course
 * derived server-side from the resolved model (never trusted from
 * client input). This suite proves that finding rather than asserting
 * it from memory. Module/lesson content in this controller is
 * course-global (module_id/course_id only — no class_group_id
 * anywhere in its validation rules or route shape), so class
 * ownership is correctly NOT treated as course-content ownership.
 */
class InstructorCourseContentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): array
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $profile = Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);
        return [$user, $profile];
    }

    private function course(Instructor $instructor): Course
    {
        return Course::factory()->create(['title' => 'English', 'status' => 'published', 'instructor_id' => $instructor->id]);
    }

    public function test_owner_instructor_can_create_a_module(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules", ['title' => 'New Module'])
            ->assertStatus(201);
    }

    public function test_another_instructor_cannot_create_a_module_in_a_course_they_do_not_own(): void
    {
        [, $ownerProfile] = $this->instructor();
        [$otherUser] = $this->instructor();
        $course = $this->course($ownerProfile);

        $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules", ['title' => 'Intrusion'])
            ->assertStatus(403);
    }

    public function test_instructor_without_a_linked_profile_cannot_bypass_authorization(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $userWithoutProfile = User::factory()->create(['role' => 'instructor', 'is_active' => true]);

        $this->actingAs($userWithoutProfile, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules", ['title' => 'Bypass attempt'])
            ->assertStatus(403);
    }

    public function test_another_instructor_cannot_update_a_module_via_the_flat_route(): void
    {
        [, $ownerProfile] = $this->instructor();
        [$otherUser] = $this->instructor();
        $course = $this->course($ownerProfile);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);

        // Flat route (/instructor/modules/{module}) — ownership must still be enforced
        // via the module's own actual course_id, not any client-supplied value.
        $this->actingAs($otherUser, 'sanctum')
            ->putJson("/api/instructor/modules/{$module->id}", ['title' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertSame('M', $module->fresh()->title);
    }

    public function test_forged_module_id_cannot_cross_course_boundary_via_nested_route(): void
    {
        [$user, $ownProfile] = $this->instructor();
        [, $otherProfile] = $this->instructor();
        $ownCourse = $this->course($ownProfile);
        $otherCourse = $this->course($otherProfile);
        $foreignModule = CourseModule::create(['course_id' => $otherCourse->id, 'title' => 'Foreign', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);

        // Instructor owns $ownCourse but tries to update a module belonging to $otherCourse
        // via the nested route that names their own course in the URL.
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/courses/{$ownCourse->id}/modules/{$foreignModule->id}", ['title' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Foreign', $foreignModule->fresh()->title);
    }

    public function test_owner_instructor_can_create_and_update_a_lesson(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/lessons", ['module_id' => $module->id, 'title' => 'New Lesson']);
        $response->assertStatus(201);
        $lessonId = $response->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/lessons/{$lessonId}", ['title' => 'Updated Lesson'])
            ->assertOk();
    }

    public function test_another_instructor_cannot_delete_a_lesson_they_do_not_own(): void
    {
        [, $ownerProfile] = $this->instructor();
        [$otherUser] = $this->instructor();
        $course = $this->course($ownerProfile);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'L', 'sort_order' => 0, 'status' => 'active']);

        $this->actingAs($otherUser, 'sanctum')
            ->deleteJson("/api/instructor/lessons/{$lesson->id}")
            ->assertStatus(403);

        $this->assertNotNull($lesson->fresh());
    }

    public function test_admin_can_manage_any_instructors_module_content(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules", ['title' => 'Admin-created module'])
            ->assertStatus(201);
    }
}
