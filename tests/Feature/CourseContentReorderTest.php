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
 * Block 2: deterministic batch reorder for modules/lessons, both admin and
 * instructor surfaces, backed by the shared App\Services\ContentReorderService.
 */
class CourseContentReorderTest extends TestCase
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

    private function course(Instructor $instructor): Course
    {
        return Course::factory()->create(['title' => 'English', 'status' => 'published', 'instructor_id' => $instructor->id]);
    }

    // ── Admin module reorder ────────────────────────────────────────────────

    public function test_admin_module_reorder_sets_sequential_positions(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $m1 = CourseModule::create(['course_id' => $course->id, 'title' => 'A', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        $m2 = CourseModule::create(['course_id' => $course->id, 'title' => 'B', 'sort_order' => 1, 'status' => 'active', 'is_active' => true]);
        $m3 = CourseModule::create(['course_id' => $course->id, 'title' => 'C', 'sort_order' => 2, 'status' => 'active', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/courses/{$course->id}/modules/reorder", ['ids' => [$m3->id, $m1->id, $m2->id]])
            ->assertOk();

        $this->assertSame(0, $m3->fresh()->sort_order);
        $this->assertSame(1, $m1->fresh()->sort_order);
        $this->assertSame(2, $m2->fresh()->sort_order);
    }

    public function test_admin_module_reorder_rejects_a_module_from_another_course(): void
    {
        $admin = $this->admin();
        [, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $courseB = $this->course($instructorB);
        $m1 = CourseModule::create(['course_id' => $courseA->id, 'title' => 'A', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        $foreign = CourseModule::create(['course_id' => $courseB->id, 'title' => 'Foreign', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/courses/{$courseA->id}/modules/reorder", ['ids' => [$foreign->id, $m1->id]]);

        $response->assertStatus(422);
        $this->assertSame(0, $m1->fresh()->sort_order, 'Reorder must not partially apply on validation failure.');
    }

    public function test_admin_module_reorder_rejects_duplicate_ids(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $m1 = CourseModule::create(['course_id' => $course->id, 'title' => 'A', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        $m2 = CourseModule::create(['course_id' => $course->id, 'title' => 'B', 'sort_order' => 1, 'status' => 'active', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/courses/{$course->id}/modules/reorder", ['ids' => [$m1->id, $m1->id]])
            ->assertStatus(422);
    }

    public function test_admin_module_reorder_rejects_an_incomplete_list(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $m1 = CourseModule::create(['course_id' => $course->id, 'title' => 'A', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        CourseModule::create(['course_id' => $course->id, 'title' => 'B', 'sort_order' => 1, 'status' => 'active', 'is_active' => true]);

        // Only one of two modules submitted — must be rejected, not silently partial.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/courses/{$course->id}/modules/reorder", ['ids' => [$m1->id]])
            ->assertStatus(422);
    }

    // ── Admin lesson reorder ────────────────────────────────────────────────

    public function test_admin_lesson_reorder_sets_sequential_positions(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        $l1 = Lesson::create(['module_id' => $module->id, 'title' => 'L1', 'sort_order' => 0, 'status' => 'active']);
        $l2 = Lesson::create(['module_id' => $module->id, 'title' => 'L2', 'sort_order' => 1, 'status' => 'active']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/modules/{$module->id}/lessons/reorder", ['ids' => [$l2->id, $l1->id]])
            ->assertOk();

        $this->assertSame(0, $l2->fresh()->sort_order);
        $this->assertSame(1, $l1->fresh()->sort_order);
    }

    public function test_admin_lesson_reorder_rejects_a_lesson_from_another_module(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $moduleA = CourseModule::create(['course_id' => $course->id, 'title' => 'A', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        $moduleB = CourseModule::create(['course_id' => $course->id, 'title' => 'B', 'sort_order' => 1, 'status' => 'active', 'is_active' => true]);
        $lessonA = Lesson::create(['module_id' => $moduleA->id, 'title' => 'LA', 'sort_order' => 0, 'status' => 'active']);
        $lessonB = Lesson::create(['module_id' => $moduleB->id, 'title' => 'LB', 'sort_order' => 0, 'status' => 'active']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/modules/{$moduleA->id}/lessons/reorder", ['ids' => [$lessonB->id, $lessonA->id]])
            ->assertStatus(422);
    }

    // ── Instructor module/lesson reorder — authorization ────────────────────

    public function test_instructor_owner_can_reorder_their_own_modules(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $m1 = CourseModule::create(['course_id' => $course->id, 'title' => 'A', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        $m2 = CourseModule::create(['course_id' => $course->id, 'title' => 'B', 'sort_order' => 1, 'status' => 'active', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules/reorder", ['ids' => [$m2->id, $m1->id]])
            ->assertOk();

        $this->assertSame(0, $m2->fresh()->sort_order);
    }

    public function test_another_instructor_cannot_reorder_modules_they_do_not_own(): void
    {
        [, $ownerProfile] = $this->instructor();
        [$otherUser] = $this->instructor();
        $course = $this->course($ownerProfile);
        $m1 = CourseModule::create(['course_id' => $course->id, 'title' => 'A', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);

        $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules/reorder", ['ids' => [$m1->id]])
            ->assertStatus(403);
    }

    public function test_instructor_without_profile_is_denied_reorder(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $m1 = CourseModule::create(['course_id' => $course->id, 'title' => 'A', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);

        $userWithoutProfile = User::factory()->create(['role' => 'instructor', 'is_active' => true]);

        $this->actingAs($userWithoutProfile, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules/reorder", ['ids' => [$m1->id]])
            ->assertStatus(403);
    }

    public function test_instructor_can_reorder_lessons_within_their_own_module(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        $l1 = Lesson::create(['module_id' => $module->id, 'title' => 'L1', 'sort_order' => 0, 'status' => 'active']);
        $l2 = Lesson::create(['module_id' => $module->id, 'title' => 'L2', 'sort_order' => 1, 'status' => 'active']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules/{$module->id}/lessons/reorder", ['ids' => [$l2->id, $l1->id]])
            ->assertOk();

        $this->assertSame(0, $l2->fresh()->sort_order);
    }

    public function test_instructor_cannot_reorder_lessons_of_a_module_belonging_to_another_course(): void
    {
        [$user, $instructor] = $this->instructor();
        [, $otherInstructor] = $this->instructor();
        $ownCourse = $this->course($instructor);
        $otherCourse = $this->course($otherInstructor);
        $foreignModule = CourseModule::create(['course_id' => $otherCourse->id, 'title' => 'Foreign', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $foreignModule->id, 'title' => 'L', 'sort_order' => 0, 'status' => 'active']);

        // Forged: instructor's own course ID paired with a module belonging to a different course.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/courses/{$ownCourse->id}/modules/{$foreignModule->id}/lessons/reorder", ['ids' => [$lesson->id]])
            ->assertStatus(404);
    }
}
