<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\FinancialRequest;
use App\Models\HrRequest;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\ManualPayment;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SmartOperationalRulesService;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\SmartOperationalRuleConfig as Config;
use Carbon\CarbonImmutable;
use Database\Seeders\ExecutiveCommandCenterPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smart operational rules (detect, score, display) inside the executive command center.
 *
 * The engine is read-only and permission-gated by the command center itself. These tests pin
 * each rule's condition, severity and score band, the stable ids and deduplication, the
 * limits, failure isolation, and that nothing sensitive or actionable leaks out.
 */
class SmartOperationalRulesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/executive/command-center';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ExecutiveCommandCenterPermissionSeeder::class);

        $this->owner = $this->user('super_admin');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /** @param array<string, array<string, mixed>> $overrides */
    private function tune(array $overrides): void
    {
        app()->instance(Config::class, new Config($overrides));
    }

    private function feed(array $query = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->owner);

        return $this->getJson(self::URL.($query ? '?'.http_build_query($query) : ''));
    }

    /** @return array<int, array<string, mixed>> */
    private function alerts(array $json, ?string $rule = null): array
    {
        $out = [];
        foreach ($json['data']['sections'] as $s) {
            foreach ($s['items'] as $i) {
                if (($i['type'] ?? null) === 'operational_alert' && ($rule === null || $i['rule_key'] === $rule)) {
                    $out[] = $i;
                }
            }
        }

        return $out;
    }

    private function allItems(array $json): array
    {
        return array_merge(...array_map(fn ($s) => $s['items'], $json['data']['sections']));
    }

    private function dept(string $slug = 'ops'): Department
    {
        return Department::firstOrCreate(['slug' => $slug], ['name' => $slug, 'name_ar' => 'العمليات', 'status' => 'active', 'is_active' => true]);
    }

    private function ticket(string $status, ?int $assigneeId, $createdAt, array $over = []): Ticket
    {
        $t = Ticket::forceCreate(array_merge([
            'ticket_number' => 'T-'.uniqid(), 'title' => 'عنوان التذكرة', 'description' => 'SECRET-DESC',
            'department_id' => $this->dept()->id, 'created_by_name' => 'مقدّم البلاغ', 'created_by_email' => 'secret.reporter@example.com',
            'status' => $status, 'assigned_to_id' => $assigneeId, 'internal_notes' => 'SECRET-INTERNAL', 'priority' => 'MEDIUM', 'ticket_category' => 'OLD_ISSUE',
        ], $over));
        $t->forceFill(['created_at' => $createdAt])->save();

        return $t;
    }

    private function meeting(string $when, ?int $departmentId = null, string $end = null): Meeting
    {
        $at = CarbonImmutable::parse($when, config('app.booking_timezone'));

        return Meeting::forceCreate(['title' => 'اجتماع المتابعة', 'department_id' => $departmentId, 'organizer_id' => $this->owner->id,
            'meeting_date' => $at->toDateString(), 'start_time' => $at->format('H:i:s'), 'end_time' => $end ?? $at->addHour()->format('H:i:s'), 'status' => 'scheduled']);
    }

    private function failedMail(string $type, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            NotificationLog::forceCreate(['uuid' => (string) Str::uuid(), 'channel' => 'email', 'type' => $type, 'status' => 'failed', 'recipient' => "secret{$i}@example.com",
                'error_message' => 'SECRET-SMTP', 'smtp_response' => 'SECRET-SMTP-REPLY', 'failed_at' => now()]);
        }
    }

    // ═══ Authorization ═══════════════════════════════════════════════════════

    public function test_unauthenticated_and_unauthorized_users_get_no_alerts(): void
    {
        $this->ticket('PENDING_APPROVAL', null, now()->subDay());

        $this->getJson(self::URL)->assertUnauthorized();
        foreach (['student', 'admin', 'executive_admin', 'finance_manager', 'tech_admin'] as $role) {
            $res = $this->feed([], $this->user($role))->assertForbidden();
            $this->assertStringNotContainsString('operational_alert', $res->getContent());
        }
    }

    public function test_the_authorized_owner_receives_smart_alerts_in_the_command_center(): void
    {
        $this->ticket('PENDING_APPROVAL', null, now()->subHours(6));

        $json = $this->feed()->assertOk()->json();

        $this->assertNotEmpty($this->alerts($json));
        $this->assertSame(1, $json['data']['summary']['smart_alerts']);
        $this->assertContains('smart_rules', array_column($json['data']['sources'], 'key'));
        $this->assertSame('smart_rules', $this->alerts($json)[0]['source']);
    }

    public function test_there_is_no_route_of_its_own_for_the_engine(): void
    {
        $this->actingAs($this->owner);
        foreach (['/api/executive/smart-rules', '/api/executive/smart-alerts', '/api/smart-rules'] as $url) {
            $this->getJson($url)->assertNotFound();
        }
    }

    // ═══ Tickets ═════════════════════════════════════════════════════════════

    public function test_an_unassigned_ticket_older_than_the_threshold_raises_a_high_alert(): void
    {
        $old = $this->ticket('PENDING_APPROVAL', null, now()->subHours(6), ['title' => 'لا يعمل الدخول']);
        $fresh = $this->ticket('PENDING_APPROVAL', null, now()->subHour());
        $assigned = $this->ticket('ASSIGNED', $this->owner->id, now()->subHours(10));

        $json = $this->feed()->assertOk()->json();
        $a = collect($this->alerts($json, 'ticket_unassigned_too_long'))->firstWhere('id', "smart-ticket_unassigned_too_long-ticket-{$old->id}");

        $this->assertNotNull($a);
        $this->assertSame('high', $a['severity']);
        $this->assertSame('عاجل', $a['priority_label']);
        $this->assertGreaterThanOrEqual(70, $a['priority_score']);
        $this->assertLessThanOrEqual(89, $a['priority_score']);
        $this->assertSame('tickets', $a['module']);
        $this->assertSame(['type' => 'ticket', 'id' => $old->id, 'title' => 'لا يعمل الدخول'], $a['related_entity']);
        $this->assertSame('/dashboard/tickets/'.$old->id, $a['url']);
        $this->assertSame('فتح التذكرة', $a['action_label']);
        $this->assertStringContainsString('عيّنها', $a['suggested_action']);
        $this->assertStringContainsString('6 ساعات', $a['description']);
        $this->assertNotNull($a['detected_at']);
        $this->assertSame('تنبيه ذكي', $a['badge']);
        $this->assertContains($a, $json['data']['sections'][0]['items'], 'it is in "needs attention"');

        $ids = array_column($this->alerts($json, 'ticket_unassigned_too_long'), 'id');
        $this->assertNotContains("smart-ticket_unassigned_too_long-ticket-{$fresh->id}", $ids);
        $this->assertNotContains("smart-ticket_unassigned_too_long-ticket-{$assigned->id}", $ids);
    }

    public function test_the_smart_alert_replaces_the_plain_new_ticket_card_for_the_same_ticket(): void
    {
        $old = $this->ticket('PENDING_APPROVAL', null, now()->subHours(6));
        $fresh = $this->ticket('PENDING_APPROVAL', null, now()->subHour());

        $ids = array_column($this->allItems($this->feed()->json()), 'id');

        $this->assertNotContains('ticket_new-'.$old->id, $ids, 'one problem, one card');
        $this->assertContains('ticket_new-'.$fresh->id, $ids, 'a ticket below the threshold keeps its plain card');
    }

    public function test_an_overdue_assigned_ticket_is_escalated_to_critical_after_three_days(): void
    {
        $late = $this->ticket('IN_PROGRESS', $this->owner->id, now()->subDays(6), ['expected_resolution_time' => now()->subDays(4)]);
        $soft = $this->ticket('IN_PROGRESS', $this->owner->id, now()->subDays(3), ['expected_resolution_time' => now()->subHours(5)]);
        $unassigned = $this->ticket('PENDING_APPROVAL', null, now()->subDays(5), ['expected_resolution_time' => now()->subDays(1)]);
        $this->ticket('RESOLVED', $this->owner->id, now()->subDays(9), ['expected_resolution_time' => now()->subDays(5)]);

        $by = collect($this->alerts($this->feed()->json(), 'ticket_overdue'))->keyBy('id');

        $this->assertSame('critical', $by["smart-ticket_overdue-ticket-{$late->id}"]['severity']);
        $this->assertSame('حرج', $by["smart-ticket_overdue-ticket-{$late->id}"]['priority_label']);
        $this->assertGreaterThanOrEqual(90, $by["smart-ticket_overdue-ticket-{$late->id}"]['priority_score']);
        $this->assertSame('high', $by["smart-ticket_overdue-ticket-{$soft->id}"]['severity']);
        $this->assertArrayNotHasKey("smart-ticket_overdue-ticket-{$unassigned->id}", $by->all(), 'unassigned tickets belong to the other rule');
        $this->assertCount(2, $by, 'a resolved ticket is never overdue');
    }

    public function test_a_burst_of_new_tickets_raises_an_aggregate_alert(): void
    {
        $this->tune(['many_new_tickets' => ['threshold_count' => 4]]);
        foreach (range(1, 5) as $_) {
            $this->ticket('ASSIGNED', $this->owner->id, now()->subHour());
        }

        $a = $this->alerts($this->feed()->json(), 'many_new_tickets');

        $this->assertCount(1, $a);
        $this->assertSame('smart-many_new_tickets-'.now()->toDateString(), $a[0]['id']);
        $this->assertSame('medium', $a[0]['severity']);
        $this->assertNull($a[0]['related_entity']);
        $this->assertStringContainsString('5 تذكرة', $a[0]['description']);
    }

    // ═══ Meetings ════════════════════════════════════════════════════════════

    public function test_a_meeting_that_ended_without_a_report_raises_an_alert(): void
    {
        $tz = config('app.booking_timezone');
        $missing = $this->meeting(now($tz)->subDays(2)->setTime(8, 0)->format('Y-m-d H:i'), $this->dept()->id);
        $recent = $this->meeting(now($tz)->subHour()->format('Y-m-d H:i'), $this->dept()->id);
        $reported = $this->meeting(now($tz)->subDays(3)->setTime(8, 0)->format('Y-m-d H:i'), $this->dept()->id);
        MeetingReport::forceCreate(['department_id' => $this->dept()->id, 'meeting_id' => $reported->id, 'meeting_date' => now()->subDays(3), 'created_by' => $this->owner->id]);

        $json = $this->feed()->assertOk()->json();
        $ids = array_column($this->alerts($json, 'meeting_report_missing'), 'id');
        $a = collect($this->alerts($json, 'meeting_report_missing'))->firstWhere('id', "smart-meeting_report_missing-meeting-{$missing->id}");

        $this->assertSame(["smart-meeting_report_missing-meeting-{$missing->id}"], $ids);
        $this->assertSame('high', $a['severity']);
        $this->assertSame('/dashboard/department/meeting-lounge/'.$missing->id, $a['url']);
        $this->assertSame('فتح الاجتماع', $a['action_label']);
        $this->assertStringContainsString('التقرير', $a['suggested_action']);
        $this->assertNotContains('meeting_missing_report-'.$missing->id, array_column($this->allItems($json), 'id'));
        $this->assertNotContains("smart-meeting_report_missing-meeting-{$recent->id}", $ids, 'under 24h after it ended');
    }

    public function test_a_soon_meeting_with_no_scope_or_participants_raises_a_medium_alert(): void
    {
        $tz = config('app.booking_timezone');
        $when = now($tz)->addHours(5)->format('Y-m-d H:i');
        $orphan = $this->meeting($when, null, now($tz)->addHours(6)->format('H:i:s'));
        $scoped = $this->meeting($when, $this->dept()->id, now($tz)->addHours(6)->format('H:i:s'));

        $ids = array_column($this->alerts($this->feed()->json(), 'meeting_starts_soon_missing_participants'), 'id');

        $this->assertContains("smart-meeting_starts_soon_missing_participants-meeting-{$orphan->id}", $ids);
        $this->assertNotContains("smart-meeting_starts_soon_missing_participants-meeting-{$scoped->id}", $ids);
    }

    // ═══ Email ═══════════════════════════════════════════════════════════════

    public function test_failed_emails_raise_an_aggregate_alert_and_repeated_types_raise_their_own(): void
    {
        $this->failedMail('email_verification', 12);
        $this->failedMail('welcome', 1);

        $json = $this->feed()->assertOk()->json();
        $recent = $this->alerts($json, 'failed_emails_recent')[0];
        $repeated = $this->alerts($json, 'repeated_email_failure_type');

        $this->assertSame('high', $recent['severity']);
        $this->assertSame('smart-failed_emails_recent-'.now()->toDateString(), $recent['id']);
        $this->assertStringContainsString('13', $recent['description']);
        $this->assertSame('errors', collect($json['data']['sections'])->first(fn ($s) => in_array($recent, $s['items']))['key']);

        $this->assertCount(1, $repeated, 'welcome failed once: below the repeat threshold');
        $this->assertSame('critical', $repeated[0]['severity']);
        $this->assertSame('smart-repeated_email_failure_type-email_type-email_verification', $repeated[0]['id']);
        $this->assertStringContainsString('توثيق البريد الإلكتروني', $repeated[0]['title']);
        $this->assertGreaterThanOrEqual(90, $repeated[0]['priority_score']);
        $this->assertNotContains('email_failures-welcome', array_column($this->allItems($json), 'id'), 'the plain failure card is replaced');
    }

    public function test_a_single_failed_email_is_a_medium_alert(): void
    {
        $this->failedMail('welcome', 1);

        $a = $this->alerts($this->feed()->json(), 'failed_emails_recent');

        $this->assertSame('medium', $a[0]['severity']);
        $this->assertSame([], $this->alerts($this->feed()->json(), 'repeated_email_failure_type'));
    }

    // ═══ Users ═══════════════════════════════════════════════════════════════

    public function test_old_unverified_accounts_raise_an_alert_and_new_ones_do_not(): void
    {
        User::factory()->create(['email_verified_at' => null, 'is_active' => true, 'created_at' => now()->subDays(8), 'email' => 'private.person@example.com', 'name' => 'اسم خاص']);
        User::factory()->create(['email_verified_at' => null, 'is_active' => true, 'created_at' => now()->subDay()]);
        User::factory()->create(['email_verified_at' => null, 'is_active' => false, 'created_at' => now()->subDays(9)]);
        User::factory()->create(['email_verified_at' => now(), 'is_active' => true, 'created_at' => now()->subDays(9)]);

        $json = $this->feed()->assertOk()->json();
        $a = $this->alerts($json, 'unverified_users_old');

        $this->assertCount(1, $a);
        $this->assertStringContainsString('1 حساب', $a[0]['description']);
        $this->assertSame('users', $a[0]['module']);
        $this->assertStringNotContainsString('private.person', json_encode($json));
        $this->assertStringNotContainsString('اسم خاص', json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    public function test_too_many_unverified_accounts_raise_a_second_alert(): void
    {
        $this->tune(['many_unverified_users' => ['threshold_count' => 2]]);
        User::factory()->count(3)->create(['email_verified_at' => null, 'is_active' => true]);

        $a = $this->alerts($this->feed()->json(), 'many_unverified_users');

        $this->assertCount(1, $a);
        $this->assertSame('medium', $a[0]['severity']);
    }

    // ═══ Courses and sessions ════════════════════════════════════════════════

    public function test_an_upcoming_session_without_an_instructor_raises_a_high_alert(): void
    {
        $tz = config('app.booking_timezone');
        $day = now($tz)->addDays(2)->toDateString();
        $instructor = Instructor::create(['user_id' => $this->user('instructor')->id, 'name' => 'مدرب', 'title' => 'Instructor']);
        $orphan = LmsSession::forceCreate(['title' => 'حصة بلا مدرب', 'session_date' => $day, 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'status' => 'scheduled']);
        $ok = LmsSession::forceCreate(['title' => 'حصة', 'session_date' => $day, 'start_time' => '12:00:00', 'end_time' => '13:00:00', 'status' => 'scheduled', 'instructor_id' => $instructor->id]);
        $far = LmsSession::forceCreate(['title' => 'بعيدة', 'session_date' => now($tz)->addDays(20)->toDateString(), 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'status' => 'scheduled']);

        $json = $this->feed()->assertOk()->json();
        $ids = array_column($this->alerts($json, 'upcoming_session_missing_instructor'), 'id');

        $this->assertSame(["smart-upcoming_session_missing_instructor-session-{$orphan->id}"], $ids);
        $this->assertNotContains('session_no_instructor-'.$orphan->id, array_column($this->allItems($json), 'id'));
        $this->assertSame('high', $this->alerts($json, 'upcoming_session_missing_instructor')[0]['severity']);
    }

    public function test_a_course_starting_soon_with_missing_setup_raises_an_alert(): void
    {
        $bad = Course::factory()->create(['start_date' => now()->addDays(2), 'instructor_id' => null, 'description' => '']);
        $good = Course::factory()->create(['start_date' => now()->addDays(2), 'instructor_id' => Instructor::create(['user_id' => $this->user('instructor')->id, 'name' => 'مدرب', 'title' => 'x'])->id]);
        Course::factory()->create(['start_date' => now()->addDays(40), 'instructor_id' => null]);

        $a = $this->alerts($this->feed()->json(), 'course_starting_soon_low_setup');

        $this->assertSame(["smart-course_starting_soon_low_setup-course-{$bad->id}"], array_column($a, 'id'));
        $this->assertSame('high', $a[0]['severity']);
        $this->assertStringContainsString('المدرب', $a[0]['description']);
        $this->assertStringContainsString('الوصف', $a[0]['description']);
    }

    // ═══ Finance and HR ══════════════════════════════════════════════════════

    public function test_a_finance_request_pending_too_long_raises_an_alert_without_amounts_or_titles(): void
    {
        $d = $this->dept();
        $old = FinancialRequest::forceCreate(['requester_id' => $this->owner->id, 'department_id' => $d->id, 'title' => 'SECRET-FIN-TITLE', 'request_type' => 'purchase', 'amount' => 98765.43, 'status' => 'submitted', 'finance_note' => 'SECRET-NOTE', 'submitted_at' => now()->subDays(3)]);
        $fresh = FinancialRequest::forceCreate(['requester_id' => $this->owner->id, 'department_id' => $d->id, 'title' => 'fresh', 'request_type' => 'purchase', 'amount' => 5, 'status' => 'submitted', 'submitted_at' => now()->subHours(3)]);

        $json = $this->feed()->assertOk()->json();
        $a = $this->alerts($json, 'finance_request_pending_too_long');

        $this->assertSame(["smart-finance_request_pending_too_long-financial_request-{$old->id}"], array_column($a, 'id'));
        $this->assertSame('medium', $a[0]['severity']);
        $this->assertStringContainsString('العمليات', $a[0]['description']);
        $this->assertSame('/dashboard/executive/financial-requests', $a[0]['url']);
        $this->assertStringNotContainsString('SECRET', json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('98765', json_encode($json));
    }

    public function test_a_manual_payment_waiting_review_raises_an_aggregate_alert(): void
    {
        $account = \App\Models\FinanceAccount::forceCreate(['name' => 'main']);
        ManualPayment::forceCreate(['finance_account_id' => $account->id, 'student_id' => $this->owner->id, 'purchasable_type' => 'x', 'purchasable_id' => 1, 'expected_amount' => 123456.78, 'paid_amount' => 123456.78,
            'status' => 'pending_review', 'payment_date' => now()->toDateString(), 'notes' => 'SECRET-PAY-NOTE', 'created_by' => $this->owner->id]);

        $json = $this->feed()->assertOk()->json();
        $a = $this->alerts($json, 'manual_payment_pending_review');

        $this->assertCount(1, $a);
        $this->assertSame('/dashboard/finance/manual-payments', $a[0]['url']);
        $this->assertStringNotContainsString('123456', json_encode($json));
        $this->assertNotContains('manual_payments_pending-all', array_column($this->allItems($json), 'id'), 'the plain card is replaced');
    }

    public function test_an_hr_request_pending_too_long_raises_an_alert(): void
    {
        $d = $this->dept();
        $old = HrRequest::forceCreate(['department_id' => $d->id, 'skills_and_specs' => 'SECRET-SPECS', 'notes' => 'SECRET-HR', 'status' => 'pending', 'required_count' => 2, 'created_by' => $this->owner->id]);
        $old->forceFill(['created_at' => now()->subDays(5)])->save();
        $fresh = HrRequest::forceCreate(['department_id' => $d->id, 'skills_and_specs' => 'x', 'status' => 'pending', 'required_count' => 1, 'created_by' => $this->owner->id]);

        $json = $this->feed()->assertOk()->json();

        $this->assertSame(["smart-hr_request_pending_too_long-hr_request-{$old->id}"], array_column($this->alerts($json, 'hr_request_pending_too_long'), 'id'));
        $this->assertNotContains('hr_request_pending-'.$old->id, array_column($this->allItems($json), 'id'));
        $this->assertContains('hr_request_pending-'.$fresh->id, array_column($this->allItems($json), 'id'));
        $this->assertStringNotContainsString('SECRET', json_encode($json));
    }

    public function test_the_quality_review_rule_is_reported_as_skipped_because_no_such_status_exists(): void
    {
        $rules = collect($this->feed()->assertOk()->json('data.smart_rules'));

        $this->assertSame('skipped', $rules->firstWhere('key', 'new_meeting_report_needs_quality_review')['status']);
        $this->assertSame([], $this->alerts($this->feed()->json(), 'new_meeting_report_needs_quality_review'));
    }

    // ═══ Engine behaviour ════════════════════════════════════════════════════

    public function test_one_failing_rule_degrades_that_rule_only(): void
    {
        $this->ticket('PENDING_APPROVAL', null, now()->subHours(6));
        Schema::drop('hr_requests');

        $json = $this->feed()->assertOk()->json();

        $rules = collect($json['data']['smart_rules']);
        $this->assertSame('failed', $rules->firstWhere('key', 'hr_request_pending_too_long')['status']);
        $this->assertSame('ok', $rules->firstWhere('key', 'ticket_unassigned_too_long')['status']);
        $smart = collect($json['data']['sources'])->firstWhere('key', 'smart_rules');
        $this->assertFalse($smart['available']);
        $this->assertContains('طلب موارد بشرية معلق', $smart['unavailable']);
        $this->assertNotEmpty($this->alerts($json, 'ticket_unassigned_too_long'));
        $this->assertContains('source_unavailable-rule-hr_request_pending_too_long', array_column($this->allItems($json), 'id'));
    }

    public function test_ids_are_stable_unique_and_identical_between_requests(): void
    {
        $this->ticket('PENDING_APPROVAL', null, now()->subHours(6));
        $this->failedMail('welcome', 4);
        User::factory()->create(['email_verified_at' => null, 'is_active' => true, 'created_at' => now()->subDays(6)]);

        $first = array_column($this->alerts($this->feed()->json()), 'id');
        $second = array_column($this->alerts($this->feed()->json()), 'id');

        $this->assertNotEmpty($first);
        $this->assertSame($first, array_values(array_unique($first)), 'no duplicate alert in one response');
        $this->assertEqualsCanonicalizing($first, $second);
        foreach ($first as $id) {
            $this->assertMatchesRegularExpression('/^smart-[a-z_]+-([a-z_]+-[A-Za-z0-9_]+|\d{4}-\d{2}-\d{2})$/', $id);
        }
        $all = array_column($this->allItems($this->feed()->json()), 'id');
        $this->assertSame($all, array_values(array_unique($all)), 'no duplicate item of any kind');
    }

    public function test_every_alert_has_a_severity_a_label_and_a_score_inside_its_band(): void
    {
        $this->ticket('PENDING_APPROVAL', null, now()->subHours(6));
        $this->ticket('IN_PROGRESS', $this->owner->id, now()->subDays(6), ['expected_resolution_time' => now()->subDays(4)]);
        $this->failedMail('email_verification', 12);
        User::factory()->create(['email_verified_at' => null, 'is_active' => true, 'created_at' => now()->subDays(6)]);

        $alerts = $this->alerts($this->feed()->json());
        $this->assertGreaterThanOrEqual(4, count($alerts));

        foreach ($alerts as $a) {
            $this->assertArrayHasKey($a['severity'], Config::BANDS);
            [$min, $max] = Config::BANDS[$a['severity']];
            $this->assertGreaterThanOrEqual($min, $a['priority_score'], $a['id']);
            $this->assertLessThanOrEqual($max, $a['priority_score'], $a['id']);
            $this->assertSame(Config::SEVERITY_LABELS[$a['severity']], $a['priority_label']);
            $this->assertSame($a['severity'], $a['priority']);
            $this->assertNotEmpty($a['suggested_action']);
            $this->assertNotEmpty($a['rule_key']);
        }
    }

    public function test_alerts_are_ordered_by_score_and_critical_comes_first(): void
    {
        $this->ticket('PENDING_APPROVAL', null, now()->subHours(6));
        $this->ticket('IN_PROGRESS', $this->owner->id, now()->subDays(6), ['expected_resolution_time' => now()->subDays(4)]);

        $needs = array_values(array_filter($this->feed()->json('data.sections.0.items'), fn ($i) => $i['type'] === 'operational_alert'));

        $this->assertSame('critical', $needs[0]['severity']);
        $scores = array_column($needs, 'priority_score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    public function test_each_rule_is_limited_and_the_engine_has_a_global_cap(): void
    {
        foreach (range(1, 12) as $_) {
            $this->ticket('PENDING_APPROVAL', null, now()->subHours(8));
        }
        $this->assertCount(5, $this->alerts($this->feed(['limit' => 50])->json(), 'ticket_unassigned_too_long'));

        $this->tune(['ticket_unassigned_too_long' => ['limit' => 200]]);
        foreach (range(1, 70) as $_) {
            $this->ticket('PENDING_APPROVAL', null, now()->subHours(8));
        }
        $engine = app(SmartOperationalRulesService::class)->evaluate();

        $this->assertCount(Config::GLOBAL_LIMIT, $engine['alerts']);
        $this->assertSame(array_column($engine['alerts'], 'id'), array_values(array_unique(array_column($engine['alerts'], 'id'))));
    }

    public function test_rules_can_be_disabled_and_thresholds_changed_in_config(): void
    {
        $t = $this->ticket('PENDING_APPROVAL', null, now()->subHours(2));
        $this->assertSame([], $this->alerts($this->feed()->json(), 'ticket_unassigned_too_long'));

        $this->tune(['ticket_unassigned_too_long' => ['threshold_hours' => 1]]);
        $this->assertCount(1, $this->alerts($this->feed()->json(), 'ticket_unassigned_too_long'));

        $this->tune(['ticket_unassigned_too_long' => ['threshold_hours' => 1, 'enabled' => false]]);
        $json = $this->feed()->json();
        $this->assertSame([], $this->alerts($json, 'ticket_unassigned_too_long'));
        $this->assertSame('disabled', collect($json['data']['smart_rules'])->firstWhere('key', 'ticket_unassigned_too_long')['status']);
        $this->assertContains('ticket_new-'.$t->id, array_column($this->allItems($json), 'id'), 'with the rule off the plain card returns');
    }

    public function test_source_filters_reach_smart_alerts_by_source_and_by_module(): void
    {
        $this->ticket('PENDING_APPROVAL', null, now()->subHours(6));
        $this->failedMail('welcome', 4);

        $smart = $this->allItems($this->feed(['source' => 'smart_rules'])->json());
        $this->assertNotEmpty($smart);
        $this->assertSame(['smart_rules'], array_values(array_unique(array_column($smart, 'source'))));

        $tickets = $this->allItems($this->feed(['source' => 'tickets'])->json());
        $this->assertContains('operational_alert', array_column($tickets, 'type'), 'a ticket alert shows under التذاكر');
        $this->assertSame(['tickets'], array_values(array_unique(array_column($tickets, 'module'))));

        $email = $this->allItems($this->feed(['source' => 'email'])->json());
        $this->assertSame(['email'], array_values(array_unique(array_column($email, 'module'))));
    }

    // ═══ Safety ══════════════════════════════════════════════════════════════

    public function test_no_sensitive_field_is_ever_returned(): void
    {
        $this->ticket('PENDING_APPROVAL', null, now()->subHours(6));
        $this->ticket('IN_PROGRESS', $this->owner->id, now()->subDays(6), ['expected_resolution_time' => now()->subDays(4)]);
        $this->failedMail('welcome', 12);

        $text = json_encode($this->feed(['range' => 'month'])->json(), JSON_UNESCAPED_UNICODE);

        foreach (['SECRET', 'secret.reporter@example.com', 'secret0@example.com', 'internal_notes', 'smtp', 'password', 'token', '"_supersedes"', '"_entity"'] as $needle) {
            $this->assertStringNotContainsString($needle, $text, $needle);
        }
    }

    public function test_every_alert_url_is_a_safe_internal_path_owned_by_a_catalog_page(): void
    {
        $tz = config('app.booking_timezone');
        $this->ticket('PENDING_APPROVAL', null, now()->subHours(6));
        $this->ticket('IN_PROGRESS', $this->owner->id, now()->subDays(6), ['expected_resolution_time' => now()->subDays(4)]);
        $this->meeting(now($tz)->subDays(2)->setTime(8, 0)->format('Y-m-d H:i'), $this->dept()->id);
        $this->failedMail('welcome', 4);
        User::factory()->create(['email_verified_at' => null, 'is_active' => true, 'created_at' => now()->subDays(6)]);
        LmsSession::forceCreate(['title' => 'x', 'session_date' => now($tz)->addDays(2)->toDateString(), 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'status' => 'scheduled']);
        Course::factory()->create(['start_date' => now()->addDays(2), 'instructor_id' => null, 'description' => '']);

        $alerts = $this->alerts($this->feed(['range' => 'month'])->json());
        $this->assertGreaterThanOrEqual(6, count($alerts));

        foreach ($alerts as $a) {
            $this->assertMatchesRegularExpression('#^/dashboard/[A-Za-z0-9/_\-]*$#', $a['url'], $a['rule_key']);
            $this->assertStringNotContainsString('//', $a['url']);
            $owned = false;
            foreach (PageAccessCatalog::all() as $e) {
                foreach ($e->routePatterns as $p) {
                    $owned = $owned || $a['url'] === $p || str_starts_with($a['url'], $p.'/');
                }
            }
            $this->assertTrue($owned, "{$a['rule_key']} links to {$a['url']}, which no catalog page owns");
        }
    }

    public function test_the_engine_is_detection_only_and_changes_and_sends_nothing(): void
    {
        $t = $this->ticket('PENDING_APPROVAL', null, now()->subHours(6));
        $this->failedMail('welcome', 4);
        $before = [Ticket::count(), NotificationLog::count(), DB::table('platform_notifications')->count(), DB::table('jobs')->count(), User::count()];
        $assignee = $t->fresh()->assigned_to_id;

        $this->feed()->assertOk();
        $this->feed(['range' => 'month'])->assertOk();

        $this->assertSame($before, [Ticket::count(), NotificationLog::count(), DB::table('platform_notifications')->count(), DB::table('jobs')->count(), User::count()]);
        $this->assertSame($assignee, $t->fresh()->assigned_to_id, 'nothing was auto-assigned');
        $this->assertSame('PENDING_APPROVAL', $t->fresh()->status);
    }
}
