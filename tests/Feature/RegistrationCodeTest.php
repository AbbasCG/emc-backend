<?php

namespace Tests\Feature;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationCodeTest extends TestCase
{
    use RefreshDatabase;

    private function payload(int $courseId, array $extra = []): array
    {
        return array_merge([
            'course_id' => $courseId,
            'full_name' => 'Test Registrant',
            'phone'     => '0612345678',
            'email'     => 'student@example.com',
            'city'      => 'Riyadh',
        ], $extra);
    }

    public function test_open_course_registers_without_code(): void
    {
        Mail::fake();
        $course = Course::factory()->free()->create([
            'requires_registration_code' => false,
        ]);

        $this->postJson('/api/register', $this->payload($course->id))
            ->assertCreated();
    }

    public function test_protected_course_rejects_missing_code(): void
    {
        Mail::fake();
        $course = Course::factory()->free()->create([
            'requires_registration_code' => true,
            'registration_code'          => 'SECRET123',
        ]);

        $this->postJson('/api/register', $this->payload($course->id))
            ->assertStatus(422)
            ->assertJsonFragment([
                'message_ar' => 'رمز التسجيل غير صحيح أو مطلوب لهذه الدورة.',
            ]);
    }

    public function test_protected_course_rejects_wrong_code(): void
    {
        Mail::fake();
        $course = Course::factory()->free()->create([
            'requires_registration_code' => true,
            'registration_code'          => 'SECRET123',
        ]);

        $this->postJson('/api/register', $this->payload($course->id, [
            'registration_code' => 'wrong-code',
        ]))
            ->assertStatus(422)
            ->assertJsonFragment([
                'message_ar' => 'رمز التسجيل غير صحيح أو مطلوب لهذه الدورة.',
            ]);
    }

    public function test_protected_course_accepts_correct_code_case_insensitive(): void
    {
        Mail::fake();
        $course = Course::factory()->free()->create([
            'requires_registration_code' => true,
            'registration_code'          => 'Secret123',
        ]);

        $this->postJson('/api/register', $this->payload($course->id, [
            'registration_code' => '  secret123  ',
        ]))
            ->assertCreated();
    }

    public function test_public_course_resource_hides_registration_code(): void
    {
        $course = Course::factory()->free()->create([
            'requires_registration_code' => true,
            'registration_code'          => 'SECRET123',
            'status'                     => 'published',
        ]);

        $response = $this->getJson("/api/courses/{$course->slug}");
        $response->assertOk();
        $json = json_encode($response->json());
        $this->assertStringNotContainsString('SECRET123', $json);
        $this->assertTrue(
            (bool) data_get($response->json(), 'data.requires_registration_code')
                ?? data_get($response->json(), 'requires_registration_code')
        );
    }
}
