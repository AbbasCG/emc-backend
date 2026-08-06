<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\MaterialAuditLog;
use App\Models\Registration;
use App\Models\User;
use App\Support\CourseContentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningContentAnalyticsTest extends TestCase
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

    private function classGroup(Course $course, Instructor $instructor, array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'Class', 'capacity' => 20, 'status' => 'active',
        ], $overrides));
    }

    private function assignToClass(ClassGroup $group, User $student, string $status = 'assigned'): void
    {
        ClassGroupStudent::create(['class_group_id' => $group->id, 'user_id' => $student->id, 'status' => $status, 'assigned_at' => now()]);
    }

    private function enroll(User $student, Course $course): void
    {
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email, 'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_owner_instructor_can_access_analytics(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/instructor/classes/{$group->id}/curriculum/analytics")
            ->assertOk()
            ->assertJsonStructure(['data' => ['summary', 'modules', 'lessons', 'materials']]);
    }

    public function test_another_instructor_is_denied(): void
    {
        [, $ownerProfile] = $this->instructor();
        [$otherUser] = $this->instructor();
        $course = $this->course($ownerProfile);
        $group = $this->classGroup($course, $ownerProfile);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/instructor/classes/{$group->id}/curriculum/analytics")
            ->assertStatus(403);
    }

    public function test_instructor_without_profile_is_denied(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $noProfile = User::factory()->create(['role' => 'instructor', 'is_active' => true]);

        $this->actingAs($noProfile, 'sanctum')
            ->getJson("/api/instructor/classes/{$group->id}/curriculum/analytics")
            ->assertStatus(403);
    }

    public function test_forged_class_id_returns_404(): void
    {
        [$user] = $this->instructor();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/instructor/classes/999999/curriculum/analytics')
            ->assertStatus(404);
    }

    // ── Correctness ──────────────────────────────────────────────────────

    public function test_zero_eligible_lessons_handled_without_division_error(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $student = $this->student();
        $this->assignToClass($group, $student);

        $data = app(\App\Services\LearningContentAnalyticsService::class)->forClass($group->fresh());

        $this->assertSame(0, $data['summary']['lessons_count']);
        $this->assertSame(0.0, $data['summary']['average_course_progress_percentage']);
        $this->assertSame(1, $data['summary']['not_started_students']);
        $this->assertSame(0, $data['summary']['completed_students']);
    }

    public function test_draft_module_and_lesson_excluded_from_denominator(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $student = $this->student();
        $this->assignToClass($group, $student);

        $visibleModule = CourseModule::create(['course_id' => $course->id, 'title' => 'V', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $visibleLesson = Lesson::create(['module_id' => $visibleModule->id, 'title' => 'VL', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);

        $draftModule = CourseModule::create(['course_id' => $course->id, 'title' => 'D', 'sort_order' => 1, 'status' => CourseContentStatus::DRAFT, 'is_active' => true]);
        Lesson::create(['module_id' => $draftModule->id, 'title' => 'DL', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);

        $draftLessonUnderVisible = Lesson::create(['module_id' => $visibleModule->id, 'title' => 'DVL', 'sort_order' => 1, 'status' => CourseContentStatus::DRAFT]);

        LessonCompletion::create(['user_id' => $student->id, 'lesson_id' => $visibleLesson->id, 'completed_at' => now()]);

        $data = app(\App\Services\LearningContentAnalyticsService::class)->forClass($group->fresh());

        $this->assertSame(1, $data['summary']['lessons_count']); // only $visibleLesson
        $this->assertSame(100.0, $data['summary']['average_course_progress_percentage']);
        $this->assertSame(1, $data['summary']['completed_students']);
        $this->assertCount(1, $data['modules']); // draft module excluded entirely
    }

    public function test_not_started_in_progress_and_completed_counts_correct(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        $l1 = Lesson::create(['module_id' => $module->id, 'title' => 'L1', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE]);
        $l2 = Lesson::create(['module_id' => $module->id, 'title' => 'L2', 'sort_order' => 1, 'status' => CourseContentStatus::ACTIVE]);

        $notStarted = $this->student();
        $inProgress = $this->student();
        $completed = $this->student();
        foreach ([$notStarted, $inProgress, $completed] as $s) {
            $this->assignToClass($group, $s);
        }
        LessonCompletion::create(['user_id' => $inProgress->id, 'lesson_id' => $l1->id, 'completed_at' => now()]);
        LessonCompletion::create(['user_id' => $completed->id, 'lesson_id' => $l1->id, 'completed_at' => now()]);
        LessonCompletion::create(['user_id' => $completed->id, 'lesson_id' => $l2->id, 'completed_at' => now()]);

        $data = app(\App\Services\LearningContentAnalyticsService::class)->forClass($group->fresh());

        $this->assertSame(1, $data['summary']['not_started_students']);
        $this->assertSame(1, $data['summary']['in_progress_students']);
        $this->assertSame(1, $data['summary']['completed_students']);
    }

    public function test_removed_class_member_is_excluded_from_eligible_students(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $active = $this->student();
        $removed = $this->student();
        $this->assignToClass($group, $active);
        $this->assignToClass($group, $removed, 'removed');

        $data = app(\App\Services\LearningContentAnalyticsService::class)->forClass($group->fresh());

        $this->assertSame(1, $data['summary']['eligible_students']);
    }

    public function test_student_from_another_class_is_excluded(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);
        $studentA = $this->student();
        $studentB = $this->student();
        $this->assignToClass($groupA, $studentA);
        $this->assignToClass($groupB, $studentB);

        $data = app(\App\Services\LearningContentAnalyticsService::class)->forClass($groupA->fresh());

        $this->assertSame(1, $data['summary']['eligible_students']);
    }

    public function test_class_scoped_material_interactions_only_count_correct_class(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);
        $studentA = $this->student();
        $studentB = $this->student();
        $this->assignToClass($groupA, $studentA);
        $this->assignToClass($groupB, $studentB);

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'class_group_id' => $groupA->id,
            'title' => 'Class A material', 'type' => 'pdf', 'is_visible' => true,
        ]);
        MaterialAuditLog::record($material, 'downloaded', $studentA->id);
        MaterialAuditLog::record($material, 'downloaded', $studentB->id); // shouldn't count for group A view

        $dataA = app(\App\Services\LearningContentAnalyticsService::class)->forClass($groupA->fresh());
        $materialRow = collect($dataA['materials'])->firstWhere('id', $material->id);

        $this->assertSame(1, $materialRow['downloads_count']);
    }

    public function test_query_count_is_bounded_regardless_of_student_and_lesson_count(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);
        $module = CourseModule::create(['course_id' => $course->id, 'title' => 'M', 'sort_order' => 0, 'status' => CourseContentStatus::ACTIVE, 'is_active' => true]);
        for ($i = 0; $i < 5; $i++) {
            Lesson::create(['module_id' => $module->id, 'title' => "L{$i}", 'sort_order' => $i, 'status' => CourseContentStatus::ACTIVE]);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->assignToClass($group, $this->student());
        }

        DB::enableQueryLog();
        app(\App\Services\LearningContentAnalyticsService::class)->forClass($group->fresh());
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bounded — not proportional to students(10) x lessons(5) = 50 queries.
        $this->assertLessThan(15, $queryCount, "Expected a bounded query count, got {$queryCount}");
    }
}
