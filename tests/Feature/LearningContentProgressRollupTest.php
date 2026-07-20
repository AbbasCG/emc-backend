<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Registration;
use App\Models\User;
use App\Services\StudentModuleProgressService;
use App\Support\CourseContentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Block 6: lesson → module → course progress roll-up.
 *
 * Course-level progress is deliberately NOT persisted on StudentProgress
 * (no curriculum-percentage column exists, and adding a write path for a
 * value this cheap to compute was judged unnecessary risk) — it is
 * computed on demand via StudentModuleProgressService::computeForCourse()
 * and exposed through the lesson/modules endpoint responses.
 */
class LearningContentProgressRollupTest extends TestCase
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

    private function module(Course $course, array $overrides = []): CourseModule
    {
        return CourseModule::create(array_merge([
            'course_id' => $course->id, 'title' => 'M', 'sort_order' => 0,
            'status' => CourseContentStatus::ACTIVE, 'is_active' => true,
        ], $overrides));
    }

    private function lesson(CourseModule $module, array $overrides = []): Lesson
    {
        return Lesson::create(array_merge([
            'module_id' => $module->id, 'title' => 'L', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE,
        ], $overrides));
    }

    // ── Completion endpoint behavior ────────────────────────────────────────

    public function test_first_completion_succeeds_and_is_idempotent(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = $this->module($course);
        $lesson = $this->lesson($module);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/lessons/{$lesson->id}/complete")
            ->assertOk();

        $this->assertSame(1, LessonCompletion::where('user_id', $student->id)->where('lesson_id', $lesson->id)->count());

        // Repeated request must not create a duplicate row.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/lessons/{$lesson->id}/complete")
            ->assertOk();

        $this->assertSame(1, LessonCompletion::where('user_id', $student->id)->where('lesson_id', $lesson->id)->count());
    }

    public function test_unenrolled_student_cannot_complete_a_lesson(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $outsider = $this->student();
        $module = $this->module($course);
        $lesson = $this->lesson($module);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/student/lessons/{$lesson->id}/complete")
            ->assertStatus(403);

        $this->assertSame(0, LessonCompletion::count());
    }

    public function test_draft_lesson_cannot_be_completed_by_direct_id(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = $this->module($course);
        $draftLesson = $this->lesson($module, ['status' => CourseContentStatus::DRAFT]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/lessons/{$draftLesson->id}/complete")
            ->assertStatus(404);

        $this->assertSame(0, LessonCompletion::count());
    }

    public function test_lesson_under_an_inactive_module_cannot_be_completed(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $inactiveModule = $this->module($course, ['status' => CourseContentStatus::INACTIVE]);
        $lesson = $this->lesson($inactiveModule);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/student/lessons/{$lesson->id}/complete")
            ->assertStatus(404);
    }

    public function test_another_students_completion_is_independent(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $studentA = $this->student();
        $studentB = $this->student();
        $this->enroll($studentA, $course);
        $this->enroll($studentB, $course);
        $module = $this->module($course);
        $lesson = $this->lesson($module);

        $this->actingAs($studentA, 'sanctum')->postJson("/api/student/lessons/{$lesson->id}/complete")->assertOk();

        $this->assertTrue(LessonCompletion::where('user_id', $studentA->id)->where('lesson_id', $lesson->id)->exists());
        $this->assertFalse(LessonCompletion::where('user_id', $studentB->id)->where('lesson_id', $lesson->id)->exists());
    }

    // ── Module progress ─────────────────────────────────────────────────────

    public function test_module_progress_excludes_draft_and_inactive_lessons_from_denominator(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = $this->module($course);
        $active = $this->lesson($module, ['title' => 'Active']);
        $this->lesson($module, ['title' => 'Draft', 'status' => CourseContentStatus::DRAFT]);
        $this->lesson($module, ['title' => 'Inactive', 'status' => CourseContentStatus::INACTIVE]);

        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $active->id, 'completed_at' => now()]);

        $result = app(StudentModuleProgressService::class)->computeForModule($module->fresh(['lessons']), $student);

        $this->assertSame(1, $result['lessons_count']);
        $this->assertSame(1, $result['completed_lessons_count']);
        $this->assertSame(100, $result['progress_percentage']);
    }

    public function test_module_progress_handles_zero_eligible_lessons_safely(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $module = $this->module($course);
        $this->lesson($module, ['status' => CourseContentStatus::DRAFT]);

        $result = app(StudentModuleProgressService::class)->computeForModule($module->fresh(['lessons']), $student);

        $this->assertSame(0, $result['lessons_count']);
        $this->assertSame(0, $result['progress_percentage']);
        $this->assertFalse($result['is_completed']);
    }

    public function test_module_progress_unaffected_by_lesson_reorder(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = $this->module($course);
        $l1 = $this->lesson($module, ['title' => 'L1', 'sort_order' => 0]);
        $l2 = $this->lesson($module, ['title' => 'L2', 'sort_order' => 1]);
        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $l1->id, 'completed_at' => now()]);

        $before = app(StudentModuleProgressService::class)->computeForModule($module->fresh(['lessons']), $student);

        // Reorder: L2 now comes first.
        $l1->update(['sort_order' => 1]);
        $l2->update(['sort_order' => 0]);

        $after = app(StudentModuleProgressService::class)->computeForModule($module->fresh(['lessons']), $student);

        $this->assertSame($before['completed_lessons_count'], $after['completed_lessons_count']);
        $this->assertSame($before['progress_percentage'], $after['progress_percentage']);
    }

    // ── Course-level roll-up ────────────────────────────────────────────────

    public function test_course_progress_aggregates_across_multiple_active_modules(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $moduleA = $this->module($course, ['title' => 'A']);
        $lA1 = $this->lesson($moduleA, ['title' => 'A1']);
        $this->lesson($moduleA, ['title' => 'A2']);

        $moduleB = $this->module($course, ['title' => 'B']);
        $lB1 = $this->lesson($moduleB, ['title' => 'B1']);
        $lB2 = $this->lesson($moduleB, ['title' => 'B2']);

        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $lA1->id, 'completed_at' => now()]);
        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $lB1->id, 'completed_at' => now()]);
        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $lB2->id, 'completed_at' => now()]);

        $result = app(StudentModuleProgressService::class)->computeForCourse($course->fresh(), $student);

        // 4 total active lessons, 3 completed = 75%
        $this->assertSame(4, $result['lessons_count']);
        $this->assertSame(3, $result['completed_lessons_count']);
        $this->assertSame(75, $result['progress_percentage']);
        $this->assertFalse($result['is_completed']);
    }

    public function test_course_progress_excludes_a_fully_inactive_module_entirely(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $visibleModule = $this->module($course, ['title' => 'Visible']);
        $lesson = $this->lesson($visibleModule);
        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'completed_at' => now()]);

        $hiddenModule = $this->module($course, ['title' => 'Hidden', 'status' => CourseContentStatus::DRAFT]);
        $this->lesson($hiddenModule);
        $this->lesson($hiddenModule);

        $result = app(StudentModuleProgressService::class)->computeForCourse($course->fresh(), $student);

        // Only the 1 lesson in the visible module counts — the 2 lessons in the
        // draft module must not appear in the denominator at all.
        $this->assertSame(1, $result['lessons_count']);
        $this->assertSame(100, $result['progress_percentage']);
        $this->assertTrue($result['is_completed']);
    }

    public function test_completing_a_lesson_in_another_course_does_not_affect_this_courses_progress(): void
    {
        [, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $courseB = $this->course($instructorB);
        $student = $this->student();
        $this->enroll($student, $courseA);
        $this->enroll($student, $courseB);

        $moduleA = $this->module($courseA);
        $this->lesson($moduleA);

        $moduleB = $this->module($courseB);
        $lessonB = $this->lesson($moduleB);
        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $lessonB->id, 'completed_at' => now()]);

        $resultA = app(StudentModuleProgressService::class)->computeForCourse($courseA->fresh(), $student);

        $this->assertSame(0, $resultA['completed_lessons_count']);
        $this->assertSame(0, $resultA['progress_percentage']);
    }

    // ── Lesson endpoint response shape ──────────────────────────────────────

    public function test_lesson_endpoint_returns_materials_module_progress_and_course_progress(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = $this->module($course);
        $lesson = $this->lesson($module);
        CourseMaterial::create([
            'course_id' => $course->id, 'lesson_id' => $lesson->id,
            'title' => 'Slides', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true,
        ]);

        $response = $this->actingAs($student, 'sanctum')->getJson("/api/student/lessons/{$lesson->id}");

        $response->assertOk();
        $response->assertJsonPath('data.is_completed', false);
        $response->assertJsonCount(1, 'data.materials');
        $response->assertJsonStructure(['module_progress' => ['lessons_count', 'progress_percentage'], 'course_progress' => ['lessons_count', 'progress_percentage']]);
    }

    public function test_lesson_endpoint_never_leaks_materials_from_another_lesson(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = $this->module($course);
        $lessonA = $this->lesson($module, ['title' => 'A']);
        $lessonB = $this->lesson($module, ['title' => 'B']);
        CourseMaterial::create([
            'course_id' => $course->id, 'lesson_id' => $lessonB->id,
            'title' => 'B material', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true,
        ]);

        $response = $this->actingAs($student, 'sanctum')->getJson("/api/student/lessons/{$lessonA->id}");

        $response->assertOk();
        $response->assertJsonCount(0, 'data.materials');
    }
}
