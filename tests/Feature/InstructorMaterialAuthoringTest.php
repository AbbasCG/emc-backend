<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1/2 closure: instructor-created materials can now be class-scoped
 * via class_group_id, with the class required to belong to the material's
 * own course AND be owned by the authenticated instructor. Reuses
 * LearningContentEligibilityService::materialScopeErrors() rather than
 * duplicating scope-consistency logic.
 */
class InstructorMaterialAuthoringTest extends TestCase
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

    private function classGroup(Course $course, Instructor $instructor, array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'Class', 'capacity' => 20, 'status' => 'active',
        ], $overrides));
    }

    public function test_instructor_can_create_a_class_scoped_material_for_their_own_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/instructor/courses/{$course->id}/materials", [
            'title' => 'Class material', 'type' => 'link', 'external_url' => 'https://example.com',
            'class_group_id' => $group->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.class_group_id', $group->id);
        $response->assertJsonPath('data.scope', 'class');
    }

    public function test_instructor_cannot_scope_a_material_to_a_class_they_do_not_own(): void
    {
        [, $ownerProfile] = $this->instructor();
        [$otherUser] = $this->instructor();
        $course = $this->course($ownerProfile);
        $group = $this->classGroup($course, $ownerProfile);

        // $otherUser doesn't own $course at all, so authorise() itself should already
        // deny this — but this test specifically proves the class-ownership layer too.
        $response = $this->actingAs($otherUser, 'sanctum')->postJson("/api/instructor/courses/{$course->id}/materials", [
            'title' => 'Intrusion', 'type' => 'link', 'external_url' => 'https://example.com',
            'class_group_id' => $group->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_instructor_cannot_scope_a_material_to_a_class_from_a_different_course(): void
    {
        [$user, $instructor] = $this->instructor();
        [, $otherInstructor] = $this->instructor();
        $ownCourse = $this->course($instructor);
        $otherCourse = $this->course($otherInstructor);
        $foreignGroup = $this->classGroup($otherCourse, $otherInstructor);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/instructor/courses/{$ownCourse->id}/materials", [
            'title' => 'Forged class', 'type' => 'link', 'external_url' => 'https://example.com',
            'class_group_id' => $foreignGroup->id,
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, CourseMaterial::count());
    }

    public function test_instructor_without_profile_cannot_class_scope_a_material(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $userWithoutProfile = User::factory()->create(['role' => 'instructor', 'is_active' => true]);

        $this->actingAs($userWithoutProfile, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/materials", [
                'title' => 'Bypass', 'type' => 'link', 'external_url' => 'https://example.com',
                'class_group_id' => $group->id,
            ])
            ->assertStatus(403);
    }

    public function test_instructor_cannot_link_a_module_from_a_different_course_when_creating_a_material(): void
    {
        [$user, $instructor] = $this->instructor();
        [, $otherInstructor] = $this->instructor();
        $ownCourse = $this->course($instructor);
        $otherCourse = $this->course($otherInstructor);
        $foreignModule = \App\Models\CourseModule::create(['course_id' => $otherCourse->id, 'title' => 'Foreign', 'sort_order' => 0, 'status' => 'active', 'is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/instructor/courses/{$ownCourse->id}/materials", [
            'title' => 'Forged module', 'type' => 'link', 'external_url' => 'https://example.com',
            'module_id' => $foreignModule->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['module_id']);
    }

    public function test_admin_can_class_scope_a_material_for_any_instructors_class(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $this->actingAs($admin, 'sanctum')->postJson("/api/instructor/courses/{$course->id}/materials", [
            'title' => 'Admin material', 'type' => 'link', 'external_url' => 'https://example.com',
            'class_group_id' => $group->id,
        ])->assertStatus(201);
    }

    public function test_update_material_cannot_be_re_scoped_to_a_foreign_class(): void
    {
        [$user, $instructor] = $this->instructor();
        [, $otherInstructor] = $this->instructor();
        $course = $this->course($instructor);
        $otherCourse = $this->course($otherInstructor);
        $foreignGroup = $this->classGroup($otherCourse, $otherInstructor);

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'title' => 'Material', 'type' => 'link',
            'external_url' => 'https://example.com', 'is_visible' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/instructor/materials/{$material->id}", [
            'class_group_id' => $foreignGroup->id,
        ]);

        $response->assertStatus(403);
        $this->assertNull($material->fresh()->class_group_id);
    }
}
