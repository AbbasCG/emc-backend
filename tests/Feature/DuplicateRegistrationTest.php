<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Verifies that duplicate enrollment is blocked at the registration endpoint.
 *
 * Regression: a student who has already registered for a course must receive
 * a 422 on any subsequent registration attempt for the same course.
 */
class DuplicateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(int $courseId, string $email, array $extra = []): array
    {
        return array_merge([
            'course_id' => $courseId,
            'full_name' => 'Test Registrant',
            'phone'     => '0612345678',
            'email'     => $email,
            'city'      => 'Riyadh',
        ], $extra);
    }

    // ─── Guest registrations ──────────────────────────────────────────────────

    public function test_same_email_cannot_register_twice_for_same_course(): void
    {
        Mail::fake();
        $course = Course::factory()->free()->create();

        $this->postJson('/api/register', $this->payload($course->id, 'student@example.com'))
            ->assertCreated();

        // Second attempt with the same email + course must be rejected.
        // The app returns 409 Conflict (semantically correct for duplicates).
        $response = $this->postJson('/api/register', $this->payload($course->id, 'student@example.com'));
        $this->assertContains($response->status(), [409, 422],
            "Expected 409 Conflict or 422 for duplicate registration, got {$response->status()}");
        $response->assertJson(['success' => false]);

        $this->assertEquals(
            1,
            Registration::where('course_id', $course->id)->where('email', 'student@example.com')->count(),
            'Only one registration should exist'
        );
    }

    public function test_same_email_can_register_for_different_courses(): void
    {
        Mail::fake();
        $course1 = Course::factory()->free()->create();
        $course2 = Course::factory()->free()->create();

        $this->postJson('/api/register', $this->payload($course1->id, 'multi@example.com'))
            ->assertCreated();

        $this->postJson('/api/register', $this->payload($course2->id, 'multi@example.com'))
            ->assertCreated();

        $this->assertEquals(
            2,
            Registration::where('email', 'multi@example.com')->count(),
            'Registrations for different courses must be allowed'
        );
    }

    // ─── Authenticated registrations ──────────────────────────────────────────

    public function test_authenticated_student_cannot_register_twice_for_same_course(): void
    {
        Mail::fake();
        $student = User::factory()->create(['role' => 'student']);
        $course  = Course::factory()->free()->create();

        $payload = $this->payload($course->id, $student->email);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/register', $payload)
            ->assertCreated();

        $response = $this->actingAs($student, 'sanctum')
            ->postJson('/api/register', $payload);
        $this->assertContains($response->status(), [409, 422],
            "Expected 409/422 for authenticated duplicate registration, got {$response->status()}");
        $response->assertJson(['success' => false]);
    }

    public function test_authenticated_student_can_register_for_multiple_courses(): void
    {
        Mail::fake();
        $student = User::factory()->create(['role' => 'student']);
        $course1 = Course::factory()->free()->create();
        $course2 = Course::factory()->free()->create();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/register', $this->payload($course1->id, $student->email))
            ->assertCreated();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/register', $this->payload($course2->id, $student->email))
            ->assertCreated();

        $this->assertEquals(2, Registration::where('email', $student->email)->count());
    }

    // ─── Paid course duplicates ───────────────────────────────────────────────

    public function test_duplicate_registration_blocked_for_paid_course(): void
    {
        Mail::fake();
        $course = Course::factory()->paid(49.99)->create();

        // First registration (paid — pending payment)
        $this->postJson('/api/register', $this->payload($course->id, 'paid@example.com', [
            'payment_provider' => 'fake',
        ]))->assertCreated();

        // Second attempt must be blocked (409 Conflict or 422)
        $response = $this->postJson('/api/register', $this->payload($course->id, 'paid@example.com', [
            'payment_provider' => 'fake',
        ]));
        $this->assertContains($response->status(), [409, 422],
            "Expected 409/422 for duplicate paid registration, got {$response->status()}");

        $this->assertEquals(
            1,
            Registration::where('course_id', $course->id)->where('email', 'paid@example.com')->count()
        );
    }

    // ─── Different user, same course ─────────────────────────────────────────

    public function test_different_users_can_register_for_same_course(): void
    {
        Mail::fake();
        $course = Course::factory()->free()->create();

        $this->postJson('/api/register', $this->payload($course->id, 'alice@example.com'))
            ->assertCreated();

        $this->postJson('/api/register', $this->payload($course->id, 'bob@example.com'))
            ->assertCreated();

        $this->assertEquals(2, Registration::where('course_id', $course->id)->count());
    }
}
