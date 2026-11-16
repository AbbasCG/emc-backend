<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\VolunteerHrProfile;
use App\Models\VolunteerRequest;
use App\Services\VolunteerApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approving a volunteer's HR profile (the legacy path) must not change accounts, roles or department membership on its own unless the
 * business switched that rule on (config/volunteer_onboarding.php). Off is the default and the previous behaviour.
 * Converting a volunteer REQUEST is not governed by a switch any more: it always creates / links the account (see VolunteerConversionTest).
 */
class VolunteerOnboardingSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private function department(): Department
    {
        return Department::firstOrCreate(['slug' => 'tech-x'], ['name' => 'Technology', 'name_ar' => 'التقنية', 'status' => 'active', 'is_active' => true, 'sort_order' => 1]);
    }

    private function approvedCandidate(string $role): array
    {
        $dept = $this->department();
        $user = User::factory()->create(['role' => $role, 'email' => $role.'@emc.test', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $profile = VolunteerHrProfile::create([
            'full_name' => 'Candidate', 'email' => $user->email, 'normalized_email' => $user->email, 'phone' => '123456789',
            'job_title' => 'متطوع', 'join_date' => now()->toDateString(), 'cv_path' => 'cvs/sample.pdf',
            'user_id' => $user->id, 'status' => 'interview_scheduled',
        ]);

        return [$profile, $admin, $dept, $user];
    }

    // ── approval ─────────────────────────────────────────────────────────────

    public function test_approval_changes_no_role_and_adds_no_membership_by_default(): void
    {
        foreach (['student', 'volunteer', 'instructor'] as $role) {
            [$profile, $admin, $dept, $user] = $this->approvedCandidate($role);
            app(VolunteerApprovalService::class)->approve($profile, $admin, $dept->id);

            $user->refresh();
            $this->assertSame($role, $user->role, "$role keeps their role");
            $this->assertFalse($user->departments()->exists(), "$role is not attached to the department");
            $this->assertSame(0, TeamMember::where('user_id', $user->id)->count());
            $this->assertSame('approved', $profile->fresh()->status);
            $this->assertNotNull($profile->fresh()->team_profile_id, 'the team profile is still created');
        }
    }

    public function test_role_switch_promotes_only_student_and_volunteer(): void
    {
        config(['volunteer_onboarding.approval_assigns_department_member_role' => true]);

        [$profile, $admin, $dept, $volunteer] = $this->approvedCandidate('volunteer');
        app(VolunteerApprovalService::class)->approve($profile, $admin, $dept->id);
        $this->assertSame('department_member', $volunteer->fresh()->role);
        $this->assertFalse($volunteer->fresh()->departments()->exists(), 'the role switch alone does not attach a department');

        [$profile2, $admin2, , $instructor] = $this->approvedCandidate('instructor');
        app(VolunteerApprovalService::class)->approve($profile2, $admin2, $dept->id);
        $this->assertSame('instructor', $instructor->fresh()->role, 'an instructor is never re-roled');
    }

    public function test_membership_switch_attaches_department_and_seats_unassigned(): void
    {
        config(['volunteer_onboarding.approval_links_department_membership' => true]);

        [$profile, $admin, $dept, $user] = $this->approvedCandidate('volunteer');
        app(VolunteerApprovalService::class)->approve($profile, $admin, $dept->id);

        $this->assertSame('volunteer', $user->fresh()->role, 'the membership switch alone does not change the role');
        $this->assertTrue($user->fresh()->departments->contains($dept->id));
        $seat = TeamMember::where('user_id', $user->id)->first();
        $this->assertNotNull($seat);
        $this->assertNull($seat->section_id);
    }

    // ── conversion ───────────────────────────────────────────────────────────

    private function acceptedRequest(string $email): VolunteerRequest
    {
        return VolunteerRequest::create([
            'full_name' => 'Ahmed', 'email' => $email, 'phone' => '+9665012345678', 'country' => 'SA', 'city' => 'Riyadh',
            'gender' => 'male', 'desired_department' => 'التقنية', 'experience_level' => 'intermediate', 'availability' => 'weekends',
            'motivation' => str_repeat('أريد المساهمة. ', 5), 'status' => 'accepted',
        ]);
    }

    public function test_conversion_no_longer_depends_on_the_old_conversion_switch(): void
    {
        // `conversion_creates_account` guarded the OLD behaviour (convert = team profile only). Converting now always creates / links the
        // account, whatever that switch says; the approval_* switches above still guard only the legacy HR-profile approval.
        foreach ([false, true] as $switch) {
            config(['volunteer_onboarding.conversion_creates_account' => $switch]);

            $dept = $this->department();
            $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
            $email = 'new-'.($switch ? 'on' : 'off').'@emc.test';

            $this->app['auth']->forgetGuards();
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/admin/volunteer-requests/'.$this->acceptedRequest($email)->id.'/convert-to-member', ['department_id' => $dept->id])
                ->assertCreated()
                ->assertJsonPath('data.role_key', 'department_member')
                ->assertJsonPath('data.account_created', true);

            $created = User::where('email', $email)->firstOrFail();
            $this->assertSame('department_member', $created->role);
            $this->assertTrue($created->departments->contains($dept->id));
            $this->assertNull(TeamMember::where('user_id', $created->id)->firstOrFail()->section_id);
        }
    }

    public function test_conversion_upgrades_a_student_and_never_re_roles_an_instructor(): void
    {
        $dept = $this->department();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $student = User::factory()->create(['role' => 'student', 'email' => 'existing@emc.test']);
        $instructor = User::factory()->create(['role' => 'instructor', 'email' => 'teacher@emc.test']);

        foreach (['existing@emc.test', 'teacher@emc.test'] as $email) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/admin/volunteer-requests/'.$this->acceptedRequest($email)->id.'/convert-to-member', ['department_id' => $dept->id])
                ->assertCreated()
                ->assertJsonPath('data.account_existing', true);
        }

        $this->assertSame('department_member', $student->fresh()->role);
        $this->assertSame('instructor', $instructor->fresh()->role, 'an existing instructor is never re-roled');
        $this->assertTrue($instructor->fresh()->departments->contains($dept->id), 'but is still linked to the chosen department');
    }
}
