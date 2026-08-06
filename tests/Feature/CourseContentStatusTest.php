<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\Registration;
use App\Models\User;
use App\Support\CourseContentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 7 continuation: centralized CourseModule/Lesson status vocabulary.
 * Audit evidence: both models were already validated with the identical
 * `active/inactive/draft` set and gated by the identical visibility rule
 * before this pass — App\Support\CourseContentStatus centralizes that
 * existing, real behavior (not an invented draft/published/scheduled/
 * archived vocabulary, which does not match live code).
 */
class CourseContentStatusTest extends TestCase
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

    // ── Model-level visibility ──────────────────────────────────────────────

    public function test_module_with_draft_status_is_not_visible_to_students(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'status' => CourseContentStatus::DRAFT, 'is_active' => true]);

        $this->assertFalse($module->isVisibleToStudents());
    }

    public function test_module_with_inactive_status_is_not_visible_to_students(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'status' => CourseContentStatus::INACTIVE, 'is_active' => true]);

        $this->assertFalse($module->isVisibleToStudents());
    }

    public function test_module_with_active_status_is_visible_to_students(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);

        $this->assertTrue($module->isVisibleToStudents());
    }

    public function test_module_explicitly_deactivated_is_not_visible_even_with_active_status(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE, 'is_active' => false]);

        $this->assertFalse($module->isVisibleToStudents());
    }

    public function test_lesson_with_active_status_is_visible_lesson_with_draft_is_not(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $active = Lesson::create(['module_id' => $module->id, 'title' => 'L1', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE]);
        $draft  = Lesson::create(['module_id' => $module->id, 'title' => 'L2', 'sort_order' => 2, 'status' => CourseContentStatus::DRAFT]);

        $this->assertTrue($active->isVisibleToStudents());
        $this->assertFalse($draft->isVisibleToStudents());
    }

    // ── Student-facing endpoint (lesson) ────────────────────────────────────

    public function test_student_cannot_access_a_draft_lesson_directly_by_url(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'Draft lesson', 'sort_order' => 1, 'status' => CourseContentStatus::DRAFT]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/lessons/{$lesson->id}")
            ->assertStatus(404);
    }

    public function test_student_can_access_a_published_active_lesson(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'Active lesson', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/lessons/{$lesson->id}")
            ->assertOk();
    }

    public function test_unenrolled_student_cannot_access_lesson_regardless_of_status(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $outsider = $this->student();
        // not enrolled
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'Active lesson', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/student/lessons/{$lesson->id}")
            ->assertStatus(403);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_admin_module_create_rejects_invalid_status_value(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/modules', [
            'course_id' => $course->id,
            'title' => 'Module',
            'status' => 'published', // not in the real CourseModule/Lesson vocabulary
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_admin_lesson_create_rejects_invalid_status_value(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/lessons', [
            'module_id' => $module->id,
            'title' => 'Lesson',
            'status' => 'archived', // not in the real CourseModule/Lesson vocabulary
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_admin_module_create_accepts_a_real_status_value(): void
    {
        $admin = $this->admin();
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/modules', [
            'course_id' => $course->id,
            'title' => 'Module',
            'status' => CourseContentStatus::DRAFT,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', CourseContentStatus::DRAFT);
    }

    // ── Progress roll-up excludes draft lessons ─────────────────────────────

    public function test_module_progress_excludes_draft_lessons_from_the_denominator(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        Lesson::create(['module_id' => $module->id, 'title' => 'Active', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE]);
        Lesson::create(['module_id' => $module->id, 'title' => 'Draft', 'sort_order' => 2, 'status' => CourseContentStatus::DRAFT]);

        $service = app(\App\Services\StudentModuleProgressService::class);
        $result = $service->computeForModule($module, $student);

        $this->assertSame(1, $result['lessons_count']);
    }
}
