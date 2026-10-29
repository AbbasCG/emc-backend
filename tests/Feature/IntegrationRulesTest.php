<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Rules the dynamic-departments merge must not change silently: public/anonymous surface, program dates, ghost roles.
 */
class IntegrationRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_options_require_a_signed_in_user(): void
    {
        Department::create(['name' => 'Finance', 'name_ar' => 'المالية', 'slug' => 'finance', 'status' => 'active', 'is_active' => true]);

        $this->getJson('/api/departments/options')->assertUnauthorized();

        $user = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $this->actingAs($user, 'sanctum')->getJson('/api/departments/options')->assertOk();
    }

    public function test_no_route_refers_to_a_role_that_does_not_exist(): void
    {
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware)) {
                    $this->assertStringNotContainsString('academy_manager', $middleware, $route->uri());
                }
            }
        }
    }

    public function test_program_dates_stay_optional_until_the_business_requires_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/learning-paths', ['title' => 'مسار بلا تواريخ', 'status' => 'draft'])
            ->assertCreated();

        config(['programs.require_dates' => true]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/learning-paths', ['title' => 'مسار آخر', 'status' => 'draft'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_date', 'end_date']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/learning-paths', ['title' => 'مسار بتواريخ', 'status' => 'draft', 'start_date' => '2027-01-01', 'end_date' => '2027-03-01'])
            ->assertCreated();
    }
}
