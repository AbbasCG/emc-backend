<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Support\CourseContentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LearningContentAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private function course(): Course
    {
        $user = \App\Models\User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $instructor = Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);
        return Course::factory()->create(['title' => 'English', 'status' => 'published', 'instructor_id' => $instructor->id]);
    }

    public function test_clean_database_exits_successfully(): void
    {
        $this->artisan('learning-content:audit')->assertExitCode(0);
    }

    public function test_dry_run_detects_duplicate_module_sort_orders_without_changing_the_database(): void
    {
        $course = $this->course();
        $m1 = CourseModule::create(['course_id' => $course->id, 'title' => 'A', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $m2 = CourseModule::create(['course_id' => $course->id, 'title' => 'B', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);

        // A repairable finding is still an unresolved finding in dry-run mode — non-zero exit,
        // and critically, the database must be completely untouched.
        $this->artisan('learning-content:audit')->assertExitCode(1);

        $this->assertSame(0, $m1->fresh()->sort_order);
        $this->assertSame(0, $m2->fresh()->sort_order);
    }

    public function test_repair_normalizes_duplicate_module_sort_orders(): void
    {
        $course = $this->course();
        $m1 = CourseModule::create(['course_id' => $course->id, 'title' => 'A', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $m2 = CourseModule::create(['course_id' => $course->id, 'title' => 'B', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);

        $this->artisan('learning-content:audit --repair')->assertExitCode(0);

        $orders = collect([$m1->fresh()->sort_order, $m2->fresh()->sort_order])->sort()->values();
        $this->assertSame([0, 1], $orders->all());
    }

    public function test_repair_normalizes_duplicate_lesson_sort_orders(): void
    {
        $course = $this->course();
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $l1 = Lesson::create(['module_id' => $module->id, 'title' => 'L1', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);
        $l2 = Lesson::create(['module_id' => $module->id, 'title' => 'L2', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);

        $this->artisan('learning-content:audit --repair')->assertExitCode(0);

        $orders = collect([$l1->fresh()->sort_order, $l2->fresh()->sort_order])->sort()->values();
        $this->assertSame([0, 1], $orders->all());
    }

    public function test_repair_derives_material_module_from_its_lesson(): void
    {
        $course = $this->course();
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'L', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);
        $material = CourseMaterial::create([
            'course_id' => $course->id, 'lesson_id' => $lesson->id, 'module_id' => null,
            'title' => 'Slides', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true,
        ]);

        $this->artisan('learning-content:audit --repair')->assertExitCode(0);

        $this->assertSame($module->id, $material->fresh()->module_id);
    }

    public function test_unrepairable_orphan_lesson_remains_reported_and_exits_nonzero(): void
    {
        $course = $this->course();
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'L', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);
        $module->delete(); // soft delete — lesson now points to a deleted module

        $this->artisan('learning-content:audit --repair')->assertExitCode(1);
        $this->artisan('learning-content:audit')->assertExitCode(1);

        // Lesson must not be deleted or altered.
        $this->assertNotNull($lesson->fresh());
    }

    public function test_command_never_deletes_content(): void
    {
        $course = $this->course();
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'L', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);
        $material = CourseMaterial::create([
            'course_id' => $course->id, 'lesson_id' => $lesson->id, 'module_id' => $module->id,
            'title' => 'Slides', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true,
        ]);

        $this->artisan('learning-content:audit --repair')->assertExitCode(0);

        $this->assertNotNull(CourseModule::find($module->id));
        $this->assertNotNull(Lesson::find($lesson->id));
        $this->assertNotNull(CourseMaterial::find($material->id));
    }

    public function test_missing_physical_file_is_detected(): void
    {
        Storage::fake('private');
        $course = $this->course();
        CourseMaterial::create([
            'course_id' => $course->id, 'title' => 'Missing file', 'type' => 'pdf',
            'file_path' => 'materials/does-not-exist.pdf', 'disk' => 'private', 'is_visible' => true,
        ]);

        $this->artisan('learning-content:audit')->assertExitCode(1);
    }

    public function test_repair_is_idempotent_second_dry_run_is_clean(): void
    {
        $course = $this->course();
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $lesson = Lesson::create(['module_id' => $module->id, 'title' => 'L', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);
        CourseMaterial::create([
            'course_id' => $course->id, 'lesson_id' => $lesson->id, 'module_id' => null,
            'title' => 'Slides', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true,
        ]);

        $this->artisan('learning-content:audit --repair')->assertExitCode(0);
        $this->artisan('learning-content:audit')->assertExitCode(0);
    }
}
