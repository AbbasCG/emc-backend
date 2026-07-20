<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentCourseLifecycleStatusTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function enroll(User $student, Course $course): Registration
    {
        return Registration::create([
            'user_id'   => $student->id,
            'course_id' => $course->id,
            'full_name' => $student->name,
            'email'     => $student->email,
            'phone'     => '0500000000',
            'status'    => 'registered',
        ]);
    }

    public function test_ended_course_is_reported_as_completed_lifecycle_on_student_courses_endpoint(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 25, 10, 0, 0, 'UTC'));

        $student = $this->student();
        $course = Course::factory()->create([
            'title' => 'Ended Course', 'status' => 'published',
            'start_date' => '2026-07-01', 'start_time' => '09:00',
            'end_date'   => '2026-07-20', 'end_time'   => '18:00',
        ]);
        $this->enroll($student, $course);

        $response = $this->actingAs($student, 'sanctum')->getJson('/api/student/courses');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('course_id', $course->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['is_ended']);
        $this->assertSame('ended', $row['computed_status']);
        $this->assertSame('completed', $row['lifecycle_status']);
        // Explicit offset, never an ambiguous bare datetime.
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $row['ends_at']);
        $this->assertSame('Europe/Amsterdam', $row['timezone']);

        Carbon::setTestNow();
    }

    public function test_active_course_is_not_reported_as_ended(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 10, 0, 0, 'UTC'));

        $student = $this->student();
        $course = Course::factory()->create([
            'title' => 'Active Course', 'status' => 'published',
            'start_date' => '2026-07-01', 'start_time' => '09:00',
            'end_date'   => '2026-07-20', 'end_time'   => '18:00',
        ]);
        $this->enroll($student, $course);

        $response = $this->actingAs($student, 'sanctum')->getJson('/api/student/courses');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('course_id', $course->id);
        $this->assertFalse($row['is_ended']);
        $this->assertSame('active', $row['lifecycle_status']);

        Carbon::setTestNow();
    }

    /** The DB `status` column must never be overwritten just because the course ended. */
    public function test_ended_course_does_not_mutate_stored_status_column(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 25, 10, 0, 0, 'UTC'));

        $student = $this->student();
        $course = Course::factory()->create([
            'status' => 'published',
            'start_date' => '2026-07-01', 'end_date' => '2026-07-20',
        ]);
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')->getJson('/api/student/courses')->assertOk();

        $this->assertSame('published', $course->fresh()->status);

        Carbon::setTestNow();
    }

    /** Existing progress data must remain untouched by an ended course's status computation. */
    public function test_existing_progress_is_unchanged_when_course_becomes_completed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 25, 10, 0, 0, 'UTC'));

        $student = $this->student();
        $course = Course::factory()->create([
            'status' => 'published',
            'start_date' => '2026-07-01', 'end_date' => '2026-07-20',
        ]);
        $this->enroll($student, $course);
        StudentProgress::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'progress_percentage' => 42, 'completed_sessions' => 3, 'total_sessions' => 10,
        ]);

        $response = $this->actingAs($student, 'sanctum')->getJson('/api/student/courses');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('course_id', $course->id);
        $this->assertSame(42, (int) $row['progress']['percentage']);
        $this->assertSame(3, $row['progress']['completed_sessions']);

        Carbon::setTestNow();
    }
}
