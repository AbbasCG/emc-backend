<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\PlatformNotification;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Full test suite for the volunteer → team_profile conversion flow
 * and the optional user-account creation step.
 *
 * Source of truth: team_profiles (not team_members).
 */
class MemberConversionTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function acceptedVr(array $overrides = []): VolunteerRequest
    {
        return VolunteerRequest::create(array_merge([
            'full_name'          => 'Ahmed Al-Rashidi',
            'email'              => 'ahmed@example.com',
            'phone'              => '+9665012345678',
            'country'            => 'Saudi Arabia',
            'city'               => 'Riyadh',
            'gender'             => 'male',
            'desired_department' => 'التقنية',
            'experience_level'   => 'intermediate',
            'skills'             => 'PHP, Laravel',
            'availability'       => 'weekends',
            'motivation'         => str_repeat('أريد المساهمة في تطوير المجتمع. ', 3),
            'status'             => 'accepted',
        ], $overrides));
    }

    /** The request body HR sends: the department picked in the convert popup (created on first use). */
    private function chosen(?Department $dept = null): array
    {
        return ['department_id' => ($dept ?? $this->defaultDept())->id];
    }

    private ?Department $defaultDept = null;

    private function defaultDept(): Department
    {
        return $this->defaultDept ??= Department::where('name_ar', 'التقنية')->first() ?? $this->dept('التقنية');
    }

    private function dept(string $nameAr = 'التقنية'): Department
    {
        return Department::create([
            'name'       => $nameAr,
            'name_ar'    => $nameAr,
            'slug'       => 'tech-' . md5($nameAr),
            'is_active'  => true,
            'sort_order' => 1,
        ]);
    }

    // ─── Conversion: accepted volunteer → team_profiles ──────────────────────

    public function test_accepted_volunteer_converts_to_team_profiles(): void
    {
        $admin = $this->admin();
        $dept  = $this->dept('التقنية');
        $vr    = $this->acceptedVr(['desired_department' => 'التقنية']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.member_type', 'volunteer')
            ->assertJsonPath('data.source', 'volunteer_request')
            ->assertJsonPath('data.role_key', 'department_member');

        $memberId = $response->json('data.id');
        $this->assertNotNull($memberId);

        $this->assertDatabaseHas('team_profiles', [
            'id'            => $memberId,
            'name_ar'       => 'Ahmed Al-Rashidi',
            'email'         => 'ahmed@example.com',
            'phone'         => '+9665012345678',
            'department_id' => $dept->id,
            'source'        => 'volunteer_request',
            'source_id'     => $vr->id,
            'is_new_member' => true,
            'role_key'      => 'department_member',
            'position_ar'   => 'عضو إدارة',
            'member_type'   => 'volunteer',
        ]);
    }

    public function test_conversion_succeeds_when_the_requested_text_matches_no_department(): void
    {
        // Since the convert popup, HR always names the department; a free-text request that matches nothing is no obstacle.
        $admin  = $this->admin();
        $chosen = $this->dept('الإعلام');
        $vr     = $this->acceptedVr(['desired_department' => 'قسم غير موجود']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen($chosen))
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('team_profiles', [
            'id'            => $response->json('data.id'),
            'department_id' => $chosen->id,
            'source'        => 'volunteer_request',
        ]);
    }

    public function test_converted_to_member_at_is_stamped_on_volunteer_request(): void
    {
        $admin = $this->admin();
        $vr    = $this->acceptedVr();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertCreated();

        $fresh = $vr->fresh();
        $this->assertNotNull($fresh->converted_to_member_at);
        $this->assertNotNull($fresh->converted_member_id);
    }

    public function test_response_includes_volunteer_request_with_conversion_timestamps(): void
    {
        $admin = $this->admin();
        $vr    = $this->acceptedVr();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertCreated();

        // volunteer_request key must have the conversion timestamps
        $vrData = $response->json('volunteer_request');
        $this->assertNotNull($vrData);
        $this->assertNotNull($vrData['converted_to_member_at']);
        $this->assertNotNull($vrData['converted_member_id']);
        $this->assertFalse((bool) $vrData['can_convert_to_member']);
    }

    // ─── Duplicate prevention ─────────────────────────────────────────────────

    public function test_duplicate_conversion_is_blocked_with_409(): void
    {
        $admin = $this->admin();
        $vr    = $this->acceptedVr();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertStatus(409)
            ->assertJson(['success' => false, 'already_converted' => true]);
    }

    // ─── Status guard ─────────────────────────────────────────────────────────

    public function test_pending_volunteer_cannot_be_converted(): void
    {
        $admin = $this->admin();
        $vr    = VolunteerRequest::create(array_merge(
            ['status' => 'pending'],
            $this->acceptedVr()->toArray(),
            ['id' => null, 'email' => 'pending@example.com'],
        ));

        // Fresh pending VR (easiest: just create one directly)
        $vr2 = VolunteerRequest::create([
            'full_name' => 'Test', 'email' => 'pending2@example.com',
            'phone' => '0000', 'country' => 'SA', 'city' => 'Riyadh',
            'gender' => 'male', 'desired_department' => 'التقنية',
            'experience_level' => 'junior', 'availability' => 'full_time',
            'motivation' => str_repeat('x', 50),
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr2->id}/convert-to-member", $this->chosen())
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // ─── Members API includes converted volunteers ────────────────────────────

    public function test_converted_volunteer_appears_in_members_api(): void
    {
        $admin = $this->admin();
        $vr    = $this->acceptedVr(['full_name' => 'نور الهدى', 'email' => 'nour@example.com']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertCreated();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/members')
            ->assertOk()
            ->assertJson(['success' => true]);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('نور الهدى', $names);
    }

    public function test_members_api_returns_correct_shape(): void
    {
        $admin = $this->admin();
        $dept  = $this->dept();
        $vr    = $this->acceptedVr(['desired_department' => 'التقنية']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertCreated();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/members')
            ->assertOk();

        $member = collect($response->json('data'))->first();
        $this->assertArrayHasKey('name', $member);
        $this->assertArrayHasKey('email', $member);
        $this->assertArrayHasKey('phone', $member);
        $this->assertArrayHasKey('department', $member);
        $this->assertArrayHasKey('member_type', $member);
        $this->assertArrayHasKey('is_new', $member);
        $this->assertArrayHasKey('volunteer_request_id', $member);
        $this->assertSame('volunteer_request', $member['source']);
        $this->assertTrue($member['is_new']);
    }

    public function test_can_convert_flag_is_false_after_conversion(): void
    {
        $admin = $this->admin();
        $vr    = $this->acceptedVr();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertCreated();

        // Fetch the VR; can_convert_to_member must now be false
        $vrResponse = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/volunteer-requests/{$vr->id}")
            ->assertOk();

        $this->assertFalse((bool) $vrResponse->json('data.can_convert_to_member'));
    }

    // ─── Notifications ────────────────────────────────────────────────────────

    public function test_conversion_creates_notifications_for_admins(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin      = $this->admin();
        $vr         = $this->acceptedVr();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertCreated();

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $superAdmin->id,
            'type'    => 'member_converted',
        ]);
        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $admin->id,
            'type'    => 'member_converted',
        ]);
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_student_cannot_convert_volunteer(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $vr      = $this->acceptedVr();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertStatus(403);
    }

    // ─── User account creation ────────────────────────────────────────────────

    public function test_user_account_can_be_created_from_team_profile(): void
    {
        $admin  = $this->admin();
        // A legacy converted volunteer: team profile only, no account (made before conversion created accounts).
        $profile = TeamProfile::create([
            'name_ar' => 'Legacy Volunteer', 'position_ar' => 'متطوع', 'email' => 'newuser@example.com',
            'member_type' => 'volunteer', 'source' => 'volunteer_request', 'sort_order' => 1,
        ]);
        $memberId = $profile->id;

        // Create user account
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/team-profiles/{$memberId}/create-user-account")
            ->assertCreated()
            ->assertJson(['success' => true, 'data' => ['created' => true]]);

        $this->assertNotNull($response->json('data.temporary_password'));
        $this->assertTrue($response->json('data.password_shown_once'));
        $this->assertNotNull($response->json('data.user.id'));

        // must_change_password is set on new accounts
        $this->assertDatabaseHas('users', [
            'email'                => 'newuser@example.com',
            'role'                 => 'volunteer',
            'must_change_password' => 1,
        ]);

        // team_profile linked
        $this->assertDatabaseHas('team_profiles', [
            'id'      => $memberId,
            'user_id' => $response->json('data.user.id'),
        ]);
    }

    public function test_conversion_already_links_the_account_so_create_user_account_reports_already_linked(): void
    {
        $admin = $this->admin();
        $vr    = $this->acceptedVr(['email' => 'fresh@example.com']);

        $memberId = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $this->chosen())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/team-profiles/{$memberId}/create-user-account")
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['already_linked' => true]]);

        $this->assertSame(1, User::where('email', 'fresh@example.com')->count());
    }

    public function test_existing_user_is_linked_not_duplicated(): void
    {
        $admin        = $this->admin();
        $existingUser = User::factory()->create(['email' => 'existing@example.com', 'role' => 'student']);
        $profile      = TeamProfile::create([
            'name_ar' => 'Legacy Volunteer', 'position_ar' => 'متطوع', 'email' => 'existing@example.com',
            'member_type' => 'volunteer', 'source' => 'volunteer_request', 'sort_order' => 1,
        ]);
        $memberId = $profile->id;

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/team-profiles/{$memberId}/create-user-account")
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['linked_existing' => true]]);

        // No duplicate user created
        $this->assertSame(1, User::where('email', 'existing@example.com')->count());
        $this->assertSame($existingUser->id, $response->json('data.user.id'));

        // team_profile linked to existing user
        $this->assertDatabaseHas('team_profiles', [
            'id'      => $memberId,
            'user_id' => $existingUser->id,
        ]);
    }

    public function test_already_linked_profile_returns_info_not_duplicate(): void
    {
        $admin   = $this->admin();
        $user    = User::factory()->create(['email' => 'linked@example.com']);
        $profile = TeamProfile::create([
            'name_ar'    => 'Linked User',
            'position_ar'=> 'متطوع',
            'email'      => 'linked@example.com',
            'user_id'    => $user->id,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/team-profiles/{$profile->id}/create-user-account")
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['already_linked' => true]]);

        $this->assertNull($response->json('data.temporary_password'));
    }

    public function test_profile_without_email_cannot_create_account(): void
    {
        $admin   = $this->admin();
        $profile = TeamProfile::create([
            'name_ar'    => 'بدون بريد',
            'position_ar'=> 'متطوع',
            'sort_order' => 1,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/team-profiles/{$profile->id}/create-user-account")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }
}
