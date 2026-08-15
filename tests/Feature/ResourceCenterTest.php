<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceCenterTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_hr_manager_can_list_courses(): void
    {
        Course::factory()->create(['title' => 'Digital Skills', 'status' => 'published']);

        $this->actingAs($this->makeUser('hr_manager'), 'sanctum')
            ->getJson('/api/admin/resource-center/courses')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_marketing_and_quality_and_support_agent_can_list_courses(): void
    {
        foreach (['marketing', 'quality', 'support_agent', 'finance_manager', 'instructor'] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')
                ->getJson('/api/admin/resource-center/courses')
                ->assertOk();
        }
    }

    public function test_student_cannot_access_resource_center(): void
    {
        $this->actingAs($this->makeUser('student'), 'sanctum')
            ->getJson('/api/admin/resource-center/courses')
            ->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/resource-center/courses')->assertStatus(401);
    }

    public function test_listing_includes_public_url_and_is_favorite(): void
    {
        $course = Course::factory()->create(['title' => 'AI Basics', 'slug' => 'ai-basics', 'status' => 'published']);

        $response = $this->actingAs($this->makeUser('hr_manager'), 'sanctum')
            ->getJson('/api/admin/resource-center/courses')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $course->id);
        $this->assertNotNull($row);
        $this->assertStringContainsString('/courses/ai-basics', $row['public_url']);
        $this->assertFalse($row['is_favorite']);
    }

    public function test_favorite_toggle_works(): void
    {
        $course = Course::factory()->create(['status' => 'published']);
        $user = $this->makeUser('hr_manager');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/resource-center/courses/{$course->id}/favorite")
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['is_favorite' => true]]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/resource-center/courses')
            ->assertOk()
            ->assertJsonFragment(['is_favorite' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/resource-center/courses/{$course->id}/favorite")
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['is_favorite' => false]]);
    }

    public function test_export_returns_csv_with_utf8_bom(): void
    {
        Course::factory()->create(['title' => 'مهارات رقمية', 'status' => 'published']);

        $response = $this->actingAs($this->makeUser('hr_manager'), 'sanctum')
            ->get('/api/admin/resource-center/export?format=csv')
            ->assertOk();

        $content = $response->streamedContent();
        $this->assertStringStartsWith(chr(0xEF) . chr(0xBB) . chr(0xBF), $content);
        $this->assertStringContainsString('العنوان', $content);
    }

    public function test_export_xlsx_is_a_valid_zip_based_file(): void
    {
        Course::factory()->create(['status' => 'published']);

        $response = $this->actingAs($this->makeUser('hr_manager'), 'sanctum')
            ->get('/api/admin/resource-center/export?format=xlsx')
            ->assertOk();

        $content = $response->streamedContent();
        $this->assertStringStartsWith("PK\x03\x04", $content);
    }

    public function test_draft_and_archived_courses_are_still_visible_to_staff(): void
    {
        Course::factory()->create(['status' => 'draft', 'title' => 'Draft Course']);

        $response = $this->actingAs($this->makeUser('hr_manager'), 'sanctum')
            ->getJson('/api/admin/resource-center/courses?status=draft')
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }
}
