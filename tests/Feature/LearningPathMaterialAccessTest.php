<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Root-cause fix: a student with legitimate course access via a Learning
 * Path enrollment (rather than a direct Registration row) could open
 * /student/courses/{id}/learn — which had its own inline enrollment check
 * that recognized learning paths — but was then denied by
 * CourseMaterialPolicy/LearningContentEligibilityService when trying to
 * actually list/preview/download a material, because those went through
 * LmsEnrollmentService::isEnrolledInCourse(), which only knew about direct
 * Registration rows. LmsEnrollmentService is now the single canonical
 * source of truth for both direct and learning-path access.
 */
class LearningPathMaterialAccessTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): Instructor
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);

        return Instructor::create(['user_id' => $user->id, 'name' => $user->name, 'title' => 'Instructor']);
    }

    private function course(Instructor $instructor): Course
    {
        return Course::factory()->create([
            'title' => 'English Course', 'status' => 'published', 'instructor_id' => $instructor->id,
        ]);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    /** Enrolls $student in $course exclusively through a Learning Path — no Registration row at all. */
    private function enrollViaLearningPath(User $student, Course $course): void
    {
        $path = LearningPath::create([
            'title' => 'Path ' . Str::random(6),
            'slug' => 'path-' . Str::random(8),
            'status' => 'published',
        ]);
        $path->courses()->attach($course->id);

        LearningPathEnrollment::create([
            'learning_path_id' => $path->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
    }

    private function enrollDirectly(User $student, Course $course): void
    {
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    public function test_learning_path_enrolled_student_can_view_the_learn_page(): void
    {
        $instructor = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enrollViaLearningPath($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/learn/{$course->id}")
            ->assertOk();
    }

    public function test_learning_path_enrolled_student_can_list_course_materials(): void
    {
        $instructor = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enrollViaLearningPath($student, $course);

        CourseMaterial::create([
            'course_id' => $course->id, 'title' => 'Recording', 'type' => 'link',
            'external_url' => 'https://zoom.us/rec/abc', 'is_visible' => true,
        ]);

        $res = $this->actingAs($student, 'sanctum')->getJson('/api/student/materials');
        $res->assertOk();
        $this->assertNotEmpty($res->json('data'));
    }

    public function test_learning_path_enrolled_student_can_preview_a_material(): void
    {
        $instructor = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enrollViaLearningPath($student, $course);

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'title' => 'Slides', 'type' => 'pdf', 'is_visible' => true,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/materials/{$material->id}")
            ->assertOk();
    }

    public function test_learning_path_enrolled_student_can_download_a_material(): void
    {
        $instructor = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enrollViaLearningPath($student, $course);

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'title' => 'Handout', 'type' => 'pdf', 'is_visible' => true,
            'file_path' => 'materials/fake.pdf', 'disk' => 'private',
        ]);

        // A missing physical file still proves authorization passed (no 403);
        // the controller fails later, past the policy gate, which is what
        // this regression is actually about.
        $this->actingAs($student, 'sanctum')
            ->get("/api/materials/{$material->id}/download")
            ->assertStatus(404);
    }

    public function test_unrelated_student_still_cannot_access_course_via_learning_path_confusion(): void
    {
        $instructor = $this->instructor();
        $course = $this->course($instructor);
        $outsider = $this->student();
        // No registration, no learning-path enrollment at all.

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/student/learn/{$course->id}")
            ->assertStatus(403);

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'title' => 'Private', 'type' => 'pdf', 'is_visible' => true,
        ]);
        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/student/materials/{$material->id}")
            ->assertStatus(403);
    }

    public function test_directly_registered_student_still_works_unchanged(): void
    {
        $instructor = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enrollDirectly($student, $course);

        $material = CourseMaterial::create([
            'course_id' => $course->id, 'title' => 'Doc', 'type' => 'pdf', 'is_visible' => true,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/learn/{$course->id}")
            ->assertOk();
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/materials/{$material->id}")
            ->assertOk();
    }
}
