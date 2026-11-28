<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EmailSettingsController;
use App\Jobs\SendLoggedEmailJob;
use App\Mail\WelcomeMail;
use App\Models\Department;
use App\Models\EmailSetting;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\EmailDeliveryPolicy;
use App\Services\EmailService;
use App\Support\EmailNotificationTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Email notification settings: two independent central controls per type («التفعيل افتراضيًا» and «إيقاف الإرسال للجميع مؤقتًا»), a
 * three-state personal choice (follow / enabled / disabled) limited to the types a person can receive, and ONE decision shared by the
 * sender, the queued job and the preference page (App\Services\EmailDeliveryPolicy). Nothing here sends a real email: the queue and
 * the mailer are faked.
 */
class EmailNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'course_enrollment';   // a type for any person with an account

    private const STAFF = 'assignment_submitted'; // a type for instructors (and the admin tier)

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendLoggedEmailJob::class]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(string $role = 'student', array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true, 'email' => Str::random(8).'@example.test'], $over));
    }

    private function choose(User $user, string $key, string $mode): void
    {
        $this->as($user)->putJson('/api/notifications/email-preferences', ['preferences' => [$key => $mode]])->assertOk();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    private function send(string $key, ?User $user, ?string $email = null): void
    {
        $to = $email ?? $user->email;
        EmailService::dispatch($key, $to, 'اسم', $user?->id, new WelcomeMail('اسم', $to, null), 'موضوع');
    }

    private function log(string $key, string $email): ?NotificationLog
    {
        return NotificationLog::where('type', $key)->where('recipient', $email)->latest('id')->first();
    }

    private function queuedCount(): int
    {
        return Queue::pushed(SendLoggedEmailJob::class)->count();
    }

    private function types(User $user): array
    {
        return collect($this->as($user)->getJson('/api/notifications/email-preferences')->assertOk()->json('data.types'))->keyBy('key')->all();
    }

    private function snapshot(): array
    {
        return NotificationPreference::orderBy('id')->get(['user_id', 'channel', 'type', 'enabled', 'source'])->toArray();
    }

    // ═══ 1. The central default ═══════════════════════════════════════════════

    public function test_turning_the_default_on_starts_the_followers_and_respects_the_ones_who_stopped_it_themselves(): void
    {
        $follower = $this->user();
        $enabler = $this->user();
        $stopper = $this->user();
        $this->choose($enabler, self::KEY, 'enabled');
        $this->choose($stopper, self::KEY, 'disabled');

        // default OFF: the follower stops; an explicit «مفعّل» still applies; the explicit stop stays
        EmailSetting::saveControls(self::KEY, false, null);
        $this->send(self::KEY, $follower);
        $this->send(self::KEY, $enabler);
        $this->send(self::KEY, $stopper);

        $this->assertSame('skipped_disabled', $this->log(self::KEY, $follower->email)->status, 'the central default is a central stop');
        $this->assertSame('central_default_off', $this->log(self::KEY, $follower->email)->payload['skip_reason']);
        $this->assertSame('pending', $this->log(self::KEY, $enabler->email)->status);
        $this->assertSame('skipped_user_preference', $this->log(self::KEY, $stopper->email)->status);
        $this->assertSame(1, $this->queuedCount());

        // default turned ON again: the follower starts receiving NEW events; the explicit stop still stops
        EmailSetting::saveControls(self::KEY, true, null);
        $this->send(self::KEY, $follower);
        $this->send(self::KEY, $stopper);
        $this->assertSame('pending', $this->log(self::KEY, $follower->email)->status);
        $this->assertSame('skipped_user_preference', $this->log(self::KEY, $stopper->email)->status);
        $this->assertSame(2, $this->queuedCount());
    }

    // ═══ 2. The central pause ═════════════════════════════════════════════════

    public function test_the_pause_stops_everybody_even_an_explicit_enabled_and_lifting_it_restores_without_touching_or_replaying_anything(): void
    {
        $follower = $this->user();
        $enabler = $this->user();
        $stopper = $this->user();
        $this->choose($enabler, self::KEY, 'enabled');
        $this->choose($stopper, self::KEY, 'disabled');
        $prefsBefore = $this->snapshot();

        EmailSetting::saveControls(self::KEY, null, true);
        foreach ([$follower, $enabler, $stopper] as $u) {
            $this->send(self::KEY, $u);
            $this->assertSame('skipped_disabled', $this->log(self::KEY, $u->email)->status);
            $this->assertSame('central_paused', $this->log(self::KEY, $u->email)->payload['skip_reason']);
        }
        $this->assertSame(0, $this->queuedCount());
        $this->assertSame($prefsBefore, $this->snapshot(), 'the pause never edits a personal preference');

        // lifting: nothing is sent or queued by the lifting itself (no replay of what was missed)
        $logsBefore = NotificationLog::count();
        EmailSetting::saveControls(self::KEY, null, false);
        $this->assertSame($logsBefore, NotificationLog::count());
        $this->assertSame(0, $this->queuedCount());
        $this->assertSame($prefsBefore, $this->snapshot());

        // and each person is back to their own state for NEW events
        foreach ([$follower, $enabler, $stopper] as $u) {
            $this->send(self::KEY, $u);
        }
        $this->assertSame('pending', NotificationLog::where('recipient', $follower->email)->latest('id')->first()->status);
        $this->assertSame('pending', NotificationLog::where('recipient', $enabler->email)->latest('id')->first()->status);
        $this->assertSame('skipped_user_preference', NotificationLog::where('recipient', $stopper->email)->latest('id')->first()->status);
        $this->assertSame(2, $this->queuedCount());
    }

    public function test_pause_and_default_are_independent_controls(): void
    {
        EmailSetting::saveControls(self::KEY, false, null);
        $row = EmailSetting::where('key', self::KEY)->first();
        $this->assertFalse($row->default_enabled);
        $this->assertFalse($row->paused);
        $this->assertTrue($row->enabled, 'enabled mirrors NOT paused');

        EmailSetting::saveControls(self::KEY, null, true);
        $row = EmailSetting::where('key', self::KEY)->first();
        $this->assertFalse($row->default_enabled, 'pausing leaves the default as it was');
        $this->assertTrue($row->paused);
        $this->assertFalse($row->enabled);

        EmailSetting::saveControls(self::KEY, null, false);
        $row = EmailSetting::where('key', self::KEY)->first();
        $this->assertFalse($row->default_enabled, 'lifting the pause leaves the default as it was');
        $this->assertFalse($row->paused);
    }

    // ═══ 3. A message already waiting in the queue ════════════════════════════

    private function queuedFor(User $user, string $key = self::KEY): NotificationLog
    {
        $this->send($key, $user);
        $log = $this->log($key, $user->email);
        $this->assertSame('pending', $log->status, 'it was queued');

        return $log;
    }

    private function runJob(NotificationLog $log, User $user): NotificationLog
    {
        (new SendLoggedEmailJob(new WelcomeMail('اسم', $user->email, null), $user->email, 'اسم', $log->id))->handle();

        return $log->fresh();
    }

    public function test_a_queued_message_is_skipped_when_it_runs_during_a_pause_and_never_sent(): void
    {
        Mail::fake();
        $u = $this->user();
        $log = $this->queuedFor($u);

        EmailSetting::saveControls(self::KEY, null, true);
        $after = $this->runJob($log, $u);

        $this->assertSame('skipped_disabled', $after->status);
        $this->assertSame('central_paused', $after->payload['skip_reason']);
        $this->assertNull($after->sent_at);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_a_queued_message_still_goes_out_when_nothing_changed(): void
    {
        Mail::fake();
        $u = $this->user();
        $log = $this->queuedFor($u);

        $this->assertSame('sent', $this->runJob($log, $u)->status);
    }

    public function test_a_queued_message_honours_a_personal_choice_made_after_it_was_queued(): void
    {
        Mail::fake();
        $u = $this->user();
        $log = $this->queuedFor($u);

        $this->choose($u, self::KEY, 'disabled');
        $after = $this->runJob($log, $u);

        $this->assertSame('skipped_user_preference', $after->status);
        $this->assertSame('user_disabled', $after->payload['skip_reason']);
        Mail::assertNothingSent();
    }

    public function test_a_queued_message_honours_the_default_turned_off_for_a_follower_but_not_for_an_explicit_enabled(): void
    {
        Mail::fake();
        $follower = $this->user();
        $enabler = $this->user();
        $this->choose($enabler, self::KEY, 'enabled');
        $logFollower = $this->queuedFor($follower);
        $logEnabler = $this->queuedFor($enabler);

        EmailSetting::saveControls(self::KEY, false, null);

        $this->assertSame('skipped_disabled', $this->runJob($logFollower, $follower)->status);
        $this->assertSame('sent', $this->runJob($logEnabler, $enabler)->status);
    }

    public function test_a_queued_staff_message_is_skipped_when_the_recipient_lost_the_role_meanwhile(): void
    {
        Mail::fake();
        $u = $this->user('instructor');
        $log = $this->queuedFor($u, self::STAFF);

        $u->update(['role' => 'student']);
        $after = $this->runJob($log, $u->fresh());

        $this->assertSame('skipped_user_preference', $after->status);
        $this->assertSame('not_eligible', $after->payload['skip_reason']);
        Mail::assertNothingSent();
    }

    // ═══ 4. Who can choose for what ═══════════════════════════════════════════

    public function test_each_account_is_offered_only_the_types_it_can_receive(): void
    {
        $student = $this->types($this->user('student'));
        $instructor = $this->types($this->user('instructor'));
        $admin = $this->types($this->user('admin'));

        $this->assertArrayHasKey('course_enrollment', $student);
        $this->assertArrayNotHasKey('assignment_submitted', $student, 'a student does not receive instructor mail');
        $this->assertArrayNotHasKey('admin_new_registration', $student);
        $this->assertArrayHasKey('assignment_submitted', $instructor);
        $this->assertArrayNotHasKey('admin_new_registration', $instructor);
        $this->assertArrayHasKey('admin_new_registration', $admin);
        $this->assertArrayHasKey('certificate_pdf_failed', $admin);
        $this->assertArrayNotHasKey('certificate_pdf_failed', $student);
    }

    public function test_mandatory_visitor_only_and_one_time_types_are_never_offered(): void
    {
        $all = array_keys($this->types($this->user('admin')) + $this->types($this->user('student')));

        foreach (EmailNotificationTypes::MANDATORY as $key) {
            $this->assertNotContains($key, $all, "{$key} is mandatory");
        }
        foreach (['contact_admin_notification', 'workshop_request_submitted', 'volunteer_confirmation', 'ambassador_confirmation', 'welcome'] as $key) {
            $this->assertNotContains($key, $all, "{$key} has nobody to choose for it");
        }
    }

    public function test_a_user_with_several_roles_gets_the_union_without_duplicates(): void
    {
        // an instructor who also leads a department: the instructor types AND the department-leader types, once each
        $leader = $this->user('instructor');
        Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'قسم', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true, 'leader_id' => $leader->id]);

        $keys = collect($this->as($leader)->getJson('/api/notifications/email-preferences')->assertOk()->json('data.types'))->pluck('key');

        $this->assertContains('assignment_submitted', $keys->all());
        $this->assertContains('volunteer_admin_notification', $keys->all(), 'a department leader receives the application fan-out');
        $this->assertSame($keys->count(), $keys->unique()->count(), 'no type twice');
    }

    public function test_saving_a_type_the_role_cannot_receive_is_refused_on_the_server_and_nothing_is_saved(): void
    {
        $student = $this->user('student');

        $res = $this->as($student)->putJson('/api/notifications/email-preferences', ['preferences' => [self::KEY => 'disabled', 'assignment_submitted' => 'enabled']]);

        $res->assertStatus(422);
        $this->assertArrayHasKey('preferences.assignment_submitted', $res->json('errors'));
        $this->assertSame(0, NotificationPreference::where('user_id', $student->id)->count(), 'the valid entry was not half-applied');
    }

    public function test_mandatory_unknown_and_invalid_choices_are_refused(): void
    {
        $u = $this->user();
        foreach ([['password_reset' => 'disabled'], ['email_verification' => 'disabled'], ['account_created' => 'disabled'], ['no_such_type' => 'enabled'], [self::KEY => 'maybe']] as $body) {
            $this->as($u)->putJson('/api/notifications/email-preferences', ['preferences' => $body])->assertStatus(422);
        }
        $this->assertSame(0, NotificationPreference::where('user_id', $u->id)->count());
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/notifications/email-preferences', ['preferences' => [self::KEY => 'enabled']])->assertUnauthorized();
    }

    public function test_a_choice_is_saved_for_this_account_only_and_follow_removes_it(): void
    {
        $a = $this->user();
        $b = $this->user();
        $this->choose($b, self::KEY, 'disabled');

        $this->choose($a, self::KEY, 'enabled');
        $this->assertSame('enabled', $this->types($a)[self::KEY]['preference']);
        $this->assertSame('disabled', $this->types($b)[self::KEY]['preference'], 'another account is untouched');

        $this->choose($a, self::KEY, 'follow');
        $this->assertSame('follow', $this->types($a)[self::KEY]['preference']);
        $this->assertSame(0, NotificationPreference::where('user_id', $a->id)->where('type', self::KEY)->count());
    }

    // ═══ 5. A role change keeps the choice ════════════════════════════════════

    public function test_a_saved_choice_survives_a_role_change_that_hides_the_type(): void
    {
        $u = $this->user('instructor');
        $this->choose($u, self::STAFF, 'disabled');

        $u->update(['role' => 'student']);
        $this->assertArrayNotHasKey(self::STAFF, $this->types($u->fresh()));
        $this->assertSame(1, NotificationPreference::where('user_id', $u->id)->where('type', self::STAFF)->count(), 'the row is kept');
        $this->as($u->fresh())->putJson('/api/notifications/email-preferences', ['preferences' => [self::STAFF => 'enabled']])->assertStatus(422);
        $this->assertFalse(NotificationPreference::where('user_id', $u->id)->where('type', self::STAFF)->first()->enabled);

        $u->update(['role' => 'instructor']);
        $this->assertSame('disabled', $this->types($u->fresh())[self::STAFF]['preference'], 'it is shown again, as the person left it');
    }

    // ═══ 6. What the page shows is what the sender does ═══════════════════════

    public function test_the_page_shows_the_preference_the_effective_state_and_its_source(): void
    {
        $u = $this->user();
        $this->choose($u, self::KEY, 'enabled');

        $row = $this->types($u)[self::KEY];
        $this->assertSame('enabled', $row['preference']);
        $this->assertTrue($row['effective']['enabled']);
        $this->assertSame('user_enabled', $row['effective']['source']);

        // paused: the preference stays visible, the effective state is «موقوف مؤقتًا من الإدارة»
        EmailSetting::saveControls(self::KEY, null, true);
        $row = $this->types($u)[self::KEY];
        $this->assertSame('enabled', $row['preference'], 'still shown, still saved');
        $this->assertFalse($row['effective']['enabled']);
        $this->assertSame('central_paused', $row['effective']['source']);
        $this->assertSame('موقوف مؤقتًا من الإدارة', $row['effective']['source_label']);
        $this->assertTrue($row['central']['paused']);

        // a choice can still be saved during the pause and applies when it is lifted
        $this->choose($u, self::KEY, 'disabled');
        $this->assertSame('disabled', $this->types($u)[self::KEY]['preference']);
        EmailSetting::saveControls(self::KEY, null, false);
        $this->assertFalse($this->types($u)[self::KEY]['effective']['enabled']);

        // follow + default off
        $this->choose($u, self::KEY, 'follow');
        EmailSetting::saveControls(self::KEY, false, null);
        $row = $this->types($u)[self::KEY];
        $this->assertSame('follow', $row['preference']);
        $this->assertFalse($row['effective']['enabled']);
        $this->assertSame('central_default_off', $row['effective']['source']);
        $this->assertFalse($row['central']['default_enabled']);
    }

    public function test_the_effective_state_shown_always_equals_the_decision_the_sender_makes(): void
    {
        $u = $this->user();
        foreach ([[true, false, 'follow'], [false, false, 'follow'], [false, false, 'enabled'], [true, false, 'disabled'], [true, true, 'enabled'], [false, true, 'follow']] as [$default, $paused, $mode]) {
            EmailSetting::saveControls(self::KEY, $default, $paused);
            $this->choose($u, self::KEY, $mode);
            $shown = $this->types($u)[self::KEY]['effective']['enabled'];
            $this->assertSame(EmailDeliveryPolicy::decide(self::KEY, $u->fresh())['send'], $shown, "default={$default} paused={$paused} mode={$mode}");
        }
    }

    // ═══ 7. Recipients with no account, mandatory mail, in-app ════════════════

    public function test_a_visitor_with_no_account_follows_the_central_setting_only(): void
    {
        $this->send('contact_confirmation', null, 'visitor@example.test');
        $this->assertSame('pending', $this->log('contact_confirmation', 'visitor@example.test')->status);

        EmailSetting::saveControls('contact_confirmation', false, null);
        $this->send('contact_confirmation', null, 'visitor@example.test');
        $this->assertSame('skipped_disabled', $this->log('contact_confirmation', 'visitor@example.test')->status);

        EmailSetting::saveControls('contact_confirmation', true, true);
        $this->send('contact_confirmation', null, 'visitor@example.test');
        $this->assertSame('central_paused', $this->log('contact_confirmation', 'visitor@example.test')->payload['skip_reason']);
    }

    public function test_mandatory_mail_ignores_preferences_and_the_central_default_but_keeps_its_old_admin_stop(): void
    {
        $u = $this->user();
        // even a stray personal row and a default switched off change nothing for mandatory mail
        NotificationPreference::create(['user_id' => $u->id, 'channel' => 'email', 'type' => 'email_verification', 'enabled' => false]);
        EmailSetting::saveControls('email_verification', false, null);

        $this->send('email_verification', $u);
        $this->assertSame('pending', $this->log('email_verification', $u->email)->status);
        $this->assertSame('mandatory', EmailDeliveryPolicy::decide('email_verification', $u)['source']);

        // the rule they always had: the administrator's stop still applies
        EmailSetting::saveControls('email_verification', null, true);
        $this->send('email_verification', $u);
        $this->assertSame('skipped_disabled', $this->log('email_verification', $u->email)->status);
    }

    public function test_the_save_everything_endpoint_cannot_overwrite_an_email_choice_and_in_app_rows_still_save(): void
    {
        $u = $this->user();
        $this->choose($u, self::KEY, 'disabled');

        $this->as($u)->patchJson('/api/notifications/preferences', ['preferences' => [
            ['channel' => 'email', 'type' => self::KEY, 'enabled' => true],
            ['channel' => 'in_app', 'type' => self::KEY, 'enabled' => false],
        ]])->assertOk();

        $this->assertFalse(NotificationPreference::where(['user_id' => $u->id, 'channel' => 'email', 'type' => self::KEY])->first()->enabled, 'the email choice was not clobbered');
        $this->assertFalse(NotificationPreference::where(['user_id' => $u->id, 'channel' => 'in_app', 'type' => self::KEY])->first()->enabled, 'in-app behaves as before');
    }

    // ═══ 8. The administrator page ════════════════════════════════════════════

    public function test_the_admin_list_exposes_the_two_controls_the_class_and_who_receives_it(): void
    {
        EmailSetting::saveControls(self::KEY, false, true);

        $settings = $this->as($this->user('super_admin'))->getJson('/api/admin/email-settings')->assertOk()->json('data.settings');

        $row = $settings[self::KEY];
        $this->assertFalse($row['default_enabled']);
        $this->assertTrue($row['paused']);
        $this->assertFalse($row['enabled'], 'the old single field still reads "allowed to leave at all"');
        $this->assertFalse($row['mandatory']);
        $this->assertTrue($row['user_configurable']);
        $this->assertNotEmpty($row['audience']);
        $this->assertTrue($settings['password_reset']['mandatory']);
        $this->assertFalse($settings['password_reset']['user_configurable']);
        $this->assertFalse($settings['contact_admin_notification']['user_configurable']);
        $this->assertSame(array_keys(EmailSettingsController::LABELS), array_keys($settings));
    }

    public function test_the_admin_saves_each_control_on_its_own_and_the_change_is_audited(): void
    {
        $admin = $this->user('super_admin');

        $this->as($admin)->putJson('/api/admin/email-settings', ['settings' => [self::KEY => ['default_enabled' => false]]])->assertOk();
        $row = EmailSetting::where('key', self::KEY)->first();
        $this->assertFalse($row->default_enabled);
        $this->assertFalse($row->paused);

        $this->putJson('/api/admin/email-settings', ['settings' => [self::KEY => ['paused' => true]]])->assertOk();
        $row = EmailSetting::where('key', self::KEY)->first();
        $this->assertFalse($row->default_enabled, 'the default was not touched by the pause');
        $this->assertTrue($row->paused);

        $this->assertSame(2, DB::table('activity_logs')->where('action', 'email_settings.updated')->where('user_id', $admin->id)->count());
    }

    public function test_the_previous_pages_single_switch_still_works_as_on_equals_flowing_and_off_equals_paused(): void
    {
        $this->as($this->user('super_admin'))->putJson('/api/admin/email-settings', ['settings' => [self::KEY => ['enabled' => false]]])->assertOk();
        $this->assertTrue(EmailSetting::where('key', self::KEY)->first()->paused);

        $this->putJson('/api/admin/email-settings', ['settings' => [self::KEY => ['enabled' => true]]])->assertOk();
        $row = EmailSetting::where('key', self::KEY)->first();
        $this->assertFalse($row->paused);
        $this->assertTrue($row->default_enabled);
    }

    public function test_a_mandatory_type_has_no_default_to_set_but_can_still_be_stopped(): void
    {
        $this->as($this->user('super_admin'));

        $res = $this->putJson('/api/admin/email-settings', ['settings' => ['password_reset' => ['default_enabled' => false]]])->assertStatus(422);
        $this->assertArrayHasKey('settings.password_reset.default_enabled', $res->json('errors'));
        $this->assertTrue(EmailSetting::where('key', 'password_reset')->first()->default_enabled, 'nothing was stored');

        $this->putJson('/api/admin/email-settings', ['settings' => ['password_reset' => ['paused' => true]]])->assertOk();
        $this->assertTrue(EmailSetting::isPaused('password_reset'));
    }

    public function test_only_the_roles_that_could_change_the_settings_before_still_can(): void
    {
        $body = ['settings' => [self::KEY => ['paused' => true]]];

        $this->as($this->user('student'))->getJson('/api/admin/email-settings')->assertForbidden();
        $this->as($this->user('admin'))->getJson('/api/admin/email-settings')->assertOk();
        $this->as($this->user('admin'))->putJson('/api/admin/email-settings', $body)->assertForbidden();
        $this->as($this->user('executive_admin'))->putJson('/api/admin/email-settings', $body)->assertOk();
        $this->assertTrue(EmailSetting::isPaused(self::KEY));
    }

    public function test_the_administrators_test_message_ignores_their_own_choice_but_not_the_pause(): void
    {
        $admin = $this->user('super_admin');
        EmailSetting::saveControls('welcome', false, null);   // default OFF: an ordinary welcome would be skipped

        $this->as($admin)->postJson('/api/admin/email-settings/test')->assertOk();
        $this->assertSame(1, $this->queuedCount(), 'the deliberate test goes out');

        EmailSetting::saveControls('welcome', null, true);
        $this->postJson('/api/admin/email-settings/test')->assertOk();
        $this->assertSame(1, $this->queuedCount(), 'the pause still applies to the test');
    }

    // ═══ 9. Old data ══════════════════════════════════════════════════════════

    public function test_the_email_settings_backfill_keeps_what_was_off_off_and_touches_nothing_else(): void
    {
        // the state BEFORE this change: only `enabled` existed (the migration's own seed rows are reused where they exist)
        $off = EmailSetting::updateOrCreate(['key' => 'course_completion'], ['enabled' => false]);
        $on = EmailSetting::updateOrCreate(['key' => 'assignment_graded'], ['enabled' => true]);
        $sender = EmailSetting::updateOrCreate(['key' => 'global_sender_name'], ['enabled' => true, 'sender_name' => 'X']);
        DB::table('email_settings')->whereIn('id', [$off->id, $on->id, $sender->id])->update(['paused' => false, 'default_enabled' => true]);

        (require base_path('database/migrations/2026_12_02_000001_add_central_controls_to_email_settings.php'))->up();

        $off = EmailSetting::find($off->id);
        $this->assertTrue($off->paused, 'what was switched off still reaches nobody');
        $this->assertFalse($off->default_enabled);
        $this->assertFalse(EmailSetting::find($on->id)->paused);
        $this->assertTrue(EmailSetting::find($on->id)->default_enabled);
        $this->assertFalse(EmailSetting::find($sender->id)->paused);
        $this->assertTrue(EmailSetting::isPaused('course_completion'));
        $this->assertFalse(EmailSetting::isPaused('assignment_graded'));
    }

    public function test_legacy_preferences_are_kept_exactly_as_saved_and_marked_not_replaced(): void
    {
        $u = $this->user();
        $other = $this->user();
        // rows written by the old save-everything page (value cannot tell the user's choice from the page's default)
        foreach (['assignment_graded' => false, 'payment_confirmed' => true] as $type => $enabled) {
            NotificationPreference::create(['user_id' => $u->id, 'channel' => 'email', 'type' => $type, 'enabled' => $enabled]);
        }
        // the assignment page only ever wrote what the user touched: a known choice, not legacy
        NotificationPreference::create(['user_id' => $u->id, 'channel' => 'email', 'type' => 'ticket_assigned', 'enabled' => false]);
        NotificationPreference::create(['user_id' => $u->id, 'channel' => 'in_app', 'type' => 'assignment_graded', 'enabled' => false]);
        DB::table('notification_preferences')->update(['source' => null]);

        (require base_path('database/migrations/2026_12_02_000002_add_source_to_notification_preferences.php'))->up();

        $get = fn (string $channel, string $type) => NotificationPreference::where(['user_id' => $u->id, 'channel' => $channel, 'type' => $type])->first();
        $this->assertSame('legacy', $get('email', 'assignment_graded')->source);
        $this->assertSame('legacy', $get('email', 'payment_confirmed')->source);
        $this->assertNull($get('email', 'ticket_assigned')->source);
        $this->assertNull($get('in_app', 'assignment_graded')->source);
        $this->assertFalse($get('email', 'assignment_graded')->enabled, 'a legacy false stays off');
        $this->assertTrue($get('email', 'payment_confirmed')->enabled, 'a legacy true stays on');
        $this->assertSame(0, NotificationPreference::where('user_id', $other->id)->count());

        // what happens to each: legacy false is DISABLED until the person picks another state
        $types = $this->types($u->fresh());
        $this->assertSame('disabled', $types['assignment_graded']['preference']);
        $this->assertTrue($types['assignment_graded']['legacy']);
        $this->assertFalse($types['assignment_graded']['effective']['enabled']);
        $this->assertSame('enabled', $types['payment_confirmed']['preference']);
        $this->assertTrue($types['payment_confirmed']['legacy']);

        // the person picks «اتباع الإعداد المركزي»: the legacy row goes and the central setting applies again
        $this->choose($u, 'assignment_graded', 'follow');
        $this->assertTrue($this->types($u)['assignment_graded']['effective']['enabled']);
        // or «مفعّل»: no longer legacy
        $this->choose($u, 'payment_confirmed', 'enabled');
        $this->assertFalse($this->types($u)['payment_confirmed']['legacy']);
    }

    // ═══ 10. The registry ═════════════════════════════════════════════════════

    public function test_every_email_type_has_a_recipient_description_and_the_mandatory_ones_exist(): void
    {
        foreach (EmailNotificationTypes::keys() as $key) {
            $this->assertNotSame('المستلم المرتبط بالحدث', EmailNotificationTypes::audienceLabel($key), "{$key} has no audience written down");
        }
        foreach (EmailNotificationTypes::MANDATORY as $key) {
            $this->assertTrue(EmailNotificationTypes::exists($key));
            $this->assertFalse(EmailNotificationTypes::isUserConfigurable($key));
        }
    }

    public function test_the_role_lists_match_the_recipients_the_senders_pick(): void
    {
        // [type => [roles the sender selects]] — mirrors NotificationService / WorkshopRequestController / CertificateGeneratorService /
        // NotifyAdminCertificateFailure; a role added to a sender without the registry would be skipped, so this pins them together.
        $senders = [
            'admin_new_registration' => ['admin', 'super_admin', 'executive_admin'],
            'ambassador_admin_notification' => ['super_admin', 'admin', 'hr_manager'],
            'workshop_request_admin_notification' => ['super_admin', 'admin'],
            'certificate_needs_approval' => ['super_admin', 'admin', 'department_manager', 'programs_manager'],
            'certificate_pdf_failed' => ['super_admin', 'admin', 'tech_admin'],
            'volunteer_admin_notification' => ['super_admin', 'admin', 'executive_admin', 'hr_manager', 'support_agent', 'marketing_manager', 'department_manager', 'finance_manager', 'quality_manager'],
            'assignment_submitted' => ['instructor'],
        ];
        foreach ($senders as $key => $roles) {
            foreach ($roles as $role) {
                $this->assertTrue(EmailNotificationTypes::isEligible($this->user($role), $key), "{$role} is a recipient of {$key}");
            }
            $this->assertFalse(EmailNotificationTypes::isEligible($this->user('student'), $key), "a student is not a recipient of {$key}");
        }
    }

    public function test_an_ineligible_registered_recipient_is_skipped_at_dispatch_and_logged_with_the_reason(): void
    {
        $student = $this->user('student');

        $this->send('certificate_pdf_failed', $student);

        $log = $this->log('certificate_pdf_failed', $student->email);
        $this->assertSame('skipped_user_preference', $log->status);
        $this->assertSame('not_eligible', $log->payload['skip_reason']);
        $this->assertSame(0, $this->queuedCount());
    }

    // ═══ 11. Other channels keep their behaviour ══════════════════════════════

    public function test_the_in_app_channel_is_untouched_by_the_email_controls(): void
    {
        $u = $this->user();
        NotificationPreference::create(['user_id' => $u->id, 'channel' => 'in_app', 'type' => self::KEY, 'enabled' => false]);
        EmailSetting::saveControls(self::KEY, false, true);

        $this->assertFalse(NotificationPreference::isEnabled($u->id, 'in_app', self::KEY), 'the in-app preference is read as before');
        $this->assertTrue(NotificationPreference::isEnabled($u->id, 'in_app', 'something_else'));
        $this->assertSame(1, NotificationPreference::where('user_id', $u->id)->count(), 'the email controls wrote nothing to the in-app rows');
    }

    // ═══ 12. Review findings ══════════════════════════════════════════════════

    public function test_a_default_only_edit_on_a_row_the_old_code_switched_off_does_not_resume_it(): void
    {
        // the old application (or an old admin tab during a rollout) wrote enabled = 0 without the new `paused` column
        DB::table('email_settings')->updateOrInsert(['key' => self::KEY], ['enabled' => false, 'paused' => false, 'default_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(EmailSetting::isPaused(self::KEY), 'it counts as paused');

        $this->as($this->user('super_admin'))->putJson('/api/admin/email-settings', ['settings' => [self::KEY => ['default_enabled' => false]]])->assertOk();

        $this->assertTrue(EmailSetting::isPaused(self::KEY), 'changing only the default never resumes a paused type');
        $this->assertFalse(EmailSetting::where('key', self::KEY)->first()->default_enabled);
    }

    public function test_the_previous_pages_on_switch_resumes_a_paused_type_but_never_resets_a_default_that_is_off(): void
    {
        $this->as($this->user('super_admin'));

        // default OFF, not paused: a stale page sending enabled:true changes nothing
        EmailSetting::saveControls(self::KEY, false, false);
        $this->putJson('/api/admin/email-settings', ['settings' => [self::KEY => ['enabled' => true]]])->assertOk();
        $this->assertFalse(EmailSetting::where('key', self::KEY)->first()->default_enabled, 'the default an administrator turned off stays off');

        // paused: the same switch resumes it for everyone (the old switch was both)
        EmailSetting::saveControls(self::KEY, false, true);
        $this->putJson('/api/admin/email-settings', ['settings' => [self::KEY => ['enabled' => true]]])->assertOk();
        $row = EmailSetting::where('key', self::KEY)->first();
        $this->assertFalse($row->paused);
        $this->assertTrue($row->default_enabled);
    }

    public function test_a_rollback_never_starts_sending_what_was_off_by_default(): void
    {
        EmailSetting::saveControls(self::KEY, false, false);   // off by default, not paused: enabled stays 1
        $this->assertTrue(EmailSetting::where('key', self::KEY)->first()->enabled);

        (require base_path('database/migrations/2026_12_02_000001_add_central_controls_to_email_settings.php'))->down();

        $this->assertSame(0, (int) DB::table('email_settings')->where('key', self::KEY)->value('enabled'), 'the previous application keeps it off');
        $this->assertSame(1, (int) DB::table('email_settings')->where('key', 'assignment_graded')->value('enabled'), 'what was on stays on');
    }

    public function test_a_personal_choice_is_applied_only_to_mail_addressed_to_that_person(): void
    {
        $signedIn = $this->user();
        $this->choose($signedIn, self::KEY, 'disabled');

        // the signed-in user's id with an address somebody ELSE typed: the choice is not theirs to apply
        $this->send(self::KEY, $signedIn, 'someone-else@example.test');
        $this->assertSame('pending', $this->log(self::KEY, 'someone-else@example.test')->status);

        // the same id with their own address: the choice applies
        $this->send(self::KEY, $signedIn);
        $this->assertSame('skipped_user_preference', $this->log(self::KEY, $signedIn->email)->status);
    }

    public function test_an_instructor_by_profile_still_receives_and_chooses_after_a_role_change(): void
    {
        $u = $this->user('programs_manager');
        $this->assertArrayNotHasKey(self::STAFF, $this->types($u));

        \App\Models\Instructor::forceCreate(['user_id' => $u->id, 'name' => 'مدرب']);

        $this->assertArrayHasKey(self::STAFF, $this->types($u->fresh()), 'the sender picks Course->instructor, whatever the role is now');
        $this->send(self::STAFF, $u);
        $this->assertSame('pending', $this->log(self::STAFF, $u->email)->status);
    }

    public function test_resetting_the_preferences_returns_the_email_choices_to_the_central_setting(): void
    {
        $u = $this->user();
        $this->choose($u, self::KEY, 'disabled');

        $this->as($u)->postJson('/api/notifications/preferences/reset')->assertOk();

        $this->assertSame('follow', $this->types($u)[self::KEY]['preference']);
    }

    public function test_malformed_input_is_a_422_never_a_500_and_the_refusal_does_not_reveal_which_types_exist(): void
    {
        $this->as($this->user('super_admin'))->putJson('/api/admin/email-settings', ['settings' => ['password_reset' => 'x']])->assertStatus(422);

        $student = $this->user('student');
        $messages = [];
        foreach (['no_such_type', 'password_reset', 'assignment_submitted'] as $key) {
            $res = $this->as($student)->putJson('/api/notifications/email-preferences', ['preferences' => [$key => 'enabled']])->assertStatus(422);
            $messages[] = $res->json("errors.preferences.{$key}.0");
        }
        $this->assertCount(1, array_unique($messages), 'unknown, mandatory and not-available types get the same answer');

        $many = array_fill_keys(array_map(fn ($i) => "junk_{$i}", range(1, 150)), 'enabled');
        $this->as($student)->putJson('/api/notifications/email-preferences', ['preferences' => $many])->assertStatus(422);
    }
}
