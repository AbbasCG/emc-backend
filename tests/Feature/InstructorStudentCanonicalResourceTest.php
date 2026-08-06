<?php

namespace Tests\Feature;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Registration;
use App\Models\User;
use App\Services\PlacementProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 2 completion pass: canonical InstructorStudentSummaryResource
 * (nested shape, additive to existing flat fields), the new
 * PlacementProgressService methods (studentsForInstructor/
 * studentsForClassGroup/studentSummary), deterministic duplicate-membership
 * handling, and backend-computed course dashboard counters.
 */
class InstructorStudentCanonicalResourceTest extends TestCase
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

    private function course(Instructor $instructor, array $overrides = []): Course
    {
        return Course::factory()->create(array_merge([
            'title' => 'English Course', 'status' => 'published',
            'instructor_id' => $instructor->id, 'requires_placement_test' => true,
        ], $overrides));
    }

    private function enroll(User $student, Course $course): void
    {
        Registration::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'full_name' => $student->name, 'email' => $student->email,
            'phone' => '0500000000', 'status' => 'registered',
        ]);
    }

    // ── Canonical Resource nested shape ──────────────────────────────────────

    public function test_course_students_endpoint_returns_nested_and_flat_shape(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/courses/{$course->id}/students");
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertNotNull($row);

        // Flat fields (backward compatibility) still present.
        $this->assertArrayHasKey('student_name', $row);
        $this->assertArrayHasKey('written_score', $row);
        $this->assertArrayHasKey('class_assignment', $row);

        // New nested structure present alongside it.
        $this->assertSame($student->name, $row['student']['name']);
        $this->assertSame($student->email, $row['student']['email']);
        $this->assertSame($course->id, $row['course']['id']);
        $this->assertArrayHasKey('written', $row['placement']);
        $this->assertArrayHasKey('oral', $row['placement']);
        $this->assertArrayHasKey('class_assignment', $row);
        $this->assertArrayHasKey('progress', $row);
    }

    public function test_placement_tests_endpoint_returns_same_nested_shape(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);
        \App\Models\PlacementTest::create([
            'title' => 'T', 'language' => 'en', 'duration_minutes' => 30, 'total_questions' => 70, 'is_active' => true,
        ]);
        \App\Models\PlacementTestAttempt::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'placement_test_id' => \App\Models\PlacementTest::first()->id,
            'score' => 42, 'total_questions' => 70, 'percentage' => 60.0,
            'estimated_level' => 'B1', 'status' => 'completed', 'submitted_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/placement-tests');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('student_id', $student->id);
        $this->assertNotNull($row);
        $this->assertSame($student->name, $row['student']['name']);
        $this->assertSame(42, $row['placement']['written']['score']);
    }

    public function test_group_students_endpoint_uses_canonical_resource_without_losing_legacy_fields(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1', 'capacity' => 10, 'status' => 'active',
        ]);
        $student = $this->student();
        $this->enroll($student, $course);
        ClassGroupStudent::create(['class_group_id' => $group->id, 'user_id' => $student->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/instructor/classes/{$group->id}/students");
        $response->assertOk();

        $row = collect($response->json('data.students'))->firstWhere('student_id', $student->id);
        $this->assertNotNull($row);

        // Legacy flat keys this endpoint has always used (name/email, not
        // student_name/student_email like the other 4 endpoints) still work.
        $this->assertSame($student->name, $row['name']);
        $this->assertSame($student->email, $row['email']);
        // The legacy nested `placement` sub-object (written_score/written_total/
        // percentage) this endpoint already shipped is preserved, not overwritten.
        $this->assertArrayHasKey('written_score', $row['placement']);
        $this->assertArrayHasKey('written_total', $row['placement']);

        // New canonical nested structure is also present, correctly populated
        // via the name/email aliases.
        $this->assertSame($student->name, $row['student']['name']);
        $this->assertSame($student->email, $row['student']['email']);
        $this->assertArrayHasKey('written', $row['placement']);
        $this->assertArrayHasKey('oral', $row['placement']);
    }

    // ── Canonical service methods ────────────────────────────────────────────

    public function test_students_for_instructor_aggregates_across_courses(): void
    {
        [$user, $instructor] = $this->instructor();
        $courseA = $this->course($instructor, ['title' => 'A']);
        $courseB = $this->course($instructor, ['title' => 'B']);
        $studentA = $this->student();
        $studentB = $this->student();
        $this->enroll($studentA, $courseA);
        $this->enroll($studentB, $courseB);

        $rows = app(PlacementProgressService::class)->studentsForInstructor($instructor);

        $ids = collect($rows)->pluck('student_id');
        $this->assertTrue($ids->contains($studentA->id));
        $this->assertTrue($ids->contains($studentB->id));
    }

    public function test_students_for_class_group_returns_only_active_members(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1', 'capacity' => 10, 'status' => 'active',
        ]);
        $member = $this->student();
        $removed = $this->student();
        $this->enroll($member, $course);
        $this->enroll($removed, $course);
        ClassGroupStudent::create(['class_group_id' => $group->id, 'user_id' => $member->id, 'status' => 'assigned', 'assigned_at' => now()]);
        ClassGroupStudent::create(['class_group_id' => $group->id, 'user_id' => $removed->id, 'status' => 'removed', 'assigned_at' => now()]);

        $rows = app(PlacementProgressService::class)->studentsForClassGroup($group);
        $ids = collect($rows)->pluck('student_id');

        $this->assertTrue($ids->contains($member->id));
        $this->assertFalse($ids->contains($removed->id));
    }

    public function test_student_summary_returns_single_row_or_null(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $student = $this->student();
        $this->enroll($student, $course);

        $row = app(PlacementProgressService::class)->studentSummary($student->id, $course);
        $this->assertNotNull($row);
        $this->assertSame($student->id, $row['student_id']);

        $missing = app(PlacementProgressService::class)->studentSummary(999999, $course);
        $this->assertNull($missing);
    }

    // ── Duplicate membership determinism ─────────────────────────────────────

    public function test_duplicate_active_memberships_deterministically_pick_the_newest(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $groupOld = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'Old', 'capacity' => 10, 'status' => 'active',
        ]);
        $groupNew = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'New', 'capacity' => 10, 'status' => 'active',
        ]);
        $student = $this->student();
        $this->enroll($student, $course);
        // Structurally rare (two different class groups, both active) — the
        // resolver must pick the most recently assigned one, not an
        // arbitrary DB row.
        ClassGroupStudent::create([
            'class_group_id' => $groupOld->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now()->subDay(),
        ]);
        ClassGroupStudent::create([
            'class_group_id' => $groupNew->id, 'user_id' => $student->id,
            'status' => 'assigned', 'assigned_at' => now(),
        ]);

        $row = app(PlacementProgressService::class)->studentSummary($student->id, $course);

        $this->assertSame('New', $row['class_assignment']['class_name']);
    }

    // ── Course dashboard counters ─────────────────────────────────────────────

    public function test_course_listing_returns_backend_computed_class_counters(): void
    {
        [$user, $instructor] = $this->instructor();
        $course = $this->course($instructor);
        $group = ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'B1', 'name' => 'B1', 'capacity' => 10, 'status' => 'active',
        ]);
        ClassGroup::create([
            'course_id' => $course->id, 'instructor_id' => $instructor->id,
            'level_code' => 'A2', 'name' => 'A2', 'capacity' => 10, 'status' => 'completed',
        ]);
        $student = $this->student();
        $this->enroll($student, $course);
        ClassGroupStudent::create(['class_group_id' => $group->id, 'user_id' => $student->id, 'status' => 'assigned', 'assigned_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/instructor/courses');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $course->id);
        $this->assertSame(1, $row['students_count']);
        $this->assertSame(1, $row['assigned_students_count']);
        $this->assertSame(2, $row['classes_count']);
        $this->assertSame(1, $row['active_classes_count']);
        $this->assertSame(1, $row['completed_classes_count']);
    }
}
