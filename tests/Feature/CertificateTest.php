<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    private function createCertificate(array $overrides = []): Certificate
    {
        $user   = $overrides['user'] ?? User::factory()->create(['role' => 'student']);
        $course = $overrides['course'] ?? Course::factory()->free()->create();

        return Certificate::create([
            'user_id'             => $user->id,
            'course_id'           => $course->id,
            'certificate_type'    => 'course_completion',
            'title'               => 'Course Completion Certificate',
            'status'              => $overrides['status'] ?? 'issued',
            'verification_code'   => $overrides['verification_code'] ?? ('CERT-TEST-' . rand(1000, 9999)),
            'issued_at'           => now(),
        ]);
    }

    public function test_valid_verification_code_returns_certificate(): void
    {
        $this->createCertificate(['verification_code' => 'CERT-VERIFY-XYZ']);

        $response = $this->getJson('/api/certificates/verify/CERT-VERIFY-XYZ');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.verification_code', 'CERT-VERIFY-XYZ');
    }

    public function test_invalid_verification_code_returns_404(): void
    {
        $response = $this->getJson('/api/certificates/verify/NONEXISTENT-CODE');

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_student_can_list_own_certificates(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $this->createCertificate(['user' => $student]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/certificates');

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_admin_can_list_certificates(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/certificates');

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_admin_can_issue_certificate(): void
    {
        $admin   = User::factory()->admin()->create();
        $student = User::factory()->create(['role' => 'student']);
        $course  = Course::factory()->free()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/certificates', [
                'user_id'          => $student->id,
                'course_id'        => $course->id,
                'certificate_type' => 'course_completion',
                'title'            => 'Laravel Mastery Certificate',
            ]);

        $response->assertCreated()->assertJson(['success' => true]);
    }

    public function test_student_cannot_issue_certificate(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course  = Course::factory()->free()->create();

        $response = $this->actingAs($student, 'sanctum')
            ->postJson('/api/admin/certificates', [
                'user_id'          => $student->id,
                'course_id'        => $course->id,
                'certificate_type' => 'course_completion',
                'title'            => 'Fake Certificate',
            ]);

        $response->assertStatus(403);
    }
}
