<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\PlatformNotification;
use App\Models\Registration;
use App\Models\User;
use App\Support\CourseContentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4: content lifecycle notifications, real transition detection
 * (not state-check), reusing the existing PlatformNotification/
 * NotificationService architecture — no parallel notification system.
 */
class LearningContentNotificationTest extends TestCase
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
        return Course::factory()->create(['title' => 'English', 'status' => 'published', 'instructor_id' => $instructor->id]);
    }

    private function enroll(User $student, Course $course): void
    {
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email, 'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    public function test_activating_a_module_notifies_eligible_students(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::DRAFT, 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/modules/{$module->id}", ['status' => CourseContentStatus::ACTIVE])
            ->assertOk();

        $this->assertDatabaseHas('platform_notifications', ['user_id' => $student->id, 'type' => 'module_activated']);
    }

    public function test_draft_module_save_does_not_notify(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::DRAFT, 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/modules/{$module->id}", ['title' => 'Still draft'])
            ->assertOk();

        $this->assertSame(0, PlatformNotification::count());
    }

    public function test_repeated_active_save_does_not_notify_again(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/modules/{$module->id}", ['status' => CourseContentStatus::ACTIVE])
            ->assertOk();

        $this->assertSame(0, PlatformNotification::where('type', 'module_activated')->count());
    }

    public function test_activating_a_lesson_notifies_eligible_students(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'L', 'sort_order' => 0, 'status' => CourseContentStatus::DRAFT]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/lessons/{$lesson->id}", ['status' => CourseContentStatus::ACTIVE])
            ->assertOk();

        $this->assertDatabaseHas('platform_notifications', ['user_id' => $student->id, 'type' => 'lesson_activated']);
    }

    public function test_meaningful_content_update_on_active_lesson_notifies(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'Original', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/instructor/lessons/{$lesson->id}", ['title' => 'Updated title'])
            ->assertOk();

        $this->assertDatabaseHas('platform_notifications', ['user_id' => $student->id, 'type' => 'lesson_content_updated']);
    }

    public function test_reorder_alone_does_not_notify(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $l1 = Lesson::create(['module_id' => $module->id, 'title' => 'L1', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);
        $l2 = Lesson::create(['module_id' => $module->id, 'title' => 'L2', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/instructor/courses/{$course->id}/modules/{$module->id}/lessons/reorder", ['ids' => [$l2->id, $l1->id]])
            ->assertOk();

        $this->assertSame(0, PlatformNotification::count());
    }

    public function test_visible_course_wide_material_notifies_all_enrolled_students(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $this->actingAs($user, 'sanctum')->postJson("/api/instructor/courses/{$course->id}/materials", [
            'title' => 'Slides', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true,
        ])->assertStatus(201);

        $this->assertDatabaseHas('platform_notifications', ['user_id' => $student->id, 'type' => 'material_visible']);
    }

    public function test_class_scoped_material_notifies_only_correct_class(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = ClassGroup::create(['course_id' => $course->id, 'instructor_id' => $instructor->id, 'level_code' => 'B1', 'name' => 'A', 'capacity' => 20, 'status' => 'active']);
        $groupB = ClassGroup::create(['course_id' => $course->id, 'instructor_id' => $instructor->id, 'level_code' => 'B1', 'name' => 'B', 'capacity' => 20, 'status' => 'active']);

        $studentA = $this->student();
        $studentB = $this->student();
        $this->enroll($studentA, $course);
        $this->enroll($studentB, $course);
        ClassGroupStudent::create(['class_group_id' => $groupA->id, 'user_id' => $studentA->id, 'status' => 'assigned', 'assigned_at' => now()]);
        ClassGroupStudent::create(['class_group_id' => $groupB->id, 'user_id' => $studentB->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $this->actingAs($user, 'sanctum')->postJson("/api/instructor/courses/{$course->id}/materials", [
            'title' => 'Class A material', 'type' => 'link', 'external_url' => 'https://example.com',
            'is_visible' => true, 'class_group_id' => $groupA->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('platform_notifications', ['user_id' => $studentA->id, 'type' => 'material_visible']);
        $this->assertDatabaseMissing('platform_notifications', ['user_id' => $studentB->id, 'type' => 'material_visible']);
    }

    public function test_removed_class_member_excluded_from_material_notification(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = ClassGroup::create(['course_id' => $course->id, 'instructor_id' => $instructor->id, 'level_code' => 'B1', 'name' => 'A', 'capacity' => 20, 'status' => 'active']);

        $removed = $this->student();
        $this->enroll($removed, $course);
        ClassGroupStudent::create(['class_group_id' => $group->id, 'user_id' => $removed->id, 'status' => 'removed', 'assigned_at' => now()]);

        $this->actingAs($user, 'sanctum')->postJson("/api/instructor/courses/{$course->id}/materials", [
            'title' => 'Class material', 'type' => 'link', 'external_url' => 'https://example.com',
            'is_visible' => true, 'class_group_id' => $group->id,
        ])->assertStatus(201);

        $this->assertDatabaseMissing('platform_notifications', ['user_id' => $removed->id, 'type' => 'material_visible']);
    }

    public function test_other_course_students_are_not_notified(): void
    {
        [$user, $instructor] = $this->instructor();
        [, $otherInstructor] = $this->instructor();
        $course = $this->course($instructor);
        $otherCourse = $this->course($otherInstructor);
        $outsider = $this->student();
        $this->enroll($outsider, $otherCourse);

        $this->actingAs($user, 'sanctum')->postJson("/api/instructor/courses/{$course->id}/materials", [
            'title' => 'Material', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true,
        ])->assertStatus(201);

        $this->assertDatabaseMissing('platform_notifications', ['user_id' => $outsider->id, 'type' => 'material_visible']);
    }

    public function test_rolled_back_transaction_creates_no_notification(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::DRAFT, 'is_active' => true]);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($module) {
                \App\Services\NotificationService::moduleActivated($module->course_id, 'Course', $module->title, $module->id);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, PlatformNotification::count());
    }
}
