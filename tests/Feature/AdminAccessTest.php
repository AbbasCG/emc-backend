<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_registrations(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/registrations');

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_student_cannot_access_admin_registrations(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/registrations');

        $response->assertStatus(403);
    }

    public function test_guest_cannot_access_admin_registrations(): void
    {
        $response = $this->getJson('/api/admin/registrations');

        $response->assertStatus(401);
    }

    public function test_admin_can_access_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard');

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_student_cannot_access_admin_dashboard(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(403);
    }

    public function test_admin_can_access_payments(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/payments');

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_partner_cannot_access_admin_payments(): void
    {
        $partner = User::factory()->create(['role' => 'partner']);

        $response = $this->actingAs($partner, 'sanctum')
            ->getJson('/api/admin/payments');

        $response->assertStatus(403);
    }
}
