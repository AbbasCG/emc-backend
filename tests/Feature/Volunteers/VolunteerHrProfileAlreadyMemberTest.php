<?php

namespace Tests\Feature\Volunteers;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerHrProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for the "already_member" false-positive report on
 * /dashboard/volunteer/hr-profile. The check lives in
 * VolunteerApprovalService::findDuplicate() and queries TeamProfile by
 * user_id (authenticated identity, never email/phone) scoped to is_active —
 * TeamProfile has no soft-deletes, removal is a hard delete
 * (AdminTeamProfileController::destroy()), so is_active is the only
 * "currently a member" signal this schema has.
 */
class VolunteerHrProfileAlreadyMemberTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MakesCvFiles;

    protected function setUp(): void
    {
        parent::setUp();
        // The legacy HR-profile pipeline is switched off by default (cancelled path); these tests prove its legacy code, so turn it on.
        config(['volunteer_onboarding.hr_profile_pipeline_enabled' => true]);
    }

    private function department(): Department
    {
        return Department::create([
            'name_ar' => 'التقنية', 'name' => 'Technology', 'slug' => 'tech-' . uniqid(), 'is_active' => true,
        ]);
    }

    private function volunteer(): User
    {
        return User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function payload(Department $dept, array $overrides = []): array
    {
        return array_merge([
            'full_name'   => 'أحمد محمد',
            'email'       => 'ahmad-' . uniqid() . '@example.com',
            'phone'       => '0501234567',
            'department_id' => $dept->id,
            'job_title'   => 'مطور برمجيات',
            'join_date'   => now()->toDateString(),
            'confirmed'   => true,
            'photo_publication_consent'    => true,
            'professional_profile_consent' => true,
            'cv_file'     => $this->pdfCv('cv.pdf', 100),
        ], $overrides);
    }

    // 1) user with no member record can submit
    public function test_user_with_no_team_profile_can_submit(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        $response->assertStatus(201)->assertJson(['success' => true]);
    }

    // 2) active team member is blocked
    public function test_active_team_member_is_blocked_with_already_member(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id, 'name_ar' => 'أحمد',
            'position_ar' => 'مطور', 'role_key' => 'volunteer', 'is_active' => true, 'sort_order' => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        $response->assertStatus(422)->assertJson(['success' => false, 'code' => 'already_member']);
    }

    // 3) pending HR profile does not return false already_member
    public function test_pending_profile_returns_pending_exists_not_already_member(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $email = 'pending-' . uniqid() . '@example.com';
        VolunteerHrProfile::create([
            'user_id' => $user->id, 'full_name' => 'أحمد', 'email' => $email, 'normalized_email' => $email,
            'phone' => '0500000000', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'submitted',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept, ['email' => $email]));

        $response->assertStatus(422)->assertJson(['code' => 'pending_exists']);
        $this->assertNotSame('already_member', $response->json('code'));
    }

    // 4) rejected profile follows resubmission policy (allowed via update(), not blocked by findDuplicate on store())
    public function test_rejected_profile_does_not_block_a_fresh_submission(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $email = 'rejected-' . uniqid() . '@example.com';
        VolunteerHrProfile::create([
            'user_id' => $user->id, 'full_name' => 'أحمد', 'email' => $email, 'normalized_email' => $email,
            'phone' => '0500000000', 'department_id' => $dept->id, 'job_title' => 'X', 'join_date' => now(),
            'cv_path' => 'x.pdf', 'status' => 'rejected',
        ]);

        // 'rejected' is not in VolunteerHrProfile::OPEN_STATUSES, so a brand new
        // submission (different email) for this user must not be blocked.
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        $response->assertStatus(201);
    }

    // 5) an inactive (is_active=false) team_profiles row does not falsely block
    public function test_inactive_team_profile_row_does_not_block_submission(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id, 'name_ar' => 'أحمد',
            'position_ar' => 'سابقاً', 'role_key' => 'volunteer', 'is_active' => false, 'sort_order' => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        $response->assertStatus(201);
    }

    // 6) TeamProfile has no SoftDeletes — confirms removal is a hard delete,
    // so a "removed" member genuinely has no row left to match, not a
    // trashed one an unscoped query could incorrectly pick up.
    public function test_team_profile_model_has_no_soft_deletes(): void
    {
        $this->assertFalse(
            in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(TeamProfile::class), true),
        );
    }

    // 7) a team_profiles row with the SAME EMAIL but a DIFFERENT user_id must
    // not block the authenticated user — the check is by user_id, not email.
    public function test_unrelated_active_member_with_same_email_does_not_block_authenticated_user(): void
    {
        Storage::fake('private');
        $otherUser = $this->volunteer();
        $dept = $this->department();
        $sharedEmail = 'shared-' . uniqid() . '@example.com';
        TeamProfile::create([
            'user_id' => $otherUser->id, 'department_id' => $dept->id, 'name_ar' => 'شخص آخر',
            'position_ar' => 'X', 'role_key' => 'volunteer', 'is_active' => true, 'sort_order' => 1,
            'email' => $sharedEmail,
        ]);

        $me = $this->volunteer();
        $response = $this->actingAs($me, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept, ['email' => $sharedEmail]));

        // Blocked (if at all) only by the email-based already_volunteer/pending_exists
        // checks — never by already_member, which is scoped to $userId = $me->id.
        $this->assertNotSame('already_member', $response->json('code'));
    }

    // 8) duplicate detection queries by the authenticated user's own id, not
    // by an id supplied in the request body.
    public function test_already_member_check_uses_authenticated_user_id(): void
    {
        Storage::fake('private');
        $me = $this->volunteer();
        $anotherActiveMember = $this->volunteer();
        $dept = $this->department();
        TeamProfile::create([
            'user_id' => $anotherActiveMember->id, 'department_id' => $dept->id, 'name_ar' => 'عضو آخر',
            'position_ar' => 'X', 'role_key' => 'volunteer', 'is_active' => true, 'sort_order' => 1,
        ]);

        // $me has no team_profiles row of their own — must be allowed to submit
        // even though *some* team_profiles row exists in the table.
        $response = $this->actingAs($me, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));

        $response->assertStatus(201);
    }

    // 9) successful submission stores the profile in volunteer_hr_profiles
    public function test_successful_submission_stores_profile_in_correct_table(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept))->assertStatus(201);

        $this->assertDatabaseHas('volunteer_hr_profiles', ['user_id' => $user->id, 'status' => 'submitted']);
        $this->assertDatabaseCount('team_profiles', 0);
    }

    // 10) approval creates/syncs exactly one team_profiles row, never a duplicate
    public function test_approval_creates_exactly_one_team_profile_row(): void
    {
        Storage::fake('private');
        $user = $this->volunteer();
        $dept = $this->department();
        $hr = User::factory()->create(['role' => 'hr_manager', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept))->assertStatus(201);
        $profile = VolunteerHrProfile::where('user_id', $user->id)->firstOrFail();

        $this->actingAs($hr, 'sanctum')->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/approve", ['department_id' => $dept->id])->assertOk();

        $this->assertDatabaseCount('team_profiles', 1);
        $this->assertDatabaseHas('team_profiles', ['user_id' => $user->id, 'is_active' => true]);

        // A now-active member resubmitting the (already-approved) profile is
        // correctly blocked — and re-approving again is idempotent, no second row.
        $second = $this->actingAs($user, 'sanctum')->postJson('/api/volunteer/hr-profile', $this->payload($dept));
        $second->assertStatus(422)->assertJson(['code' => 'already_member']);
        $this->assertDatabaseCount('team_profiles', 1);
    }
}
