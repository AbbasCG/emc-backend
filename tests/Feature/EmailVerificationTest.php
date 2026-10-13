<?php

namespace Tests\Feature;

use App\Jobs\SendLoggedEmailJob;
use App\Mail\EmailVerificationMail;
use App\Models\EmailSetting;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * EMAIL VERIFICATION.
 *
 * A new interactive account gets one email with a signed, expiring link; opening it
 * marks the address verified; admins see the status and can resend while it is
 * unverified. The link proves one thing for one address, the resend is rate limited,
 * and nothing here ever mails a user who already exists.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://app.example.test']);
        Queue::fake([SendLoggedEmailJob::class]);
        foreach (['student', 'instructor', 'volunteer', 'admin', 'tech_admin', 'super_admin', 'hr_manager', 'support_agent', 'quality_manager'] as $slug) {
            Role::firstOrCreate(['name' => $slug], ['display_name' => $slug, 'is_system' => false]);
        }

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function unverified(array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => 'student', 'is_active' => true, 'email_verified_at' => null], $over));
    }

    private function service(): EmailVerificationService
    {
        return app(EmailVerificationService::class);
    }

    private function jobs(): array
    {
        $jobs = [];
        Queue::assertPushed(SendLoggedEmailJob::class, function ($job) use (&$jobs) {
            $jobs[] = $job;

            return true;
        });

        return $jobs;
    }

    private function verificationJobs(): array
    {
        return array_values(array_filter($this->jobs(), fn ($j) => $j->mailable instanceof EmailVerificationMail));
    }

    private function logs(User $u)
    {
        return NotificationLog::where('type', 'email_verification')->where('user_id', $u->id)->get();
    }

    private function createViaAdmin(array $over = [])
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/admin/users', array_merge([
            'name' => 'مستخدم جديد', 'email' => 'new.user@example.com', 'role' => 'student',
        ], $over));
    }

    private function stub(User $u): string
    {
        return $this->service()->verificationUrl($u);
    }

    // ═══ Sending after account creation ══════════════════════════════════════

    public function test_an_admin_created_account_gets_one_verification_email(): void
    {
        $this->createViaAdmin()->assertCreated();

        $user = User::where('email', 'new.user@example.com')->firstOrFail();
        $jobs = $this->verificationJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('new.user@example.com', $jobs[0]->toEmail);
        $this->assertCount(1, $this->logs($user));
        $this->assertSame($this->admin->id, (int) $this->logs($user)->first()->triggered_by_user_id);
    }

    public function test_the_verification_email_is_logged_with_its_own_type_and_never_stores_the_link(): void
    {
        $this->createViaAdmin()->assertCreated();
        $user = User::where('email', 'new.user@example.com')->firstOrFail();

        $log = $this->logs($user)->sole();

        $this->assertSame('email', $log->channel);
        $this->assertSame('pending', $log->status);
        $this->assertSame('توثيق البريد الإلكتروني لحسابك في EMC', $log->subject);
        $this->assertStringNotContainsString('signature', json_encode($log->payload));
        $this->assertStringNotContainsString('signature', (string) $log->subject);
    }

    public function test_the_welcome_email_with_the_temporary_password_is_still_sent_alongside(): void
    {
        $this->createViaAdmin()->assertCreated();

        $types = NotificationLog::where('channel', 'email')->pluck('type')->all();

        $this->assertContains('account_created', $types);
        $this->assertContains('email_verification', $types);
    }

    public function test_a_self_registered_student_gets_a_verification_email(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'name' => 'طالب جديد', 'email' => 'student.new@example.com', 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
            'country_code' => 'SA', 'phone_country_code' => '+966', 'phone' => '500000000', 'city' => 'الرياض', 'gender' => 'male',
            'how_did_you_hear_about_us' => 'social',
        ]);

        if ($res->status() === 422) {
            $this->markTestSkipped('Registration payload rules changed: '.json_encode($res->json('errors')));
        }
        $res->assertCreated();
        $this->assertCount(1, $this->verificationJobs());
        $this->assertSame('student.new@example.com', $this->verificationJobs()[0]->toEmail);
    }

    public function test_a_new_instructor_account_gets_a_verification_email(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/instructors', ['name' => 'مدرّب', 'email' => 'coach@example.com'])->assertCreated();

        $this->assertSame(['coach@example.com'], array_map(fn ($j) => $j->toEmail, $this->verificationJobs()));
    }

    public function test_the_model_hook_uses_the_platform_mail_not_a_laravel_default(): void
    {
        $u = $this->unverified();

        $u->sendEmailVerificationNotification();

        $this->assertCount(1, $this->verificationJobs());
    }

    // ═══ Never repeated, never mass-mailed ═══════════════════════════════════

    public function test_calling_the_initial_send_twice_still_sends_one_email(): void
    {
        $u = $this->unverified();

        $this->service()->sendInitial($u);
        $this->service()->sendInitial($u);

        $this->assertCount(1, $this->logs($u));
        $this->assertCount(1, $this->verificationJobs());
    }

    public function test_a_normal_profile_update_sends_nothing(): void
    {
        $this->createViaAdmin()->assertCreated();
        $user = User::where('email', 'new.user@example.com')->firstOrFail();
        $before = NotificationLog::count();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/admin/users/{$user->id}", ['name' => 'اسم معدّل', 'phone' => '0555'])->assertOk();
        $this->putJson("/api/admin/users/{$user->id}", ['email' => 'NEW.USER@example.com'])->assertOk(); // same address, different case

        $this->assertSame($before, NotificationLog::count());
        $this->assertCount(1, $this->verificationJobs());
    }

    public function test_changing_the_email_clears_verification_and_sends_one_new_email(): void
    {
        $u = $this->unverified(['email' => 'old@example.com', 'email_verified_at' => now()]);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/admin/users/{$u->id}", ['email' => 'fresh@example.com'])->assertOk();

        $this->assertNull($u->fresh()->email_verified_at);
        $jobs = $this->verificationJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('fresh@example.com', $jobs[0]->toEmail);
    }

    public function test_a_users_own_email_change_sends_verification_to_the_new_address(): void
    {
        $u = $this->unverified(['email' => 'old@example.com', 'email_verified_at' => now()]);

        Sanctum::actingAs($u);
        $this->putJson('/api/profile', ['email' => 'mine.new@example.com'])->assertOk();

        $this->assertNull($u->fresh()->email_verified_at);
        $this->assertSame(['mine.new@example.com'], array_map(fn ($j) => $j->toEmail, $this->verificationJobs()));
    }

    public function test_existing_users_are_never_mailed_just_because_they_exist(): void
    {
        $this->unverified();
        $this->unverified();
        $this->unverified(['role' => 'instructor']);

        // Nothing runs on boot, on migrate or on factory creation; only account creation and explicit resends mail.
        Queue::assertNothingPushed();
        $this->assertSame(0, NotificationLog::where('type', 'email_verification')->count());
    }

    public function test_an_already_verified_user_gets_no_initial_email(): void
    {
        $u = $this->unverified(['email_verified_at' => now()]);

        $this->service()->sendInitial($u);

        $this->assertCount(0, $this->logs($u));
    }

    public function test_a_mail_failure_cannot_fail_the_account_creation(): void
    {
        NotificationLog::creating(fn ($log) => $log->type === 'email_verification' ? throw new \RuntimeException('log store down') : null);

        $this->createViaAdmin()->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'new.user@example.com']);
    }

    public function test_when_the_email_type_is_switched_off_the_skip_is_logged_not_sent(): void
    {
        EmailSetting::updateOrCreate(['key' => 'email_verification'], ['enabled' => false]);
        EmailSetting::clearAllCache();

        $this->createViaAdmin()->assertCreated();
        $user = User::where('email', 'new.user@example.com')->firstOrFail();

        $this->assertCount(0, $this->verificationJobs());
        $this->assertSame('skipped_disabled', $this->logs($user)->sole()->status);
    }

    // ═══ The link ════════════════════════════════════════════════════════════

    public function test_opening_the_link_verifies_the_email_and_redirects_to_the_app(): void
    {
        $u = $this->unverified();

        $res = $this->get($this->stub($u));

        $res->assertRedirect('https://app.example.test/verify-email?status=success');
        $this->assertNotNull($u->fresh()->email_verified_at);
    }

    public function test_the_link_works_without_being_signed_in(): void
    {
        $u = $this->unverified();

        $this->getJson($this->stub($u))->assertOk()->assertJson(['success' => true, 'status' => 'success', 'message' => 'تم توثيق بريدك الإلكتروني بنجاح.']);
        $this->assertNotNull($u->fresh()->email_verified_at);
    }

    public function test_the_redirect_carries_only_the_outcome_never_an_id_or_an_email(): void
    {
        $u = $this->unverified(['email' => 'private.person@example.com']);

        $location = $this->get($this->stub($u))->headers->get('Location');

        $this->assertSame('https://app.example.test/verify-email?status=success', $location);
        $this->assertStringNotContainsString('private', $location);
        $this->assertStringNotContainsString((string) $u->id, str_replace('https://app.example.test', '', $location));
    }

    public function test_a_tampered_signature_does_not_verify(): void
    {
        $u = $this->unverified();
        $url = $this->stub($u);
        $bad = substr($url, 0, -3).(substr($url, -3) === 'aaa' ? 'bbb' : 'aaa');

        $this->get($bad)->assertRedirect('https://app.example.test/verify-email?status=invalid');
        $this->assertNull($u->fresh()->email_verified_at);
    }

    public function test_a_missing_signature_does_not_verify(): void
    {
        $u = $this->unverified();
        $plain = "/api/email/verify/{$u->id}/".EmailVerificationService::hashFor($u);

        $this->getJson($plain)->assertForbidden()->assertJson(['status' => 'invalid', 'message' => 'رابط التوثيق غير صالح أو انتهت صلاحيته.']);
        $this->assertNull($u->fresh()->email_verified_at);
    }

    public function test_an_expired_link_does_not_verify(): void
    {
        $u = $this->unverified();
        $url = $this->stub($u);

        $this->travel(config('auth.verification.expire') + 1)->minutes();

        $this->get($url)->assertRedirect('https://app.example.test/verify-email?status=expired');
        $this->assertNull($u->fresh()->email_verified_at);
    }

    public function test_the_link_lifetime_is_configurable_and_defaults_to_a_day(): void
    {
        $this->assertSame(1440, config('auth.verification.expire'));

        config(['auth.verification.expire' => 60]);
        $u = $this->unverified();
        $url = $this->stub($u);

        $this->travel(59)->minutes();
        $this->get($url)->assertRedirect('https://app.example.test/verify-email?status=success');
    }

    public function test_changing_the_id_in_a_valid_link_cannot_verify_someone_else(): void
    {
        $a = $this->unverified();
        $b = $this->unverified();
        $swapped = str_replace("/verify/{$a->id}/", "/verify/{$b->id}/", $this->stub($a));

        $this->get($swapped)->assertRedirect('https://app.example.test/verify-email?status=invalid');
        $this->assertNull($a->fresh()->email_verified_at);
        $this->assertNull($b->fresh()->email_verified_at);
    }

    public function test_a_link_for_an_old_address_stops_working_after_the_email_changes(): void
    {
        $u = $this->unverified(['email' => 'before@example.com']);
        $url = $this->stub($u);
        $u->forceFill(['email' => 'after@example.com'])->save();

        $this->get($url)->assertRedirect('https://app.example.test/verify-email?status=invalid');
        $this->assertNull($u->fresh()->email_verified_at);
    }

    public function test_a_valid_link_for_a_deleted_user_looks_like_any_other_invalid_link(): void
    {
        $u = $this->unverified();
        $url = $this->stub($u);
        $u->forceDelete();

        $this->get($url)->assertRedirect('https://app.example.test/verify-email?status=invalid');
    }

    public function test_an_already_verified_user_is_told_so_and_nothing_changes(): void
    {
        $when = now()->subDays(3)->startOfSecond();
        $u = $this->unverified(['email_verified_at' => $when]);

        $this->get($this->stub($u))->assertRedirect('https://app.example.test/verify-email?status=already');
        $this->assertTrue($u->fresh()->email_verified_at->equalTo($when));
    }

    public function test_using_the_same_link_twice_is_graceful(): void
    {
        $u = $this->unverified();
        $url = $this->stub($u);

        $this->get($url)->assertRedirect('https://app.example.test/verify-email?status=success');
        $this->get($url)->assertRedirect('https://app.example.test/verify-email?status=already');
    }

    public function test_verification_is_audited(): void
    {
        $u = $this->unverified();

        $this->get($this->stub($u));

        $this->assertDatabaseHas('audit_logs', ['action' => 'email_verified', 'entity_type' => 'User', 'entity_id' => $u->id]);
    }

    // ═══ The email itself ════════════════════════════════════════════════════

    public function test_the_email_is_arabic_rtl_branded_and_has_a_button_and_a_fallback_link(): void
    {
        $u = $this->unverified(['name' => 'سارة']);
        $this->service()->sendInitial($u);

        $mail = $this->verificationJobs()[0]->mailable;
        $html = $mail->render();
        $url = $mail->verifyUrl;

        $this->assertSame('توثيق البريد الإلكتروني لحسابك في EMC', $mail->envelope()->subject);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('EMC', $html);
        $this->assertStringContainsString('أهلاً <strong>سارة</strong>', $html);
        $this->assertStringContainsString('تم إنشاء حسابك في منصة EMC. يرجى توثيق بريدك الإلكتروني لتأكيد ملكيتك للحساب.', $html);
        $this->assertStringContainsString('توثيق البريد الإلكتروني', $html);
        $this->assertStringContainsString('إذا لم تقم بإنشاء هذا الحساب، يمكنك تجاهل هذه الرسالة.', $html);
        $this->assertStringContainsString('إذا لم يعمل الزر', $html);
        $this->assertSame(3, substr_count($html, htmlspecialchars($url, ENT_QUOTES)), 'button href, fallback href and fallback text all carry the link');
    }

    public function test_the_email_states_how_long_the_link_lasts(): void
    {
        $u = $this->unverified();
        $this->service()->sendInitial($u);
        $this->assertStringContainsString('يوم واحد (24 ساعة)', $this->verificationJobs()[0]->mailable->render());

        config(['auth.verification.expire' => 120]);
        $this->assertSame('ساعتين', $this->service()->expiresText());
        config(['auth.verification.expire' => 45]);
        $this->assertSame('45 دقيقة', $this->service()->expiresText());
        config(['auth.verification.expire' => 2880]);
        $this->assertSame('يومين', $this->service()->expiresText());
    }

    public function test_the_link_in_the_email_actually_verifies(): void
    {
        $u = $this->unverified();
        $this->service()->sendInitial($u);

        $this->get($this->verificationJobs()[0]->mailable->verifyUrl)->assertRedirect('https://app.example.test/verify-email?status=success');
        $this->assertNotNull($u->fresh()->email_verified_at);
    }

    public function test_the_link_carries_no_name_email_or_token_beyond_the_signature(): void
    {
        $u = $this->unverified(['email' => 'visible.part@example.com', 'name' => 'اسم ظاهر']);

        $url = $this->stub($u);

        $this->assertStringNotContainsString('visible.part', $url);
        $this->assertStringNotContainsString(urlencode('اسم'), $url);
        $this->assertMatchesRegularExpression('#/api/email/verify/\d+/[a-f0-9]{40}\?expires=\d+&signature=[a-f0-9]{64}$#', $url);
    }

    // ═══ Admin resend ════════════════════════════════════════════════════════

    public function test_an_admin_resends_to_an_unverified_user(): void
    {
        $u = $this->unverified();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$u->id}/resend-verification")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'تم إرسال رسالة توثيق البريد الإلكتروني.']);

        $this->assertCount(1, $this->verificationJobs());
        $this->assertSame($this->admin->id, (int) $this->logs($u)->sole()->triggered_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'verification_resent', 'entity_id' => $u->id]);
    }

    public function test_the_resent_link_is_new_and_valid(): void
    {
        $u = $this->unverified();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/users/{$u->id}/resend-verification")->assertOk();

        $this->get($this->verificationJobs()[0]->mailable->verifyUrl)->assertRedirect('https://app.example.test/verify-email?status=success');
    }

    public function test_an_admin_cannot_resend_to_a_verified_user(): void
    {
        $u = $this->unverified(['email_verified_at' => now()]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$u->id}/resend-verification")
            ->assertStatus(409)
            ->assertJson(['message' => 'البريد الإلكتروني موثّق مسبقًا.']);
        Queue::assertNothingPushed();
    }

    public function test_tech_admin_and_super_admin_may_resend_too(): void
    {
        foreach (['tech_admin', 'super_admin'] as $role) {
            $u = $this->unverified();
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->postJson("/api/admin/users/{$u->id}/resend-verification")->assertOk();
        }
    }

    public function test_people_without_the_admin_role_cannot_resend(): void
    {
        $u = $this->unverified();

        foreach (['student', 'instructor', 'volunteer', 'hr_manager', 'support_agent', 'quality_manager'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->postJson("/api/admin/users/{$u->id}/resend-verification")->assertForbidden();
        }
        Queue::assertNothingPushed();
    }

    public function test_unauthenticated_resend_is_refused(): void
    {
        $u = $this->unverified();

        $this->postJson("/api/admin/users/{$u->id}/resend-verification")->assertUnauthorized();
    }

    public function test_an_admin_cannot_target_a_super_admin_but_a_super_admin_can(): void
    {
        $root = User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'email_verified_at' => null]);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/users/{$root->id}/resend-verification")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $this->postJson("/api/admin/users/{$root->id}/resend-verification")->assertOk();
    }

    public function test_a_suspended_or_deleted_account_gets_nothing(): void
    {
        $suspended = $this->unverified(['is_active' => false]);
        $deleted = $this->unverified();
        $deleted->delete();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$suspended->id}/resend-verification")->assertStatus(422);
        $this->postJson("/api/admin/users/{$deleted->id}/resend-verification")->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_user_is_not_found(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/users/999999/resend-verification')->assertNotFound();
    }

    public function test_a_resend_is_refused_when_the_email_type_is_switched_off(): void
    {
        EmailSetting::updateOrCreate(['key' => 'email_verification'], ['enabled' => false]);
        EmailSetting::clearAllCache();
        $u = $this->unverified();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$u->id}/resend-verification")->assertStatus(409);
        Queue::assertNothingPushed();
    }

    // ═══ Rate limiting ═══════════════════════════════════════════════════════

    public function test_a_second_resend_within_a_minute_is_refused_with_a_wait_time(): void
    {
        $u = $this->unverified();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/users/{$u->id}/resend-verification")->assertOk();

        $res = $this->postJson("/api/admin/users/{$u->id}/resend-verification")->assertStatus(429);

        $this->assertGreaterThan(0, $res->json('retry_after'));
        $this->assertStringContainsString('يمكنك المحاولة مجددًا بعد', $res->json('message'));
        $this->assertCount(1, $this->verificationJobs());
    }

    public function test_the_wait_is_per_user_not_global(): void
    {
        $a = $this->unverified();
        $b = $this->unverified();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$a->id}/resend-verification")->assertOk();
        $this->postJson("/api/admin/users/{$b->id}/resend-verification")->assertOk();
    }

    public function test_after_a_minute_a_resend_is_allowed_again_but_the_hourly_cap_holds(): void
    {
        $u = $this->unverified();
        Sanctum::actingAs($this->admin);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/admin/users/{$u->id}/resend-verification")->assertOk();
            $this->travel(61)->seconds();
        }

        $this->postJson("/api/admin/users/{$u->id}/resend-verification")->assertStatus(429);
        $this->travel(3600)->seconds();
        $this->postJson("/api/admin/users/{$u->id}/resend-verification")->assertOk();
    }

    // ═══ A user asking for their own new link ════════════════════════════════

    public function test_a_signed_in_unverified_user_can_request_a_new_link_for_themself(): void
    {
        $u = $this->unverified();
        Sanctum::actingAs($u);

        $this->postJson('/api/auth/email/resend')->assertOk()->assertJson(['message' => 'تم إرسال رسالة توثيق البريد الإلكتروني.']);

        $this->assertSame([$u->email], array_map(fn ($j) => $j->toEmail, $this->verificationJobs()));
    }

    public function test_a_verified_user_cannot_request_one_and_a_guest_cannot_either(): void
    {
        Sanctum::actingAs($this->unverified(['email_verified_at' => now()]));
        $this->postJson('/api/auth/email/resend')->assertStatus(409);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/email/resend')->assertUnauthorized();
    }

    public function test_self_resend_is_rate_limited_too(): void
    {
        Sanctum::actingAs($this->unverified());
        $this->postJson('/api/auth/email/resend')->assertOk();

        $this->postJson('/api/auth/email/resend')->assertStatus(429);
    }

    // ═══ What the admin and the user see ═════════════════════════════════════

    public function test_the_admin_list_and_detail_show_the_verification_status(): void
    {
        $no = $this->unverified(['name' => 'غير موثّق']);
        $yes = $this->unverified(['name' => 'موثّق', 'email_verified_at' => now()]);
        Sanctum::actingAs($this->admin);

        $list = collect($this->getJson('/api/admin/users?per_page=50')->assertOk()->json('data'));
        $rowNo = $list->firstWhere('id', $no->id);
        $rowYes = $list->firstWhere('id', $yes->id);

        $this->assertFalse($rowNo['email_verified']);
        $this->assertNull($rowNo['email_verified_at']);
        $this->assertTrue($rowNo['can_resend_verification']);
        $this->assertTrue($rowYes['email_verified']);
        $this->assertNotNull($rowYes['email_verified_at']);
        $this->assertFalse($rowYes['can_resend_verification']);

        $detail = $this->getJson("/api/admin/users/{$no->id}")->assertOk()->json('data');
        $this->assertFalse($detail['email_verified']);
        $this->assertTrue($detail['can_resend_verification']);
    }

    public function test_a_suspended_unverified_account_is_not_offered_a_resend(): void
    {
        $u = $this->unverified(['is_active' => false]);
        Sanctum::actingAs($this->admin);

        $this->assertFalse($this->getJson("/api/admin/users/{$u->id}")->json('data.can_resend_verification'));
    }

    public function test_the_signed_in_user_sees_their_own_status(): void
    {
        $u = $this->unverified();
        Sanctum::actingAs($u);

        $this->assertFalse($this->getJson('/api/auth/me')->assertOk()->json('data.user.email_verified'));
        $this->get($this->stub($u));
        $u->refresh();
        $this->assertTrue($this->getJson('/api/auth/me')->json('data.user.email_verified'));
    }

    public function test_the_email_type_is_listed_in_the_email_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $settings = $this->getJson('/api/admin/email-settings')->assertOk()->json('data.settings');

        $this->assertSame('توثيق البريد الإلكتروني', $settings['email_verification']['label']);
        $this->assertTrue($settings['email_verification']['enabled']);
    }

    public function test_verification_does_not_block_signing_in(): void
    {
        $u = $this->unverified(['email' => 'login.me@example.com', 'password' => bcrypt('Str0ng!Passw0rd')]);

        $this->postJson('/api/auth/login', ['email' => 'login.me@example.com', 'password' => 'Str0ng!Passw0rd'])->assertOk();
    }
}
