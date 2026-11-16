<?php

namespace Tests\Feature\Volunteers;

use App\Mail\VolunteerAcceptedMail;
use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Models\VolunteerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Path A: an accepted volunteer request becomes a member when HR confirms a department in the convert popup.
 * One transaction creates the account, the department membership, the team profile and the volunteer-hub profile; the e-mail goes out after commit.
 */
class VolunteerConversionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function dept(string $nameAr, array $extra = []): Department
    {
        return Department::create(array_merge([
            'name' => $nameAr, 'name_ar' => $nameAr, 'slug' => 'd-' . md5($nameAr . json_encode($extra)),
            'status' => 'active', 'is_active' => true, 'sort_order' => 1,
        ], $extra));
    }

    private function accepted(array $over = []): VolunteerRequest
    {
        return VolunteerRequest::create(array_merge([
            'full_name' => 'Ahmed Al-Rashidi', 'email' => 'ahmed@example.com', 'phone' => '+9665012345678',
            'country' => 'Saudi Arabia', 'city' => 'Riyadh', 'gender' => 'male', 'desired_department' => 'التقنية',
            'experience_level' => 'intermediate', 'skills' => 'PHP', 'availability' => 'weekends',
            'motivation' => str_repeat('أريد المساهمة. ', 5), 'status' => 'accepted',
        ], $over));
    }

    private function convert(User $actor, VolunteerRequest $vr, array $body)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($actor, 'sanctum')->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", $body);
    }

    /** The temporary password as the recipient reads it in the e-mail. */
    private function passwordFromMail(VolunteerAcceptedMail $mail): string
    {
        $this->assertTrue($mail->hasTemporaryPassword());
        $this->assertSame(1, preg_match('/unicode-bidi:embed;">([^<]+)</u', $mail->render(), $m), 'the password is shown in the e-mail');

        return trim($m[1]);
    }

    /** Fails when $needle appears in ANY column of ANY table (jobs, notifications, logs, users...). */
    private function assertNowhereInDatabase(string $needle): void
    {
        foreach (Schema::getTables() as $table) {
            foreach (DB::table($table['name'])->get() as $row) {
                $this->assertStringNotContainsString($needle, json_encode($row, JSON_UNESCAPED_UNICODE), "found in table {$table['name']}");
            }
        }
    }

    // ── happy path ───────────────────────────────────────────────────────────

    public function test_conversion_creates_everything_in_the_department_HR_chose_not_the_one_requested(): void
    {
        Mail::fake();
        $requested = $this->dept('التقنية');
        $chosen    = $this->dept('الإعلام');
        $leader    = User::factory()->create(['role' => 'department_member', 'is_active' => true]);
        $chosen->update(['leader_id' => $leader->id]);
        $hr = $this->admin('hr_manager');
        $vr = $this->accepted(['desired_department' => 'التقنية']);

        $res = $this->convert($hr, $vr, ['department_id' => $chosen->id])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.department_id', $chosen->id)
            ->assertJsonPath('data.department_name', 'الإعلام')
            ->assertJsonPath('data.role_key', 'department_member')
            ->assertJsonPath('data.member_type', 'volunteer')
            ->assertJsonPath('data.source', 'volunteer_request')
            ->assertJsonPath('data.source_id', $vr->id)
            ->assertJsonPath('data.has_user_account', true)
            ->assertJsonPath('data.account_created', true)
            ->assertJsonPath('data.account_existing', false)
            ->assertJsonPath('data.email_sent', true)
            ->assertJsonPath('volunteer_request.status', 'converted_to_member');

        // 1. account
        $user = User::where('email', 'ahmed@example.com')->firstOrFail();
        $this->assertSame($user->id, $res->json('data.user_id'));
        $this->assertSame('department_member', $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->is_active);
        $this->assertSame('+9665012345678', $user->phone);
        $this->assertSame('Riyadh', $user->city);
        $this->assertSame('male', $user->gender);

        // 2. department membership + unassigned seat
        $this->assertSame([$chosen->id], $user->departments->pluck('id')->all());
        $this->assertTrue((bool) $user->departments->first()->pivot->is_primary);
        $this->assertFalse($user->departments->contains($requested->id));
        $seat = TeamMember::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($chosen->id, $seat->department_id);
        $this->assertNull($seat->section_id);

        // 3. team profile (Members page, Team management, public /team)
        $profile = TeamProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($profile->id, $res->json('data.id'));
        $this->assertSame($chosen->id, $profile->department_id);
        $this->assertSame('volunteer', $profile->member_type);
        $this->assertSame('department_member', $profile->role_key);
        $this->assertSame('volunteer_request', $profile->source);
        $this->assertSame($vr->id, (int) $profile->source_id);
        $this->assertSame($profile->id, $seat->team_profile_id);

        // 4. volunteer hub profile (Impact Points)
        $hub = VolunteerProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($chosen->id, $hub->department_id);
        $this->assertSame('active', $hub->status);
        $this->assertSame('pending', $hub->onboarding_status);

        // 5. request stamp
        $fresh = $vr->fresh();
        $this->assertSame('converted_to_member', $fresh->status);
        $this->assertNotNull($fresh->converted_to_member_at);
        $this->assertSame($profile->id, $fresh->converted_member_id);
        $this->assertSame($hr->id, $fresh->converted_by);

        // notification still fires, to the CHOSEN department's leader
        $this->assertDatabaseHas('platform_notifications', ['user_id' => $leader->id, 'type' => 'member_converted']);
    }

    public function test_the_password_goes_only_into_the_email_and_is_stored_only_as_a_hash(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');

        $res = $this->convert($this->admin(), $this->accepted(), ['department_id' => $dept->id])->assertCreated();

        Mail::assertSent(VolunteerAcceptedMail::class, 1);
        $sent = Mail::sent(VolunteerAcceptedMail::class)->first();
        $this->assertTrue($sent->hasTo('ahmed@example.com'));

        $password = $this->passwordFromMail($sent);
        $this->assertSame(12, strlen($password));

        $html = $sent->render();
        $this->assertStringContainsString('ahmed@example.com', $html);
        $this->assertStringContainsString('التقنية', $html);
        $this->assertStringContainsString('تم قبولك', $html);

        $user = User::where('email', 'ahmed@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password), 'the mailed password really is the account password');
        $this->assertStringNotContainsString($password, $res->getContent(), 'never in the response');
        $this->assertNowhereInDatabase($password);
    }

    public function test_mail_failure_does_not_roll_back_or_500(): void
    {
        $dept = $this->dept('التقنية');
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->convert($this->admin(), $this->accepted(), ['department_id' => $dept->id])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email_sent', false)
            ->assertJsonPath('data.account_created', true);

        $this->assertSame('converted_to_member', VolunteerRequest::firstOrFail()->status);
        $this->assertSame(1, User::where('email', 'ahmed@example.com')->count());
        $this->assertSame(1, TeamProfile::count());
        $this->assertSame(1, VolunteerProfile::count());
    }

    // ── validation ───────────────────────────────────────────────────────────

    public function test_a_missing_or_invalid_department_is_a_422_in_arabic_and_creates_nothing(): void
    {
        Mail::fake();
        $this->dept('التقنية');
        $archived = $this->dept('قديمة', ['status' => 'archived']);
        $vr = $this->accepted();
        $hr = $this->admin('hr_manager');

        foreach ([[], ['department_id' => null], ['department_id' => 999999], ['department_id' => 'abc'], ['department_id' => $archived->id]] as $body) {
            $res = $this->convert($hr, $vr, $body)->assertStatus(422)->assertJsonPath('success', false);
            $this->assertNotEmpty($res->json('errors.department_id'));
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $res->json('message'));
        }

        $this->assertSame('accepted', $vr->fresh()->status);
        $this->assertSame(0, TeamProfile::count());
        $this->assertSame(0, User::where('email', 'ahmed@example.com')->count());
        Mail::assertNothingSent();
    }

    public function test_a_request_that_is_not_accepted_is_a_422(): void
    {
        $dept = $this->dept('التقنية');
        $vr = $this->accepted(['status' => 'pending']);

        $this->convert($this->admin(), $vr, ['department_id' => $dept->id])->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(0, User::where('email', 'ahmed@example.com')->count());
    }

    public function test_second_conversion_is_a_409_and_creates_nothing_twice(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $other = $this->dept('الإعلام');
        $vr = $this->accepted();
        $hr = $this->admin();

        $this->convert($hr, $vr, ['department_id' => $dept->id])->assertCreated();
        $counts = [User::count(), TeamProfile::count(), TeamMember::count(), VolunteerProfile::count()];

        $this->convert($hr, $vr, ['department_id' => $other->id])
            ->assertStatus(409)
            ->assertJsonPath('already_converted', true);

        $this->assertSame($counts, [User::count(), TeamProfile::count(), TeamMember::count(), VolunteerProfile::count()]);
        Mail::assertSent(VolunteerAcceptedMail::class, 1);
    }

    // ── atomicity ────────────────────────────────────────────────────────────

    public function test_a_failure_after_the_user_insert_leaves_nothing_behind(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $vr = $this->accepted();

        // The account is created first; the team profile insert blows up afterwards.
        TeamProfile::creating(function () {
            throw new \RuntimeException('boom after the user insert');
        });

        $this->convert($this->admin(), $vr, ['department_id' => $dept->id])
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertSame(0, User::where('email', 'ahmed@example.com')->count(), 'the user insert was rolled back');
        $this->assertSame(0, TeamProfile::count());
        $this->assertSame(0, TeamMember::count());
        $this->assertSame(0, VolunteerProfile::count());
        $this->assertSame(0, DB::table('user_departments')->count());
        $fresh = $vr->fresh();
        $this->assertSame('accepted', $fresh->status);
        $this->assertNull($fresh->converted_to_member_at);
        Mail::assertNothingSent();
    }

    // ── existing accounts ────────────────────────────────────────────────────

    public function test_an_existing_student_account_is_upgraded_and_gets_a_mail_without_a_password(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $student = User::factory()->create(['role' => 'student', 'email' => 'ahmed@example.com', 'must_change_password' => false]);
        $hashBefore = $student->password;

        $this->convert($this->admin(), $this->accepted(), ['department_id' => $dept->id])
            ->assertCreated()
            ->assertJsonPath('data.account_created', false)
            ->assertJsonPath('data.account_existing', true)
            ->assertJsonPath('data.user_id', $student->id);

        $student->refresh();
        $this->assertSame('department_member', $student->role);
        $this->assertFalse($student->must_change_password, 'their own password is untouched');
        $this->assertSame($hashBefore, $student->password);
        $this->assertTrue($student->departments->contains($dept->id));
        $this->assertSame(1, User::where('email', 'ahmed@example.com')->count());
        $this->assertSame($student->id, TeamProfile::firstOrFail()->user_id);
        $this->assertSame($student->id, VolunteerProfile::firstOrFail()->user_id);

        Mail::assertSent(VolunteerAcceptedMail::class, fn ($m) => $m->hasTo('ahmed@example.com') && ! $m->hasTemporaryPassword());
        $sent = Mail::sent(VolunteerAcceptedMail::class)->first();
        $this->assertStringContainsString('كلمة المرور الحالية', $sent->render());
    }

    public function test_an_existing_privileged_account_keeps_its_role_and_is_only_linked(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');

        foreach (['instructor', 'admin', 'super_admin', 'hr_manager'] as $i => $role) {
            $email = "{$role}@example.com";
            $account = User::factory()->create(['role' => $role, 'email' => $email]);
            $vr = $this->accepted(['email' => $email, 'full_name' => "Person {$i}"]);

            $this->convert($this->admin(), $vr, ['department_id' => $dept->id])->assertCreated()->assertJsonPath('data.account_existing', true);

            $this->assertSame($role, $account->fresh()->role, "{$role} keeps its role");
            $this->assertTrue($account->fresh()->departments->contains($dept->id));
            $this->assertSame($account->id, TeamProfile::where('source_id', $vr->id)->firstOrFail()->user_id);
        }
    }

    public function test_the_request_e_mail_is_lower_cased_before_it_is_matched_to_an_account(): void
    {
        // (The users.email collation is case-insensitive in MySQL, which is what makes "Ahmed@X.com" match "ahmed@x.com" in production;
        // SQLite is case-sensitive, so this test covers the half the application does itself: it normalises the request's e-mail.)
        Mail::fake();
        $dept = $this->dept('التقنية');
        $existing = User::factory()->create(['role' => 'student', 'email' => 'ahmed@example.com']);

        $this->convert($this->admin(), $this->accepted(['email' => '  Ahmed@Example.COM ']), ['department_id' => $dept->id])
            ->assertCreated()
            ->assertJsonPath('data.account_existing', true);

        $this->assertSame(2, User::count(), 'admin + the existing account, no duplicate');
        $this->assertSame($existing->id, TeamProfile::firstOrFail()->user_id);
        $this->assertSame('department_member', $existing->fresh()->role);
    }

    public function test_a_new_account_is_created_with_a_lower_cased_e_mail(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');

        $this->convert($this->admin(), $this->accepted(['email' => 'Mixed.Case@Example.com']), ['department_id' => $dept->id])->assertCreated();

        $this->assertSame(1, User::where('email', 'mixed.case@example.com')->count());
    }

    public function test_an_account_that_already_has_a_placement_keeps_it_everywhere(): void
    {
        Mail::fake();
        $chosen = $this->dept('التقنية');
        $placedIn = $this->dept('الإعلام');
        $user = User::factory()->create(['role' => 'student', 'email' => 'ahmed@example.com']);
        $profile = TeamProfile::create([
            'user_id' => $user->id, 'name_ar' => 'Ahmed', 'position_ar' => 'منسق', 'department_id' => $placedIn->id, 'sort_order' => 1,
        ]);
        $seat = TeamMember::create(['user_id' => $user->id, 'team_profile_id' => $profile->id, 'department_id' => $placedIn->id, 'status' => 'active', 'role_title' => 'منسق']);
        VolunteerProfile::create(['user_id' => $user->id, 'department_id' => $placedIn->id, 'status' => 'active']);

        // team_profiles.user_id is unique: this must not blow up, and HR's choice must not move anybody.
        $res = $this->convert($this->admin(), $this->accepted(), ['department_id' => $chosen->id])
            ->assertCreated()
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.department_id', $placedIn->id)
            ->assertJsonPath('data.department_name', 'الإعلام')
            ->assertJsonPath('data.department_kept_existing', true);

        $this->assertSame(1, TeamProfile::count());
        $this->assertSame($placedIn->id, $profile->fresh()->department_id);
        $this->assertSame(1, TeamMember::count());
        $this->assertSame($placedIn->id, $seat->fresh()->department_id);
        $this->assertSame(1, VolunteerProfile::count());
        $this->assertSame($placedIn->id, VolunteerProfile::firstOrFail()->department_id);
        $this->assertSame([$placedIn->id], $user->fresh()->departments->pluck('id')->all(), 'the chosen department is not added');
        $this->assertSame($profile->id, VolunteerRequest::firstOrFail()->converted_member_id);
        $this->assertSame($user->id, $res->json('data.user_id'));
    }

    public function test_an_existing_profile_without_a_department_gets_the_chosen_one(): void
    {
        Mail::fake();
        $chosen = $this->dept('التقنية');
        $user = User::factory()->create(['role' => 'student', 'email' => 'ahmed@example.com']);
        $profile = TeamProfile::create(['user_id' => $user->id, 'name_ar' => 'Ahmed', 'position_ar' => 'منسق', 'department_id' => null, 'sort_order' => 1]);

        $this->convert($this->admin(), $this->accepted(), ['department_id' => $chosen->id])
            ->assertCreated()
            ->assertJsonPath('data.department_id', $chosen->id)
            ->assertJsonPath('data.department_kept_existing', false);

        $this->assertSame($chosen->id, $profile->fresh()->department_id);
        $this->assertSame($chosen->id, TeamMember::firstOrFail()->department_id);
        $this->assertSame($chosen->id, VolunteerProfile::firstOrFail()->department_id);
        $this->assertTrue($user->fresh()->departments->contains($chosen->id));
    }

    public function test_a_soft_deleted_account_with_the_same_e_mail_is_a_422_and_nothing_is_created(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $gone = User::factory()->create(['role' => 'student', 'email' => 'ahmed@example.com']);
        $gone->delete();

        $this->convert($this->admin(), $this->accepted(), ['department_id' => $dept->id])->assertStatus(422);

        $this->assertSame(0, TeamProfile::count());
        $this->assertSame('accepted', VolunteerRequest::firstOrFail()->status);
    }

    // ── authorisation ────────────────────────────────────────────────────────

    public function test_only_hr_and_admin_roles_may_convert(): void
    {
        $dept = $this->dept('التقنية');
        $vr = $this->accepted();

        foreach (['student', 'instructor', 'department_member', 'volunteer'] as $role) {
            $this->convert($this->admin($role), $vr, ['department_id' => $dept->id])->assertStatus(403);
        }
        $this->assertSame('accepted', $vr->fresh()->status);

        foreach (['hr_manager', 'admin', 'super_admin', 'tech_admin'] as $i => $role) {
            Mail::fake();
            $req = $this->accepted(['email' => "ok{$i}@example.com"]);
            $this->convert($this->admin($role), $req, ['department_id' => $dept->id])->assertCreated();
        }
    }

    // ── suggested department on the accepted list ────────────────────────────

    public function test_the_accepted_list_suggests_the_department_with_the_same_matching_as_convert(): void
    {
        $inactive = $this->dept('التقنية', ['status' => 'inactive', 'is_active' => false]);   // lower id, but inactive
        $active   = $this->dept('التقنية', ['slug' => 'tech-active']);
        $contains = $this->dept('الإعلام والتسويق');
        $english  = $this->dept('الموارد', ['name' => 'Human Resources']);

        $a = $this->accepted(['email' => 'a@x.com', 'desired_department' => 'التقنية']);
        $b = $this->accepted(['email' => 'b@x.com', 'desired_department' => 'الإعلام']);
        $c = $this->accepted(['email' => 'c@x.com', 'desired_department' => 'human resources']);
        $d = $this->accepted(['email' => 'd@x.com', 'desired_department' => 'لا يوجد']);
        $e = $this->accepted(['email' => 'e@x.com', 'desired_department' => '']);

        $this->assertLessThan($active->id, $inactive->id);

        $this->app['auth']->forgetGuards();
        $rows = collect($this->actingAs($this->admin('hr_manager'), 'sanctum')->getJson('/api/admin/volunteers/accepted')->assertOk()->json('data.data'))
            ->keyBy('id');

        $this->assertSame($active->id, $rows[$a->id]['suggested_department_id'], 'an active department beats an inactive one');
        $this->assertSame('التقنية', $rows[$a->id]['suggested_department_name']);
        $this->assertSame($contains->id, $rows[$b->id]['suggested_department_id']);
        $this->assertSame($english->id, $rows[$c->id]['suggested_department_id']);
        $this->assertNull($rows[$d->id]['suggested_department_id']);
        $this->assertNull($rows[$d->id]['suggested_department_name']);
        $this->assertNull($rows[$e->id]['suggested_department_id']);

        // the single-request endpoint (same resource) agrees
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin('hr_manager'), 'sanctum')->getJson("/api/admin/volunteer-requests/{$a->id}")
            ->assertOk()->assertJsonPath('data.suggested_department_id', $active->id);
    }

    // ── resend credentials ───────────────────────────────────────────────────

    private function converted(): array
    {
        $dept = $this->dept('التقنية');
        $vr = $this->accepted();
        $this->convert($this->admin(), $vr, ['department_id' => $dept->id])->assertCreated();

        return [$vr->fresh(), User::where('email', 'ahmed@example.com')->firstOrFail()];
    }

    private function resend(User $actor, VolunteerRequest $vr)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($actor, 'sanctum')->postJson("/api/admin/volunteer-requests/{$vr->id}/resend-credentials");
    }

    public function test_resend_issues_a_new_password_and_keeps_the_flag(): void
    {
        Mail::fake();
        [$vr, $user] = $this->converted();
        $first = $this->passwordFromMail(Mail::sent(VolunteerAcceptedMail::class)->first());
        $oldHash = $user->password;
        $user->createToken('old-session');

        $res = $this->resend($this->admin('hr_manager'), $vr)->assertOk()->assertJson(['success' => true, 'email_sent' => true]);

        $user->refresh();
        $this->assertNotSame($oldHash, $user->password);
        $this->assertTrue($user->must_change_password);
        $this->assertFalse(Hash::check($first, $user->password), 'the old temporary password stops working');
        $this->assertSame(0, $user->tokens()->count(), 'sessions opened with the old password are revoked');

        Mail::assertSent(VolunteerAcceptedMail::class, 2);
        $second = $this->passwordFromMail(Mail::sent(VolunteerAcceptedMail::class)->last());
        $this->assertNotSame($first, $second);
        $this->assertTrue(Hash::check($second, $user->password));
        $this->assertStringNotContainsString($second, $res->getContent());
        $this->assertNowhereInDatabase($second);
    }

    public function test_resend_is_refused_when_there_is_nothing_to_reissue(): void
    {
        Mail::fake();
        $hr = $this->admin('hr_manager');

        // not converted yet
        $pending = $this->accepted();
        $this->resend($hr, $pending)->assertStatus(422)->assertJsonPath('success', false);

        // converted, but the owner already chose their own password
        [$vr, $user] = $this->converted();
        $user->forceFill(['must_change_password' => false])->save();
        $res = $this->resend($hr, $vr)->assertStatus(422);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $res->json('message'));

        // converted but the account was an existing one that never had a temporary password
        Mail::assertSent(VolunteerAcceptedMail::class, 1);
    }

    public function test_resend_is_denied_to_other_roles_without_burning_the_target_s_quota(): void
    {
        Mail::fake();
        [$vr] = $this->converted();

        foreach (['student', 'instructor', 'department_member'] as $role) {
            $this->resend($this->admin($role), $vr)->assertStatus(403);
        }

        // the people who may do it still can: the denied callers did not use up the one-per-10-minutes slot
        $this->resend($this->admin('hr_manager'), $vr)->assertOk();
    }

    public function test_resend_reports_a_mail_failure_without_failing(): void
    {
        [$vr] = $this->converted();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->resend($this->admin('hr_manager'), $vr)->assertOk()->assertJson(['success' => true, 'email_sent' => false]);
    }
}
