<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentDashboardTest extends TestCase
{
    use RefreshDatabase;

    // ─── Dashboard ────────────────────────────────────────────────────────────

    public function test_student_can_access_dashboard(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/dashboard')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_unauthenticated_cannot_access_student_dashboard(): void
    {
        $this->getJson('/api/student/dashboard')
            ->assertStatus(401);
    }

    public function test_admin_cannot_access_student_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/student/dashboard')
            ->assertStatus(403);
    }

    public function test_instructor_cannot_access_student_dashboard(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor']);

        $this->actingAs($instructor, 'sanctum')
            ->getJson('/api/student/dashboard')
            ->assertStatus(403);
    }

    // ─── Registrations ────────────────────────────────────────────────────────

    public function test_student_can_list_own_registrations(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course  = Course::factory()->free()->create();

        Registration::factory()->create([
            'course_id' => $course->id,
            'user_id'   => $student->id,
            'email'     => $student->email,
            'status'    => 'Registered',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/registrations')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_student_registrations_list_is_scoped_to_own_records(): void
    {
        $studentA = User::factory()->create(['role' => 'student']);
        $studentB = User::factory()->create(['role' => 'student']);
        $course   = Course::factory()->free()->create();

        // Only Student A has a registration
        Registration::factory()->create([
            'course_id' => $course->id,
            'user_id'   => $studentA->id,
            'email'     => $studentA->email,
        ]);

        $response = $this->actingAs($studentB, 'sanctum')
            ->getJson('/api/student/registrations');

        $response->assertOk();

        // Student B's list should contain no items
        $items = $response->json('data.data') ?? $response->json('data') ?? [];
        $this->assertEmpty($items, 'Student B should see zero registrations');
    }

    // ─── Courses ─────────────────────────────────────────────────────────────

    public function test_student_can_view_available_courses(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Course::factory()->count(3)->free()->create();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/available-courses')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_student_can_view_enrolled_courses(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course  = Course::factory()->free()->create();

        Registration::factory()->create([
            'course_id' => $course->id,
            'user_id'   => $student->id,
            'email'     => $student->email,
            'status'    => 'Registered',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/courses')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ─── Certificates ─────────────────────────────────────────────────────────

    public function test_student_can_list_own_certificates(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/certificates')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ─── Progress & Assignments ───────────────────────────────────────────────

    public function test_student_can_access_own_progress(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/progress')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_student_can_access_assignments_list(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/assignments')
            ->assertOk()
            ->assertJson(['success' => true]);
    }
}
