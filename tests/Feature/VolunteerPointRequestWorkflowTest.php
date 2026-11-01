<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerPointActivity;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerPointRequest;
use App\Support\Permissions;
use Database\Seeders\GranularPermissionsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VolunteerPointActivitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VolunteerPointRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            GranularPermissionsSeeder::class,
            VolunteerPointActivitySeeder::class,
        ]);
        $this->artisan('access:sync-role-defaults', ['--apply' => true]);
    }

    private function createTeamMember(string $name = 'متطوع تجريبي', ?Department $department = null): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'volunteer', 'is_active' => true]);
        $dept = $department ?? Department::firstOrCreate(['name' => 'إدارة التقنية'], ['name_ar' => 'إدارة التقنية', 'slug' => 'tech', 'is_active' => true]);

        $profile = TeamProfile::create([
            'department_id' => $dept->id,
            'user_id'       => $user->id,
            'name_ar'       => $name,
            'position_ar'   => 'عضو فريق',
            'is_active'     => true,
        ]);

        TeamMember::create([
            'user_id'         => $user->id,
            'team_profile_id' => $profile->id,
            'department_id'   => $dept->id,
            'status'          => 'active',
            'role_title'      => 'عضو متطوع',
        ]);

        return $user;
    }

    public function test_activities_endpoint_returns_categories_and_activities(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/operations/volunteer-points/activities');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertNotEmpty($response->json('data.categories'));
        $this->assertNotEmpty($response->json('data.activities'));
    }

    public function test_submitting_point_request_saves_as_pending_without_increasing_balance(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $volunteer = $this->createTeamMember('أحمد المتطوع');

        $activity = VolunteerPointActivity::where('type', 'fixed')
            ->where('points_min', 50)
            ->firstOrFail();

        $response = $this->actingAs($admin)->postJson('/api/operations/volunteer-points/requests', [
            'user_id'     => $volunteer->id,
            'activity_id' => $activity->id,
            'points'      => 50,
            'reason'      => 'حضور اجتماع رسمي للإدارة في الموعد المحدد',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.points_requested', 50);

        // التأكد من أن الرصيد لم يزد في جدول الدفتر
        $this->assertEquals(0, VolunteerPointAward::where('user_id', $volunteer->id)->sum('points'));
        $this->assertDatabaseHas('volunteer_point_requests', [
            'user_id'          => $volunteer->id,
            'points_requested' => 50,
            'status'           => 'pending',
        ]);
    }

    public function test_submitting_points_outside_activity_range_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $volunteer = $this->createTeamMember('سارة المتطوعة');

        $activity = VolunteerPointActivity::where('type', 'range')
            ->where('points_min', 25)
            ->where('points_max', 50)
            ->firstOrFail();

        // إدخال نقاط أعلى من النطاق (60 بدلاً من حد أقصى 50)
        $response = $this->actingAs($admin)->postJson('/api/operations/volunteer-points/requests', [
            'user_id'     => $volunteer->id,
            'activity_id' => $activity->id,
            'points'      => 60,
            'reason'      => 'إنجاز مهمة بسيطة',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'POINTS_OUT_OF_RANGE');
    }

    public function test_hr_approves_request_and_balance_is_increased_once(): void
    {
        $hrManager = User::factory()->create(['role' => 'hr_manager', 'is_active' => true]);
        $volunteer = $this->createTeamMember('محمد المتميز');

        $activity = VolunteerPointActivity::where('type', 'fixed')->where('points_min', 100)->firstOrFail();

        $pointRequest = VolunteerPointRequest::create([
            'user_id'          => $volunteer->id,
            'requester_id'     => $hrManager->id,
            'activity_id'      => $activity->id,
            'category_key'     => $activity->category_key,
            'activity_name'    => $activity->name_ar,
            'points_requested' => 100,
            'reason'           => 'حضور جميع الاجتماعات الشهرية',
            'status'           => 'pending',
        ]);

        // اعتماد الطلب
        $response = $this->actingAs($hrManager)->postJson("/api/operations/volunteer-points/requests/{$pointRequest->id}/approve", [
            'approved_points' => 100,
            'review_notes'    => 'تم التحقق من الحضور الكامل',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // التحقق من تحديث حالة الطلب
        $pointRequest->refresh();
        $this->assertEquals('approved', $pointRequest->status);
        $this->assertNotNull($pointRequest->award_id);

        // التحقق من تحديث الرصيد مرة واحدة فقط في الدفتر
        $this->assertEquals(100, VolunteerPointAward::where('user_id', $volunteer->id)->sum('points'));
    }

    public function test_hr_rejects_request_with_reason_and_balance_remains_unchanged(): void
    {
        $hrManager = User::factory()->create(['role' => 'hr_manager', 'is_active' => true]);
        $volunteer = $this->createTeamMember('علي المتطوع');

        $activity = VolunteerPointActivity::where('type', 'fixed')->where('points_min', 50)->firstOrFail();

        $pointRequest = VolunteerPointRequest::create([
            'user_id'          => $volunteer->id,
            'requester_id'     => $hrManager->id,
            'activity_id'      => $activity->id,
            'category_key'     => $activity->category_key,
            'activity_name'    => $activity->name_ar,
            'points_requested' => 50,
            'reason'           => 'طلب غير مستوفٍ للشروط',
            'status'           => 'pending',
        ]);

        // محاولة الرفض دون سبب (يجب أن ترفض)
        $this->actingAs($hrManager)->postJson("/api/operations/volunteer-points/requests/{$pointRequest->id}/reject", [
            'rejection_reason' => '',
        ])->assertStatus(422);

        // الرفض مع سبب
        $response = $this->actingAs($hrManager)->postJson("/api/operations/volunteer-points/requests/{$pointRequest->id}/reject", [
            'rejection_reason' => 'عدم اكتمال التوثيق المطلوب',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $pointRequest->refresh();
        $this->assertEquals('rejected', $pointRequest->status);
        $this->assertEquals('عدم اكتمال التوثيق المطلوب', $pointRequest->rejection_reason);

        // الرصيد لم يتغير
        $this->assertEquals(0, VolunteerPointAward::where('user_id', $volunteer->id)->sum('points'));
    }
}
