<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\Instructor;
use App\Models\Lesson;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Models\User;
use App\Support\CourseContentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Block 5: student learning-content flow security — direct-URL lesson
 * access, draft/inactive visibility, cross-course/cross-class leakage,
 * and removed-membership handling.
 */
class StudentLearningContentAccessTest extends TestCase
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

    private function classGroup(Course $course, Instructor $instructor, array $overrides = []): ClassGroup
    {
        return ClassGroup::create(array_merge([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'Class', 'capacity' => 20, 'status' => 'active',
        ], $overrides));
    }

    private function assignToClass(ClassGroup $group, User $student, string $status = 'assigned'): void
    {
        ClassGroupStudent::create([
            'class_group_id' => $group->id, 'user_id' => $student->id,
            'status' => $status, 'assigned_at' => now(),
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

    // ── Direct-URL protection ───────────────────────────────────────────────

    public function test_unenrolled_student_cannot_access_lesson_by_direct_url(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $outsider = $this->student();
        $module = $this->module($course);
        $lesson = $this->lesson($module);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/student/lessons/{$lesson->id}")
            ->assertStatus(403);
    }

    public function test_draft_lesson_hidden_from_direct_url(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = $this->module($course);
        $draft = $this->lesson($module, ['status' => CourseContentStatus::DRAFT]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/lessons/{$draft->id}")
            ->assertStatus(404);
    }

    public function test_inactive_lesson_hidden_from_direct_url(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $module = $this->module($course);
        $inactive = $this->lesson($module, ['status' => CourseContentStatus::INACTIVE]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/lessons/{$inactive->id}")
            ->assertStatus(404);
    }

    public function test_lesson_under_draft_module_is_hidden_even_if_lesson_itself_is_active(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        $draftModule = $this->module($course, ['status' => CourseContentStatus::DRAFT]);
        $lesson = $this->lesson($draftModule);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/lessons/{$lesson->id}")
            ->assertStatus(404);
    }

    public function test_lesson_from_a_course_the_student_is_not_enrolled_in_is_denied(): void
    {
        [, $instructorA] = $this->instructor();
        [, $instructorB] = $this->instructor();
        $courseA = $this->course($instructorA);
        $courseB = $this->course($instructorB);
        $student = $this->student();
        $this->enroll($student, $courseA);
        // not enrolled in courseB

        $moduleB = $this->module($courseB);
        $lessonB = $this->lesson($moduleB);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/lessons/{$lessonB->id}")
            ->assertStatus(403);
    }

    // ── Class-scoped material leakage via lesson materials ─────────────────

    public function test_wrong_class_student_cannot_see_class_scoped_material_attached_to_a_shared_lesson(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);

        $studentA = $this->student();
        $this->enroll($studentA, $course);
        $this->assignToClass($groupA, $studentA);

        $module = $this->module($course);
        $lesson = $this->lesson($module);

        $sessionB = LmsSession::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id, 'class_group_id' => $groupB->id,
            'title' => 'B session', 'session_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'scheduled',
        ]);

        // Material attached to this shared lesson, but linked to a session in class B only.
        CourseMaterial::create([
            'course_id' => $course->id, 'lesson_id' => $lesson->id, 'session_id' => $sessionB->id,
            'title' => 'Class B material', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true,
        ]);

        $response = $this->actingAs($studentA, 'sanctum')->getJson("/api/student/lessons/{$lesson->id}");

        $response->assertOk();
        $response->assertJsonCount(0, 'data.materials');
    }

    public function test_removed_class_member_cannot_see_class_scoped_lesson_material(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $removedStudent = $this->student();
        $this->enroll($removedStudent, $course);
        $this->assignToClass($group, $removedStudent, 'removed');

        $module = $this->module($course);
        $lesson = $this->lesson($module);
        CourseMaterial::create([
            'course_id' => $course->id, 'lesson_id' => $lesson->id, 'class_group_id' => $group->id,
            'title' => 'Class material', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true,
        ]);

        $response = $this->actingAs($removedStudent, 'sanctum')->getJson("/api/student/lessons/{$lesson->id}");

        $response->assertOk();
        $response->assertJsonCount(0, 'data.materials');
    }

    public function test_correct_class_member_sees_their_own_class_scoped_lesson_material(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = $this->classGroup($course, $instructor);

        $member = $this->student();
        $this->enroll($member, $course);
        $this->assignToClass($group, $member);

        $module = $this->module($course);
        $lesson = $this->lesson($module);
        CourseMaterial::create([
            'course_id' => $course->id, 'lesson_id' => $lesson->id, 'class_group_id' => $group->id,
            'title' => 'Class material', 'type' => 'link', 'external_url' => 'https://example.com', 'is_visible' => true,
        ]);

        $response = $this->actingAs($member, 'sanctum')->getJson("/api/student/lessons/{$lesson->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data.materials');
    }

    // ── Download/preview parity ──────────────────────────────────────────────

    public function test_material_download_and_preview_use_the_same_eligibility_check(): void
    {
        [, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupA = $this->classGroup($course, $instructor, ['name' => 'A']);
        $groupB = $this->classGroup($course, $instructor, ['name' => 'B']);

        $studentA = $this->student();
        $this->enroll($studentA, $course);
        $this->assignToClass($groupA, $studentA);

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'class_group_id' => $groupB->id,
            'title' => 'Class B material', 'type' => 'pdf', 'is_visible' => true,
            'file_path' => 'materials/fake.pdf', 'disk' => 'private', 'mime_type' => 'application/pdf',
        ]);

        $downloadResponse = $this->actingAs($studentA, 'sanctum')->getJson("/api/materials/{$material->id}/download");
        $previewResponse = $this->actingAs($studentA, 'sanctum')->getJson("/api/materials/{$material->id}/preview");

        $downloadResponse->assertStatus(403);
        $previewResponse->assertStatus(403);
    }
}
