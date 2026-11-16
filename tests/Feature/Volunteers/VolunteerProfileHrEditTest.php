<?php

namespace Tests\Feature\Volunteers;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerHrProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * HR edits a volunteer's profile from /dashboard/hr/volunteers, and the change reaches the two places that show it:
 * the volunteer's own profile (/dashboard/volunteer/hr-profile, the same row) and the team record created at approval
 * (/dashboard/super-admin/crud/team, team_profiles + its operational team_members row). Nothing is duplicated, no role,
 * membership or review status changes, and only the whitelisted fields can be written.
 */
class VolunteerProfileHrEditTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\MakesCvFiles;

    private Department $dept;

    private Department $otherDept;

    private User $volunteer;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        // The legacy HR-profile pipeline is switched off by default (cancelled path); these tests prove its legacy code, so turn it on.
        config(['volunteer_onboarding.hr_profile_pipeline_enabled' => true]);
        Storage::fake('private');
        Storage::fake('public');
        $this->dept = $this->department('التقنية');
        $this->otherDept = $this->department('الإعلام');
        $this->volunteer = User::factory()->create(['role' => 'volunteer', 'is_active' => true]);
        $this->hr = User::factory()->create(['role' => 'hr_manager', 'is_active' => true]);
    }

    private function department(string $name): Department
    {
        return Department::create(['name_ar' => $name, 'name' => $name, 'slug' => 'd-'.uniqid(), 'is_active' => true]);
    }

    /** The volunteer submits their HR profile through the real endpoint. */
    private function submitted(array $overrides = []): VolunteerHrProfile
    {
        $id = $this->actingAs($this->volunteer, 'sanctum')->postJson('/api/volunteer/hr-profile', array_merge([
            'full_name'   => 'أحمد محمد',
            'email'       => 'ahmad-'.uniqid().'@example.com',
            'phone'       => '0501234567',
            'country'     => 'هولندا',
            'city'        => 'أمستردام',
            'gender'      => 'male',
            'department_id' => $this->dept->id,
            'job_title'   => 'مطور برمجيات',
            'join_date'   => '2026-01-10',
            'skills'      => 'PHP, React',
            'confirmed'   => true,
            'photo_publication_consent'    => true,
            'professional_profile_consent' => false,
            'cv_file'     => $this->pdfCv('cv.pdf', 100),
        ], $overrides))->assertCreated()->json('data.id');

        return VolunteerHrProfile::findOrFail($id);
    }

    /** Submitted, then approved by HR through the real endpoint: the profile is linked to a team record. */
    private function approved(): VolunteerHrProfile
    {
        $profile = $this->submitted();
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/approve")->assertOk();

        return $profile->refresh();
    }

    private function edit(VolunteerHrProfile $profile, array $data, ?User $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->hr, 'sanctum')->putJson("/api/hr/volunteer-hr-profiles/{$profile->id}", $data);
    }

    // ── Editing ──────────────────────────────────────────────────────────────

    public function test_hr_saves_every_allowed_field(): void
    {
        $profile = $this->approved();
        $data = [
            'full_name' => 'أحمد محمود', 'email' => 'new-address@example.com', 'phone' => '0612345678', 'phone_country_code' => '+31',
            'country' => 'بلجيكا', 'country_code' => 'BE', 'city' => 'بروكسل', 'date_of_birth' => '1995-04-01', 'gender' => 'male',
            'nationality' => 'مصري', 'department_id' => $this->otherDept->id, 'job_title' => 'مصمم', 'employment_type' => 'part_time',
            'join_date' => '2026-02-01', 'availability' => 'evenings', 'weekly_hours' => 6, 'skills' => 'Figma',
            'languages' => ['العربية', 'الإنجليزية'], 'education' => 'بكالوريوس', 'university_specialization' => 'تصميم',
            'experience' => 'خمس سنوات', 'professional_bio' => '<b>نبذة</b> مختصرة', 'linkedin_url' => 'https://linkedin.com/in/x',
            'portfolio_url' => 'https://example.com', 'emergency_contact_name' => 'سارة', 'emergency_contact_phone' => '0600000000',
        ];

        $res = $this->edit($profile, $data)->assertOk()->assertJsonPath('message', 'تم حفظ بيانات المتطوع.');

        $profile->refresh();
        foreach (['full_name', 'email', 'phone', 'city', 'job_title', 'availability', 'emergency_contact_name'] as $field) {
            $this->assertSame($data[$field], $profile->{$field}, $field);
        }
        $this->assertSame($this->otherDept->id, $profile->department_id);
        $this->assertSame('new-address@example.com', $profile->normalized_email);
        $this->assertSame('2026-02-01', $profile->join_date->toDateString());
        $this->assertSame(['العربية', 'الإنجليزية'], $profile->languages);
        $this->assertSame('نبذة مختصرة', $profile->professional_bio, 'markup is stripped like the volunteer form does');
        $res->assertJsonPath('data.emergency_contact_name', 'سارة');
    }

    public function test_the_volunteer_sees_the_change_on_their_own_profile(): void
    {
        $profile = $this->approved();
        $this->edit($profile, ['city' => 'روتردام', 'job_title' => 'قائد فريق'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->volunteer, 'sanctum')->getJson('/api/volunteer/hr-profile')->assertOk()
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.city', 'روتردام')
            ->assertJsonPath('data.job_title', 'قائد فريق');
    }

    public function test_shared_fields_reach_the_team_record_and_its_operational_membership(): void
    {
        $profile = $this->approved();
        $team = TeamProfile::findOrFail($profile->team_profile_id);
        $member = TeamMember::create(['user_id' => $this->volunteer->id, 'team_profile_id' => $team->id, 'department_id' => $this->dept->id, 'role_title' => $team->position_ar, 'status' => 'active']);

        $this->edit($profile, [
            'full_name' => 'أحمد محمود', 'job_title' => 'مصمم', 'department_id' => $this->otherDept->id,
            'city' => 'لاهاي', 'phone' => '0699999999', 'join_date' => '2026-03-01',
            'education' => 'ماجستير', 'date_of_birth' => '1990-01-01',
        ])->assertOk();

        $team->refresh();
        $this->assertSame(['أحمد محمود', 'مصمم', $this->otherDept->id, 'لاهاي', '0699999999'], [$team->name_ar, $team->position_ar, $team->department_id, $team->city, $team->phone]);
        $this->assertSame('2026-03-01', $team->joined_at->toDateString());
        $this->assertSame([$this->otherDept->id, 'مصمم'], [$member->refresh()->department_id, $member->role_title]);

        // the team page lists the updated record
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->app['auth']->forgetGuards();
        $row = collect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/team-profiles?per_page=200')->assertOk()->json('data'))->firstWhere('id', $team->id);
        $this->assertSame('أحمد محمود', $row['name_ar'] ?? $row['name'] ?? null);
    }

    public function test_hr_only_fields_and_team_only_facts_stay_where_they_belong(): void
    {
        $profile = $this->approved();
        $team = TeamProfile::findOrFail($profile->team_profile_id);
        $team->forceFill(['is_active' => false, 'is_leader' => true, 'sort_order' => 7, 'role_key' => 'volunteer'])->save();

        $this->edit($profile, ['education' => 'دكتوراه', 'emergency_contact_phone' => '0611111111', 'city' => 'أوترخت'])->assertOk();

        $team->refresh();
        $this->assertSame('أوترخت', $team->city);
        $this->assertFalse($team->is_active, 'membership visibility is a team decision, not an HR profile edit');
        $this->assertTrue($team->is_leader);
        $this->assertSame(7, (int) $team->sort_order);
        $this->assertArrayNotHasKey('education', $team->getAttributes());
    }

    public function test_nothing_is_duplicated_and_no_account_or_role_changes(): void
    {
        $profile = $this->approved();
        $counts = fn () => [User::count(), VolunteerHrProfile::count(), TeamProfile::count(), TeamMember::count()];
        $before = $counts();
        $originalEmail = $this->volunteer->email;

        $this->edit($profile, ['full_name' => 'اسم جديد', 'email' => 'changed@example.com', 'department_id' => $this->otherDept->id])->assertOk();
        $this->edit($profile->refresh(), ['city' => 'لاهاي'])->assertOk();

        $this->assertSame($before, $counts());
        $this->volunteer->refresh();
        $this->assertSame('volunteer', $this->volunteer->role);
        $this->assertSame($originalEmail, $this->volunteer->email, 'the sign-in e-mail of the account is not an HR profile field');
        $this->assertSame('approved', $profile->refresh()->status);
    }

    public function test_review_status_link_and_consents_cannot_be_written(): void
    {
        $profile = $this->approved();
        $teamId = $profile->team_profile_id;

        $this->edit($profile, [
            'city' => 'لاهاي', 'status' => 'rejected', 'user_id' => $this->hr->id, 'team_profile_id' => 999,
            'approved_by' => $this->hr->id, 'photo_publication_consent' => false, 'professional_profile_consent' => true,
            'motivation' => 'نص بديل', 'cv_path' => 'evil.pdf', 'role' => 'admin',
        ])->assertOk();

        $profile->refresh();
        $this->assertSame('لاهاي', $profile->city);
        $this->assertSame(['approved', $this->volunteer->id, $teamId, true, false], [$profile->status, $profile->user_id, $profile->team_profile_id, $profile->photo_publication_consent, $profile->professional_profile_consent]);
        $this->assertNotSame('نص بديل', $profile->motivation);
        $this->assertNotSame('evil.pdf', $profile->cv_path);
    }

    // ── Who may edit ─────────────────────────────────────────────────────────

    public function test_unauthorized_users_are_refused(): void
    {
        $profile = $this->approved();

        $this->edit($profile, ['city' => 'x'], $this->volunteer)->assertForbidden();
        $this->edit($profile, ['city' => 'x'], User::factory()->create(['role' => 'operations_manager', 'is_active' => true]))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/hr/volunteer-hr-profiles/{$profile->id}", ['city' => 'x'])->assertUnauthorized();
        $this->assertNotSame('x', $profile->refresh()->city);

        $this->edit($profile, ['city' => 'مقبول'], User::factory()->create(['role' => 'super_admin', 'is_active' => true]))->assertOk();
    }

    public function test_an_unknown_profile_is_not_found_and_another_profile_is_not_touched(): void
    {
        $first = $this->approved();
        $other = VolunteerHrProfile::query()->create(array_merge($first->only(['full_name', 'phone', 'department_id', 'job_title', 'join_date']), [
            'email' => 'other@example.com', 'normalized_email' => 'other@example.com', 'status' => 'submitted', 'city' => 'ثابتة', 'cv_path' => 'volunteer-hr-cvs/x.pdf',
        ]));

        $this->edit($first, ['city' => 'تغيرت'])->assertOk();
        $this->assertSame('ثابتة', $other->refresh()->city);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->hr, 'sanctum')->putJson('/api/hr/volunteer-hr-profiles/999999', ['city' => 'x'])->assertNotFound();
    }

    // ── Validation and rules ─────────────────────────────────────────────────

    public function test_validation_errors_are_in_arabic_and_nothing_is_saved(): void
    {
        $profile = $this->approved();

        $res = $this->edit($profile, ['full_name' => '', 'email' => 'not-an-email', 'weekly_hours' => 500, 'department_id' => 999999, 'linkedin_url' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors(['full_name', 'email', 'weekly_hours', 'department_id', 'linkedin_url']);
        $this->assertSame('الاسم الكامل مطلوب.', $res->json('errors.full_name.0'));
        $this->assertSame('أحمد محمد', $profile->refresh()->full_name);
    }

    public function test_an_email_used_by_another_volunteer_is_refused(): void
    {
        $profile = $this->approved();
        VolunteerHrProfile::query()->create(array_merge($profile->only(['full_name', 'phone', 'department_id', 'job_title', 'join_date']), [
            'email' => 'Taken@Example.com', 'normalized_email' => 'taken@example.com', 'status' => 'under_review', 'cv_path' => 'volunteer-hr-cvs/y.pdf',
        ]));

        $this->edit($profile, ['email' => 'taken@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_drafts_and_rejected_profiles_stay_with_the_volunteer(): void
    {
        $profile = $this->submitted();
        $profile->forceFill(['status' => 'rejected'])->save();
        $this->edit($profile, ['city' => 'x'])->assertUnprocessable();

        $profile->forceFill(['status' => 'submitted'])->save();
        $this->edit($profile, ['city' => 'قيد المراجعة'])->assertOk();
        $this->assertNull($profile->refresh()->team_profile_id, 'an edit never creates a team record: only approval does');
        $this->assertSame(0, TeamProfile::count());
    }

    public function test_a_save_from_a_stale_copy_is_refused(): void
    {
        $profile = $this->approved();
        $loaded = $this->actingAs($this->hr, 'sanctum')->getJson("/api/hr/volunteer-hr-profiles/{$profile->id}")->json('data.updated_at');

        $this->travel(5)->seconds();
        $this->edit($profile, ['city' => 'أولاً', 'expected_updated_at' => $loaded])->assertOk();
        $this->travel(5)->seconds();
        $this->edit($profile, ['city' => 'ثانياً', 'expected_updated_at' => $loaded])->assertStatus(409)->assertJsonPath('code', 'profile_conflict');
        $this->assertSame('أولاً', $profile->refresh()->city);
    }

    public function test_legacy_values_can_be_resent_unchanged(): void
    {
        $profile = $this->approved();
        $profile->forceFill(['gender' => 'ذكر', 'languages' => 'عربي وإنجليزي'])->save(); // values from before the pickers existed

        $this->edit($profile, ['gender' => 'ذكر', 'city' => 'لاهاي'])->assertOk();
        $this->edit($profile, ['gender' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('gender');
        $this->assertSame('لاهاي', $profile->refresh()->city);
    }

    public function test_the_edit_is_audited_with_field_names_only(): void
    {
        $profile = $this->approved();
        $this->edit($profile, ['phone' => '0688888888'])->assertOk();

        $log = \App\Models\AuditLog::query()->where('entity_type', 'VolunteerHrProfile')->where('entity_id', $profile->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('0688888888', json_encode($log->toArray(), JSON_UNESCAPED_UNICODE));
    }

    // ── From the team page back to the HR profile ────────────────────────────

    public function test_a_team_edit_of_a_shared_field_is_written_back_to_the_hr_profile(): void
    {
        $profile = $this->approved();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->app['auth']->forgetGuards();

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/team-profiles/{$profile->team_profile_id}", [
            'position_ar' => 'منسق', 'phone' => '0677777777', 'email' => null, 'is_active' => false,
        ])->assertOk();

        $profile->refresh();
        $this->assertSame(['منسق', '0677777777'], [$profile->job_title, $profile->phone]);
        $this->assertNotNull($profile->email, 'an empty value never erases a required HR field');
        $this->assertSame('approved', $profile->status, 'hiding the team card does not change the HR decision');
    }

    public function test_a_team_record_not_made_from_this_profile_is_never_linked(): void
    {
        $profile = $this->submitted();
        $unrelated = TeamProfile::create(['name_ar' => 'شخص آخر', 'position_ar' => 'مدير', 'email' => $profile->email, 'is_active' => true, 'sort_order' => 1]);
        $profile->forceFill(['status' => 'under_review'])->save();

        $this->edit($profile, ['city' => 'لاهاي', 'full_name' => 'اسم'])->assertOk();
        $this->assertSame('شخص آخر', $unrelated->refresh()->name_ar, 'sharing an e-mail is not a link');
    }
}
