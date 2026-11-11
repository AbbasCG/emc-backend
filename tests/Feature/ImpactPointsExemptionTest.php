<?php

namespace Tests\Feature;

use App\Http\Controllers\Operations\VolunteerPointsController;
use App\Models\Department;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\VolunteerPointActivity;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerPointRequest;
use Database\Seeders\GranularPermissionsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VolunteerPointActivitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpactPointsExemptionTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private User $recipient;
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GranularPermissionsSeeder::class);
        $this->seed(VolunteerPointActivitySeeder::class);

        $this->dept = Department::create([
            'name' => 'Programs',
            'name_ar' => 'البرامج',
            'slug' => 'programs',
        ]);
        $this->hr = User::factory()->create(['role' => 'hr_manager', 'is_active' => true]);
        $this->recipient = User::factory()->create(['role' => 'volunteer', 'is_active' => true]);

        $profile = \App\Models\TeamProfile::create([
            'department_id' => $this->dept->id,
            'user_id' => $this->recipient->id,
            'name_ar' => $this->recipient->name,
            'position_ar' => 'عضو تطوعي',
            'is_active' => true,
        ]);

        TeamMember::create([
            'user_id' => $this->recipient->id,
            'team_profile_id' => $profile->id,
            'department_id' => $this->dept->id,
            'status' => 'active',
            'role_title' => 'عضو تطوعي',
        ]);
    }

    public function test_internal_tasks_are_capped_at_2500_points_monthly(): void
    {
        // Seed 2400 points from internal department tasks
        VolunteerPointAward::create([
            'user_id' => $this->recipient->id,
            'category' => 'admin_tech_tasks',
            'points' => 2400,
            'reason' => 'مهام داخلية',
            'awarded_at' => now(),
        ]);

        // Attempting to award 150 points for internal task fails (2400 + 150 = 2550 > 2500)
        $this->actingAs($this->hr)->postJson('/api/operations/volunteer-points/awards', [
            'user_id' => $this->recipient->id,
            'category' => 'task',
            'points' => 150,
            'reason' => 'مهمة إدارية إضافية',
        ])->assertStatus(422)->assertJsonPath('code', 'MONTHLY_CAP_EXCEEDED');

        // Awarding 100 points succeeds exactly at the 2500 cap
        $this->actingAs($this->hr)->postJson('/api/operations/volunteer-points/awards', [
            'user_id' => $this->recipient->id,
            'category' => 'task',
            'points' => 100,
            'reason' => 'مهمة إدارية مقبولة',
        ])->assertCreated();
    }

    public function test_exempt_categories_can_exceed_the_2500_cap_without_error(): void
    {
        // Seed full 2500 points from internal tasks
        VolunteerPointAward::create([
            'user_id' => $this->recipient->id,
            'category' => 'task',
            'points' => 2500,
            'reason' => 'اكتمل سقف الأعمال الداخلية',
            'awarded_at' => now(),
        ]);

        // 1. المبادرات: تمنح بنجاح حتى عند اكتمال السقف
        $this->actingAs($this->hr)->postJson('/api/operations/volunteer-points/awards', [
            'user_id' => $this->recipient->id,
            'category' => 'initiatives_institutional',
            'points' => 500,
            'reason' => 'مبادرة مؤسسية كبرى',
        ])->assertCreated();

        // 2. المعسكرات: تمنح بنجاح
        $this->actingAs($this->hr)->postJson('/api/operations/volunteer-points/awards', [
            'user_id' => $this->recipient->id,
            'category' => 'camps',
            'points' => 400,
            'reason' => 'معسكر إعداد قادة',
        ])->assertCreated();

        // 3. الجوائز الشهرية: تمنح بنجاح
        $this->actingAs($this->hr)->postJson('/api/operations/volunteer-points/awards', [
            'user_id' => $this->recipient->id,
            'category' => 'monthly_awards',
            'points' => 300,
            'reason' => 'جائزة المتطوع المتميز للشهر',
        ])->assertCreated();

        // 4. الجوائز الاستثنائية: تمنح بنجاح
        $this->actingAs($this->hr)->postJson('/api/operations/volunteer-points/awards', [
            'user_id' => $this->recipient->id,
            'category' => 'exceptional_continuity',
            'points' => 1000,
            'reason' => 'جائزة الأثر الاستثنائي',
        ])->assertCreated();
    }

    public function test_points_from_exempt_categories_do_not_consume_the_internal_tasks_cap(): void
    {
        // Give 5000 points from initiatives, camps, and exceptional awards
        VolunteerPointAward::create([
            'user_id' => $this->recipient->id,
            'category' => 'initiatives_institutional',
            'points' => 2000,
            'reason' => 'مبادرة استراتيجية',
            'awarded_at' => now(),
        ]);
        VolunteerPointAward::create([
            'user_id' => $this->recipient->id,
            'category' => 'camps',
            'points' => 1500,
            'reason' => 'معسكر صيفي',
            'awarded_at' => now(),
        ]);
        VolunteerPointAward::create([
            'user_id' => $this->recipient->id,
            'category' => 'exceptional_continuity',
            'points' => 1500,
            'reason' => 'جائزة استثنائية',
            'awarded_at' => now(),
        ]);

        // Total points this month is 5000, but internal tasks points is 0!
        // The volunteer can STILL receive up to 2500 points of internal tasks!
        $this->actingAs($this->hr)->postJson('/api/operations/volunteer-points/awards', [
            'user_id' => $this->recipient->id,
            'category' => 'task',
            'points' => 2500,
            'reason' => 'إسناد كامل سقف الأعمال الداخلية',
        ])->assertCreated();
    }

    public function test_volunteer_point_request_flow_respects_exemption(): void
    {
        // Seed 2500 points from internal tasks
        VolunteerPointAward::create([
            'user_id' => $this->recipient->id,
            'category' => 'task',
            'points' => 2500,
            'reason' => 'سقف مكتمل',
            'awarded_at' => now(),
        ]);

        // Find activity for camps
        $campActivity = VolunteerPointActivity::where('category_key', 'camps')->firstOrFail();

        // Create a point request for camps
        $pointRequest = VolunteerPointRequest::create([
            'user_id' => $this->recipient->id,
            'department_id' => $this->dept->id,
            'requester_id' => $this->hr->id,
            'activity_id' => $campActivity->id,
            'category_key' => $campActivity->category_key,
            'activity_name' => $campActivity->name_ar,
            'points_requested' => $campActivity->points_min,
            'reason' => 'مشاركة متميزة في المعسكر',
            'status' => 'pending',
        ]);

        // HR approves the request: it succeeds even though member has 2500 internal points!
        $this->actingAs($this->hr)->postJson("/api/operations/volunteer-points/requests/{$pointRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.request.status', 'approved');
    }
}
