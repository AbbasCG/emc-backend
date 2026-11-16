<?php

namespace Tests\Feature\Volunteers;

use App\Mail\VolunteerAcceptedMail;
use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Models\VolunteerRequest;
use App\Models\VolunteerStatusLog;
use App\Services\VolunteerHubSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Second-round hardening of the volunteer conversion: mailer safety, unverified / suspended accounts, resend restrictions and
 * limits, the one placement rule, and how an existing hub profile is reconciled.
 */
class VolunteerConversionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'hr_manager'): User
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

    private function convert(User $actor, VolunteerRequest $vr, Department $dept)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($actor, 'sanctum')->postJson("/api/admin/volunteer-requests/{$vr->id}/convert-to-member", ['department_id' => $dept->id]);
    }

    private function resend(User $actor, VolunteerRequest $vr)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($actor, 'sanctum')->postJson("/api/admin/volunteer-requests/{$vr->id}/resend-credentials");
    }

    private function passwordFromMail(VolunteerAcceptedMail $mail): string
    {
        $this->assertTrue($mail->hasTemporaryPassword());
        $this->assertSame(1, preg_match('/unicode-bidi:embed;">([^<]+)</u', $mail->render(), $m));

        return trim($m[1]);
    }

    /** A converted request whose account still holds its temporary password, built directly (no mail, no limiter use). */
    private function convertedDirect(int $i, string $role = 'department_member', ?Department $dept = null): array
    {
        $dept ??= Department::first() ?? $this->dept('التقنية');
        $user = User::factory()->create(['role' => $role, 'email' => "direct{$i}@example.com", 'must_change_password' => true, 'is_active' => true]);
        $profile = TeamProfile::create(['user_id' => $user->id, 'name_ar' => "Direct {$i}", 'position_ar' => 'عضو', 'department_id' => $dept->id, 'sort_order' => $i]);
        $vr = $this->accepted([
            'email' => "direct{$i}@example.com", 'status' => 'converted_to_member',
            'converted_to_member_at' => now(), 'converted_member_id' => $profile->id,
        ]);

        return [$vr, $user];
    }

    // ── mailer safety ────────────────────────────────────────────────────────

    public function test_a_log_or_array_mailer_outside_testing_counts_as_not_sent(): void
    {
        $dept = $this->dept('التقنية');
        $hr = $this->admin();
        $this->app->instance('env', 'production');

        foreach (['log', 'array'] as $i => $driver) {
            Mail::fake();
            config(['mail.default' => $driver]);

            $this->convert($hr, $this->accepted(['email' => "a{$i}@example.com"]), $dept)
                ->assertCreated()
                ->assertJsonPath('data.account_created', true)
                ->assertJsonPath('data.email_sent', false);

            Mail::assertNothingSent();
            $this->assertSame(1, User::where('email', "a{$i}@example.com")->count(), 'the conversion itself still happened');
        }

        // a real mailer is used normally
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->convert($hr, $this->accepted(['email' => 'real@example.com']), $dept)->assertCreated()->assertJsonPath('data.email_sent', true);
        Mail::assertSent(VolunteerAcceptedMail::class, 1);
    }

    public function test_resend_with_a_log_mailer_reports_not_sent_and_writes_nothing(): void
    {
        Mail::fake();
        [$vr] = $this->convertedDirect(1);
        $this->app->instance('env', 'production');
        config(['mail.default' => 'log']);

        $this->resend($this->admin(), $vr)->assertOk()->assertJsonPath('email_sent', false);
        Mail::assertNothingSent();
    }

    // ── unverified / suspended accounts ──────────────────────────────────────

    public function test_an_unverified_pre_registered_account_loses_its_known_password_and_sessions(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        // Someone registered ahmed@example.com first, knows its password, never verified the address.
        $squatter = User::factory()->create([
            'role' => 'student', 'email' => 'ahmed@example.com', 'email_verified_at' => null,
            'password' => Hash::make('Known-By-Squatter-1'), 'must_change_password' => false,
        ]);
        $squatter->createToken('squatter-laptop');
        $squatter->createToken('squatter-phone');

        $res = $this->convert($this->admin(), $this->accepted(), $dept)
            ->assertCreated()
            ->assertJsonPath('data.account_created', false)
            ->assertJsonPath('data.account_existing', true)
            ->assertJsonPath('data.credentials_reissued', true);

        $squatter->refresh();
        $this->assertFalse(Hash::check('Known-By-Squatter-1', $squatter->password), 'the pre-registered password no longer works');
        $this->assertSame(0, $squatter->tokens()->count(), 'every session is gone');
        $this->assertTrue($squatter->must_change_password);
        $this->assertSame('department_member', $squatter->role);

        // the address owner gets the temporary password by mail; it is not in the response
        Mail::assertSent(VolunteerAcceptedMail::class, 1);
        $sent = Mail::sent(VolunteerAcceptedMail::class)->first();
        $this->assertTrue($sent->hasTo('ahmed@example.com'));
        $password = $this->passwordFromMail($sent);
        $this->assertTrue(Hash::check($password, $squatter->password));
        $this->assertStringNotContainsString($password, $res->getContent());
    }

    public function test_a_verified_account_keeps_its_password_and_gets_the_existing_password_mail(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $owner = User::factory()->create(['role' => 'student', 'email' => 'ahmed@example.com', 'password' => Hash::make('Owner-Pass-1')]);
        $owner->createToken('keep-me');

        $this->convert($this->admin(), $this->accepted(), $dept)->assertCreated()->assertJsonPath('data.credentials_reissued', false);

        $this->assertTrue(Hash::check('Owner-Pass-1', $owner->fresh()->password));
        $this->assertSame(1, $owner->tokens()->count());
        Mail::assertSent(VolunteerAcceptedMail::class, fn ($m) => ! $m->hasTemporaryPassword());
    }

    public function test_an_unverified_privileged_account_is_only_linked_never_re_credentialled(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'ahmed@example.com', 'email_verified_at' => null, 'password' => Hash::make('Admin-Pass-1')]);

        $this->convert($this->admin(), $this->accepted(), $dept)->assertCreated()->assertJsonPath('data.credentials_reissued', false);

        $this->assertTrue(Hash::check('Admin-Pass-1', $admin->fresh()->password));
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_an_already_flagged_verified_account_stays_as_it_is(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $user = User::factory()->create(['role' => 'volunteer', 'email' => 'ahmed@example.com', 'must_change_password' => true, 'password' => Hash::make('Their-Temp-1')]);

        $this->convert($this->admin(), $this->accepted(), $dept)->assertCreated()->assertJsonPath('data.account_existing', true);

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('Their-Temp-1', $user->password), 'a verified owner keeps what they were given');
        $this->assertSame('department_member', $user->role);
        Mail::assertSent(VolunteerAcceptedMail::class, fn ($m) => ! $m->hasTemporaryPassword());
    }

    public function test_a_suspended_account_is_refused_and_nothing_is_persisted(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $user = User::factory()->create(['role' => 'student', 'email' => 'ahmed@example.com', 'is_active' => false]);
        $vr = $this->accepted();

        $res = $this->convert($this->admin(), $vr, $dept)->assertStatus(422)->assertJsonPath('success', false);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $res->json('message'));

        $this->assertSame('student', $user->fresh()->role);
        $this->assertSame(0, TeamProfile::count());
        $this->assertSame(0, TeamMember::count());
        $this->assertSame(0, VolunteerProfile::count());
        $this->assertSame(0, DB::table('user_departments')->count());
        $this->assertSame('accepted', $vr->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_an_empty_e_mail_is_a_422_in_arabic(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');

        foreach (['', '   '] as $email) {
            $vr = $this->accepted(['email' => $email]);
            $res = $this->convert($this->admin(), $vr, $dept)->assertStatus(422);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $res->json('message'));
            $this->assertSame('accepted', $vr->fresh()->status);
        }
        $this->assertSame(0, TeamProfile::count());
    }

    // ── resend restrictions and limits ───────────────────────────────────────

    public function test_resend_is_refused_for_any_account_that_is_not_a_department_member(): void
    {
        Mail::fake();
        $hr = $this->admin();

        foreach (['instructor', 'admin', 'super_admin', 'partner', 'student'] as $i => $role) {
            [$vr, $user] = $this->convertedDirect($i + 1, $role);
            $hash = $user->password;

            $res = $this->resend($hr, $vr)->assertStatus(422);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $res->json('message'));
            $this->assertSame($hash, $user->fresh()->password, "{$role}: password untouched");
        }
        Mail::assertNothingSent();

        // a suspended department member is refused too
        [$vr, $user] = $this->convertedDirect(20);
        $user->forceFill(['is_active' => false])->save();
        $this->resend($hr, $vr)->assertStatus(422);
    }

    public function test_resend_is_limited_to_one_per_ten_minutes_and_ten_per_day_per_target(): void
    {
        Mail::fake();
        [$vr] = $this->convertedDirect(1);
        [$other] = $this->convertedDirect(2);
        $hr = $this->admin();

        $this->resend($hr, $vr)->assertOk();
        $this->resend($hr, $vr)->assertStatus(429);
        $this->resend($this->admin(), $vr)->assertStatus(429);              // whoever asks: the limit is about the target
        $this->resend($hr, $other)->assertOk();                             // another target is not affected

        for ($i = 0; $i < 9; $i++) {                                        // 10 successful sends in all for $vr, 11 minutes apart
            $this->travel(11)->minutes();
            $this->resend($hr, $vr)->assertOk();
        }
        $this->travel(11)->minutes();
        $this->resend($hr, $vr)->assertStatus(429);                          // the daily cap
    }

    public function test_resend_is_limited_to_twenty_per_hour_per_actor(): void
    {
        Mail::fake();
        $hr = $this->admin();

        foreach (range(1, 20) as $i) {
            [$vr] = $this->convertedDirect($i);
            $this->resend($hr, $vr)->assertOk();
        }

        [$vr] = $this->convertedDirect(21);
        $this->resend($hr, $vr)->assertStatus(429);
        $this->resend($this->admin(), $vr)->assertOk();                      // another HR user has their own allowance
    }

    // ── one placement rule ───────────────────────────────────────────────────

    public function test_an_inactive_team_profile_and_seat_are_reactivated(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $user = User::factory()->create(['role' => 'student', 'email' => 'ahmed@example.com']);
        $profile = TeamProfile::create(['user_id' => $user->id, 'name_ar' => 'Ahmed', 'position_ar' => 'x', 'department_id' => $dept->id, 'is_active' => false, 'sort_order' => 1]);
        $seat = TeamMember::create(['user_id' => $user->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'status' => 'inactive', 'role_title' => 'x']);

        $this->convert($this->admin(), $this->accepted(), $dept)->assertCreated()->assertJsonPath('data.is_active', true);

        $this->assertTrue($profile->fresh()->is_active);
        $this->assertSame('active', $seat->fresh()->status);
        $this->assertSame(1, TeamMember::count());
    }

    public function test_an_existing_seat_row_is_kept_and_not_duplicated(): void
    {
        Mail::fake();
        $chosen = $this->dept('التقنية');
        $a = $this->dept('الإعلام');
        $user = User::factory()->create(['role' => 'student', 'email' => 'ahmed@example.com']);
        $profile = TeamProfile::create(['user_id' => $user->id, 'name_ar' => 'Ahmed', 'position_ar' => 'x', 'department_id' => null, 'sort_order' => 1]);
        // seat in A while the profile row has no department: the seat is a placement too, and it wins
        $seat = TeamMember::create(['user_id' => $user->id, 'team_profile_id' => $profile->id, 'department_id' => $a->id, 'status' => 'active', 'role_title' => 'x']);

        $this->convert($this->admin(), $this->accepted(), $chosen)->assertCreated()->assertJsonPath('data.department_id', $a->id);

        $this->assertSame(1, TeamMember::count());
        $this->assertSame($a->id, $seat->fresh()->department_id);
        $this->assertSame($a->id, VolunteerProfile::firstOrFail()->department_id);
    }

    public function test_an_account_that_already_has_a_primary_department_never_gets_a_second_primary(): void
    {
        Mail::fake();
        $primary = $this->dept('الإعلام');
        $chosen = $this->dept('التقنية');
        $user = User::factory()->create(['role' => 'student', 'email' => 'ahmed@example.com']);
        $user->departments()->attach($primary->id, ['is_primary' => true]);

        $this->convert($this->admin(), $this->accepted(), $chosen)->assertCreated()->assertJsonPath('data.department_id', $chosen->id);

        $rows = DB::table('user_departments')->where('user_id', $user->id)->orderBy('department_id')->get()->keyBy('department_id');
        $this->assertCount(2, $rows);
        $this->assertTrue((bool) $rows[$primary->id]->is_primary);
        $this->assertFalse((bool) $rows[$chosen->id]->is_primary, 'no second primary');
        $this->assertSame($chosen->id, TeamProfile::firstOrFail()->department_id, 'it had no profile, so the chosen department is the placement');
    }

    // ── hub profile reconciliation ───────────────────────────────────────────

    public function test_an_existing_hub_profile_keeps_its_status_and_department(): void
    {
        Mail::fake();
        $chosen = $this->dept('التقنية');
        $kept = $this->dept('الإعلام');

        foreach (['withdrawn', 'on_leave', 'active'] as $i => $status) {
            $user = User::factory()->create(['role' => 'volunteer', 'email' => "v{$i}@example.com"]);
            $profile = VolunteerProfile::create(['user_id' => $user->id, 'department_id' => $kept->id, 'status' => $status]);

            $this->convert($this->admin(), $this->accepted(['email' => "v{$i}@example.com"]), $chosen)->assertCreated();

            $profile->refresh();
            $this->assertSame($status, $profile->status, "{$status} is left alone");
            $this->assertSame($kept->id, $profile->department_id, 'an existing department is not overwritten');
            $this->assertSame(1, VolunteerProfile::where('user_id', $user->id)->count());
            $this->assertSame(0, VolunteerStatusLog::where('volunteer_profile_id', $profile->id)->count());
        }
    }

    public function test_an_existing_hub_profile_without_a_department_is_filled(): void
    {
        Mail::fake();
        $chosen = $this->dept('التقنية');
        $user = User::factory()->create(['role' => 'volunteer', 'email' => 'ahmed@example.com']);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'department_id' => null, 'status' => 'on_leave']);

        $this->convert($this->admin(), $this->accepted(), $chosen)->assertCreated();

        $this->assertSame($chosen->id, $profile->fresh()->department_id);
        $this->assertSame('on_leave', $profile->fresh()->status);
    }

    public function test_an_inactive_hub_profile_is_reactivated_with_a_status_log(): void
    {
        Mail::fake();
        $chosen = $this->dept('التقنية');
        $hr = $this->admin();
        $user = User::factory()->create(['role' => 'volunteer', 'email' => 'ahmed@example.com']);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'department_id' => $chosen->id, 'status' => 'inactive']);

        $this->convert($hr, $this->accepted(), $chosen)->assertCreated();

        $this->assertSame('active', $profile->fresh()->status);
        $log = VolunteerStatusLog::where('volunteer_profile_id', $profile->id)->firstOrFail();
        $this->assertSame(['inactive', 'active'], [$log->old_status, $log->new_status]);
        $this->assertSame($hr->id, $log->changed_by);
        $this->assertSame($user->id, $log->user_id);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $log->reason);
    }

    // ── hub sync lock ────────────────────────────────────────────────────────

    public function test_the_conversion_releases_the_hub_lock_after_it_commits_and_never_duplicates_a_profile(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $user = User::factory()->create(['role' => 'volunteer', 'email' => 'ahmed@example.com']);

        // the hub sync got there first and made the profile ...
        app(VolunteerHubSyncService::class)->ensureProfiles();
        $this->assertSame(1, VolunteerProfile::where('user_id', $user->id)->count());

        // ... the conversion reconciles it instead of inserting a second one
        $this->convert($this->admin(), $this->accepted(), $dept)->assertCreated();
        $this->assertSame(1, VolunteerProfile::where('user_id', $user->id)->count());

        // a conversion that creates the profile takes the lock and gives it back at commit, so a later sync is not blocked
        $this->convert($this->admin(), $this->accepted(['email' => 'new@example.com']), $dept)->assertCreated();
        $lock = Cache::lock(VolunteerHubSyncService::LOCK_KEY, 5);
        $this->assertTrue($lock->get(), 'the conversion left the hub lock free');
        $lock->release();
        $this->assertFalse(app(VolunteerHubSyncService::class)->ensureProfiles()['skipped_locked']);
    }

    public function test_creating_the_hub_profile_waits_for_a_running_sync_and_then_carries_on(): void
    {
        Mail::fake();
        $dept = $this->dept('التقنية');
        $sync = Cache::lock(VolunteerHubSyncService::LOCK_KEY, 60);
        $this->assertTrue($sync->get());                                    // a sync is running

        $started = microtime(true);
        $this->convert($this->admin(), $this->accepted(), $dept)->assertCreated();

        $this->assertGreaterThanOrEqual(4.5, microtime(true) - $started, 'it waited for the lock (up to 5 s) before creating the row');
        $this->assertSame(1, VolunteerProfile::count());
    }

    // ── round 3: Arabic 429s, failed sends do not burn the per-target budget, converted requests are final ──

    private function assertArabicThrottle($response, ?int $expectedMinutes = null): void
    {
        $response->assertStatus(429);
        $json = $response->json();

        $this->assertFalse($json['success']);
        $this->assertSame('too_many_requests', $json['code']);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $json['message']);
        $this->assertStringNotContainsString('Too many', $json['message']);
        $this->assertStringContainsString('أعد المحاولة بعد', $json['message']);
        $this->assertIsInt($json['retry_after']);
        $this->assertGreaterThan(0, $json['retry_after']);
        $this->assertSame((string) $json['retry_after'], $response->headers->get('Retry-After'));
        if ($expectedMinutes !== null) {
            if ($expectedMinutes > 2) {
                $this->assertStringContainsString((string) $expectedMinutes, $json['message']);
            }
            $this->assertSame($expectedMinutes, (int) ceil($json['retry_after'] / 60));
        }
    }

    public function test_the_resend_429s_are_arabic_with_retry_after(): void
    {
        Mail::fake();
        [$vr] = $this->convertedDirect(1);
        $hr = $this->admin();

        $this->resend($hr, $vr)->assertOk();
        $this->assertArabicThrottle($this->resend($hr, $vr), 10);                   // the 10-minute cooldown on this target

        // a denied role's small bucket is Arabic too
        $student = $this->admin('student');
        foreach (range(1, 10) as $i) {
            $this->resend($student, $vr)->assertStatus(403);
        }
        $this->assertArabicThrottle($this->resend($student, $vr), 1);

        // the hourly cap per actor
        $busy = $this->admin();
        foreach (range(10, 29) as $i) {
            [$other] = $this->convertedDirect($i);
            $this->resend($busy, $other)->assertOk();
        }
        [$last] = $this->convertedDirect(30);
        $this->assertArabicThrottle($this->resend($busy, $last), 60);

        // other routes keep the global English handler
        $this->assertSame('Too many requests. Please slow down and try again.', $this->englishThrottleMessage());
    }

    /** The untouched global 429 handler, on a route that uses the generic `auth` limiter. */
    private function englishThrottleMessage(): string
    {
        $this->app['auth']->forgetGuards();
        for ($i = 0; $i < 7; $i++) {
            $res = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);   // throttle:auth, 5 per minute
            if ($res->status() === 429) {
                return (string) $res->json('message');
            }
        }

        return 'no 429 reached';
    }

    public function test_a_failed_resend_does_not_burn_the_per_target_budget(): void
    {
        [$vr] = $this->convertedDirect(1);
        $hr = $this->admin();

        // twelve failed sends in a row (a mailer that only logs delivers nothing): never throttled by the per-target cooldown or daily cap
        $this->app->instance('env', 'production');
        config(['mail.default' => 'log']);
        for ($i = 0; $i < 12; $i++) {
            $this->resend($hr, $vr)->assertOk()->assertJsonPath('email_sent', false);
        }

        // the first DELIVERED one starts the cooldown
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $this->resend($hr, $vr)->assertOk()->assertJsonPath('email_sent', true);
        $this->assertArabicThrottle($this->resend($hr, $vr));
    }

    public function test_the_daily_cap_counts_only_delivered_resends(): void
    {
        Mail::fake();
        [$vr] = $this->convertedDirect(1);
        $hr = $this->admin();

        foreach (range(1, 10) as $i) {
            $this->resend($hr, $vr)->assertOk();
            $this->travel(11)->minutes();
        }
        $this->assertArabicThrottle($this->resend($hr, $vr));
    }

    public function test_a_converted_request_can_never_change_status(): void
    {
        $hr = $this->admin();
        [$vr] = $this->convertedDirect(1);
        $keys = ['status', 'converted_member_id', 'converted_to_member_at', 'accepted_at', 'accepted_by', 'reviewed_by', 'reviewed_at', 'admin_notes'];
        $snapshot = fn () => \Illuminate\Support\Arr::only($vr->fresh()->getAttributes(), $keys);
        $before = $snapshot();

        foreach (['rejected', 'accepted', 'pending', 'reviewing', 'contacted', 'converted_to_member'] as $status) {
            $this->app['auth']->forgetGuards();
            $res = $this->actingAs($hr, 'sanctum')
                ->patchJson("/api/admin/volunteer-requests/{$vr->id}/status", ['status' => $status, 'admin_notes' => 'محاولة'])
                ->assertStatus(409)
                ->assertJsonPath('success', false)
                ->assertJsonPath('already_converted', true);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $res->json('message'));

            $this->assertSame($before, $snapshot(), "nothing changed after trying {$status}");
        }

        // a legacy row that carries the converted status without a timestamp is just as final
        $legacy = $this->accepted(['email' => 'legacy@example.com', 'status' => 'converted_to_member']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($hr, 'sanctum')->patchJson("/api/admin/volunteer-requests/{$legacy->id}/status", ['status' => 'rejected'])
            ->assertStatus(409)->assertJsonPath('already_converted', true);
        $this->assertSame('converted_to_member', $legacy->fresh()->status);
    }

    public function test_an_unconverted_request_can_still_change_status(): void
    {
        $hr = $this->admin();
        $vr = $this->accepted(['status' => 'pending']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($hr, 'sanctum')->patchJson("/api/admin/volunteer-requests/{$vr->id}/status", ['status' => 'rejected'])->assertOk();
        $this->assertSame('rejected', $vr->fresh()->status);

        $this->app['auth']->forgetGuards();
        $this->actingAs($hr, 'sanctum')->patchJson("/api/admin/volunteer-requests/{$vr->id}/status", ['status' => 'accepted'])->assertOk();
        $this->assertSame('accepted', $vr->fresh()->status);
    }
}
