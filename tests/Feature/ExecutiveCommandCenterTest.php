<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\FinancialRequest;
use App\Models\HrRequest;
use App\Models\LmsSession;
use App\Models\ManualPayment;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\NotificationLog;
use App\Models\Permission;
use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserPageAccessOverride;
use App\Services\EffectivePageAccessService;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\RoleDefaultMap;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Database\Seeders\ExecutiveCommandCenterPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Private executive command center.
 *
 * Two layers, both proven: PAGE ACCESS (catalog key executive.command_center) opens the page;
 * AUTHORIZATION (permission executive.command_center.view, not inherited by any role) is what
 * the API checks. The feed itself is read-only, bounded, source-isolated and free of the
 * sensitive text of the modules it summarizes.
 */
class ExecutiveCommandCenterTest extends TestCase
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

    private function feed(array $query = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->owner);

        return $this->getJson(self::URL.($query ? '?'.http_build_query($query) : ''));
    }

    private function items(array $json, ?string $section = null): array
    {
        $out = [];
        foreach ($json['data']['sections'] as $s) {
            if ($section === null || $s['key'] === $section) {
                array_push($out, ...$s['items']);
            }
        }

        return $out;
    }

    private function ofType(array $json, string $type): array
    {
        return array_values(array_filter($this->items($json), fn ($i) => $i['type'] === $type));
    }

    private function ticket(string $status, array $over = []): Ticket
    {
        return Ticket::forceCreate(array_merge([
            'ticket_number' => 'T-'.uniqid(), 'title' => 'عنوان التذكرة', 'description' => 'SECRET-TICKET-DESC',
            'department_id' => $this->dept()->id, 'created_by_name' => 'مقدّم البلاغ', 'created_by_email' => 'secret.reporter@example.com',
            'status' => $status, 'internal_notes' => 'SECRET-INTERNAL', 'priority' => 'MEDIUM', 'ticket_category' => 'OLD_ISSUE',
        ], $over));
    }

    private function dept(): Department
    {
        return Department::firstOrCreate(['slug' => 'ops'], ['name' => 'ops', 'name_ar' => 'العمليات', 'status' => 'active', 'is_active' => true]);
    }

    private function meeting(string $when, string $status = 'scheduled'): Meeting
    {
        $tz = config('app.booking_timezone');
        $at = CarbonImmutable::parse($when, $tz);

        return Meeting::forceCreate(['title' => 'اجتماع المتابعة', 'department_id' => $this->dept()->id, 'organizer_id' => $this->owner->id,
            'meeting_date' => $at->toDateString(), 'start_time' => $at->format('H:i:s'), 'status' => $status]);
    }

    // ═══ Authorization ═══════════════════════════════════════════════════════

    public function test_unauthenticated_requests_are_refused(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_the_permission_is_not_inherited_by_any_role_and_not_in_the_default_set(): void
    {
        $this->assertNotContains(Permissions::EXECUTIVE_COMMAND_CENTER_VIEW, Permissions::all());
        $this->assertNotNull(Permission::where('name', Permissions::EXECUTIVE_COMMAND_CENTER_VIEW)->first());

        $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', Permissions::EXECUTIVE_COMMAND_CENTER_VIEW))->pluck('name')->all();
        $this->assertSame(['super_admin'], $holders);
    }

    public function test_only_super_admin_holds_it_by_default(): void
    {
        $this->feed()->assertOk();

        foreach (['tech_admin', 'executive_admin', 'admin', 'finance_manager', 'hr_manager', 'quality_manager', 'support_agent', 'operations_manager', 'instructor', 'student', 'volunteer'] as $role) {
            $this->feed([], $this->user($role))->assertForbidden();
        }
    }

    public function test_tech_admin_is_refused_until_explicitly_granted_and_then_allowed(): void
    {
        $tech = $this->user('tech_admin');
        $this->feed([], $tech)->assertForbidden();

        Role::where('name', 'tech_admin')->first()->grantPermission(Permissions::EXECUTIVE_COMMAND_CENTER_VIEW);

        $this->feed([], $tech->fresh())->assertOk();
    }

    public function test_a_custom_role_can_be_granted_and_page_access_alone_gives_no_data(): void
    {
        $role = Role::create(['name' => 'follow_up', 'display_name' => 'follow_up', 'is_system' => false]);
        $u = User::factory()->create(['role' => 'follow_up', 'is_active' => true]);
        UserPageAccessOverride::create(['user_id' => $u->id, 'page_key' => 'executive.command_center', 'state' => 'allow', 'granted_by' => $this->owner->id]);

        $this->assertTrue(app(EffectivePageAccessService::class)->resolveAll($u)['executive.command_center']->allowed);
        $this->feed([], $u)->assertForbidden();

        $role->grantPermission(Permissions::EXECUTIVE_COMMAND_CENTER_VIEW);
        $this->feed([], $u->fresh())->assertOk();
    }

    public function test_it_is_a_catalog_page_with_no_role_default(): void
    {
        $e = PageAccessCatalog::find('executive.command_center');

        $this->assertNotNull($e);
        $this->assertSame('/dashboard/executive/command-center', $e->primaryRoute);
        $this->assertSame('مركز المتابعة التنفيذي', $e->labelAr);
        $this->assertFalse($e->delegatable);
        $this->assertSame(Permissions::EXECUTIVE_COMMAND_CENTER_VIEW, $e->requiredPermission);
        foreach (RoleDefaultMap::all() as $role => $keys) {
            $this->assertNotContains('executive.command_center', $keys, "{$role} must not receive it by default");
        }
    }

    public function test_the_seeder_is_idempotent_and_grants_super_admin_only(): void
    {
        $this->seed(ExecutiveCommandCenterPermissionSeeder::class);
        $this->seed(ExecutiveCommandCenterPermissionSeeder::class);

        $this->assertSame(1, Permission::where('name', Permissions::EXECUTIVE_COMMAND_CENTER_VIEW)->count());
        $this->assertFalse($this->user('tech_admin')->hasPermission(Permissions::EXECUTIVE_COMMAND_CENTER_VIEW));
    }

    // ═══ Shape, validation, limits ═══════════════════════════════════════════

    public function test_response_has_meta_summary_sections_sources_and_capabilities(): void
    {
        $data = $this->feed()->assertOk()->json('data');

        foreach (['meta', 'summary', 'sections', 'sources', 'capabilities'] as $k) {
            $this->assertArrayHasKey($k, $data);
        }
        $this->assertSame(['needs_attention', 'upcoming', 'recent_updates', 'errors'], array_column($data['sections'], 'key'));
        $this->assertSame(['يحتاج انتباه الآن', 'القادم', 'آخر التحديثات', 'مشاكل وتنبيهات'], array_column($data['sections'], 'title'));
        $this->assertSame(['tickets', 'meetings', 'education', 'finance', 'hr', 'quality', 'system'], array_column($data['sources'], 'key'));
        $this->assertSame('today', $data['meta']['range']);
        $this->assertSame('Europe/Amsterdam', $data['meta']['timezone']);
        $this->assertSame(['can_view' => true, 'can_refresh' => true, 'read_only' => true], $data['capabilities']);
        foreach (['needs_attention', 'upcoming', 'recent_updates', 'errors', 'sources_active', 'sources_total'] as $k) {
            $this->assertArrayHasKey($k, $data['summary']);
        }
    }

    public function test_an_empty_platform_returns_empty_sections_not_an_error(): void
    {
        $data = $this->feed(['range' => 'month'])->assertOk()->json('data');

        $this->assertSame(7, $data['summary']['sources_total']);
        foreach ($data['sections'] as $s) {
            $this->assertIsArray($s['items']);
        }
    }

    public function test_bad_parameters_are_rejected(): void
    {
        $this->feed(['range' => 'year'])->assertStatus(422);
        $this->feed(['source' => 'nope'])->assertStatus(422);
        $this->feed(['priority' => 'urgent'])->assertStatus(422);
        $this->feed(['limit' => 500])->assertStatus(422);
    }

    public function test_limits_cap_each_section_but_the_totals_stay_honest(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->ticket('PENDING_APPROVAL');
        }

        $json = $this->feed(['limit' => 3])->assertOk()->json();
        $needs = collect($json['data']['sections'])->firstWhere('key', 'needs_attention');

        $this->assertCount(3, $needs['items']);
        $this->assertGreaterThanOrEqual(10, $needs['total']);
        $this->assertGreaterThanOrEqual(10, $json['data']['summary']['needs_attention']);
        // The collector itself never reads more than its row limit.
        $this->assertLessThanOrEqual(10, count($this->ofType($this->feed(['limit' => 50])->json(), 'ticket_new')));
    }

    public function test_source_and_priority_filters_narrow_the_list_only(): void
    {
        $this->ticket('PENDING_APPROVAL');
        NotificationLog::forceCreate(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'channel' => 'email', 'type' => 'welcome', 'status' => 'failed', 'recipient' => 'x@example.com', 'failed_at' => now()]);

        $only = $this->feed(['source' => 'tickets'])->assertOk()->json();

        $this->assertNotEmpty($this->items($only));
        $this->assertSame(['tickets'], array_values(array_unique(array_column($this->items($only), 'source'))));
        // Totals in the summary are unfiltered.
        $this->assertGreaterThanOrEqual(1, $only['data']['summary']['errors']);

        $crit = $this->feed(['priority' => 'critical'])->json();
        $this->assertSame([], array_values(array_diff(array_unique(array_column($this->items($crit), 'priority')), ['critical'])));
    }

    // ═══ Sources ═════════════════════════════════════════════════════════════

    public function test_tickets_are_normalized(): void
    {
        $new = $this->ticket('PENDING_APPROVAL', ['title' => 'لا يعمل تسجيل الدخول']);
        $late = $this->ticket('IN_PROGRESS', ['expected_resolution_time' => now()->subDay()]);
        $done = $this->ticket('RESOLVED', ['resolved_at' => now(), 'ticket_category' => 'NEW_SUGGESTION']);

        $json = $this->feed()->assertOk()->json();

        $n = collect($this->ofType($json, 'ticket_new'))->firstWhere('id', 'ticket_new-'.$new->id);
        $this->assertSame('tickets', $n['source']);
        $this->assertSame('high', $n['priority']);
        $this->assertSame('عاجل' === $n['priority_label'], false);
        $this->assertSame('مهم', $n['priority_label']);
        $this->assertSame('/dashboard/tickets/'.$new->id, $n['url']);
        $this->assertSame('فتح التذكرة', $n['action_label']);
        $this->assertSame('مقدّم البلاغ', $n['actor']['name']);
        $this->assertNotNull($n['timestamp']);

        $o = collect($this->ofType($json, 'ticket_overdue'))->firstWhere('id', 'ticket_overdue-'.$late->id);
        $this->assertSame('critical', $o['priority']);
        $this->assertNotNull($o['due_at']);

        $this->assertNotEmpty(collect($this->ofType($json, 'suggestion_done'))->firstWhere('id', 'suggestion_done-'.$done->id));
    }

    public function test_upcoming_meetings_missing_reports_and_new_reports(): void
    {
        $tz = config('app.booking_timezone');
        $soon = $this->meeting(now($tz)->addDay()->setTime(23, 0)->format('Y-m-d H:i'));
        $past = $this->meeting(now($tz)->subDay()->setTime(8, 0)->format('Y-m-d H:i'));
        $reported = $this->meeting(now($tz)->subDays(2)->setTime(8, 0)->format('Y-m-d H:i'));
        MeetingReport::forceCreate(['department_id' => $this->dept()->id, 'meeting_id' => $reported->id, 'meeting_date' => now()->subDays(2), 'created_by' => $this->owner->id]);

        $json = $this->feed(['range' => 'week'])->assertOk()->json();

        $up = collect($this->ofType($json, 'meeting_upcoming'))->firstWhere('id', 'meeting_upcoming-'.$soon->id);
        $this->assertSame('meetings', $up['source']);
        $this->assertSame('/dashboard/department/meeting-lounge/'.$soon->id, $up['url']);
        $this->assertNotNull($up['due_at']);
        $this->assertContains($up, $this->items($json, 'upcoming'));

        $missingIds = array_column($this->ofType($json, 'meeting_missing_report'), 'id');
        $this->assertContains('meeting_missing_report-'.$past->id, $missingIds);
        $this->assertNotContains('meeting_missing_report-'.$reported->id, $missingIds, 'a meeting with a report is not missing one');
        $this->assertNotEmpty($this->ofType($json, 'meeting_reports_new'));
    }

    public function test_upcoming_sessions_and_sessions_without_an_instructor(): void
    {
        $tz = config('app.booking_timezone');
        $day = now($tz)->addDay()->toDateString();
        $ok = LmsSession::forceCreate(['title' => 'حصة المحادثة', 'session_date' => $day, 'start_time' => '23:00:00', 'end_time' => '23:59:00', 'status' => 'scheduled', 'instructor_id' => \App\Models\Instructor::create(['user_id' => $this->user('instructor')->id, 'name' => 'مدرب', 'title' => 'Instructor'])->id]);
        $orphan = LmsSession::forceCreate(['title' => 'حصة بلا مدرب', 'session_date' => $day, 'start_time' => '23:30:00', 'end_time' => '23:59:00', 'status' => 'scheduled']);

        $json = $this->feed(['range' => 'week'])->assertOk()->json();

        $this->assertContains('session_upcoming-'.$ok->id, array_column($this->ofType($json, 'session_upcoming'), 'id'));
        $o = collect($this->ofType($json, 'session_no_instructor'))->firstWhere('id', 'session_no_instructor-'.$orphan->id);
        $this->assertSame('high', $o['priority']);
        $this->assertContains($o, $this->items($json, 'needs_attention'));
        $this->assertSame('education', $o['source']);
    }

    public function test_email_failures_are_grouped_by_type_with_an_arabic_label_and_no_recipient(): void
    {
        foreach (range(1, 6) as $i) {
            NotificationLog::forceCreate(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'channel' => 'email', 'type' => 'email_verification', 'status' => 'failed', 'recipient' => "secret{$i}@example.com",
                'subject' => 'SECRET-SUBJECT', 'error_message' => 'SECRET-SMTP-REPLY', 'smtp_response' => 'SECRET-SMTP', 'failed_at' => now()]);
        }

        $body = $this->feed()->assertOk()->getContent();
        $json = json_decode($body, true);
        $row = $this->ofType($json, 'email_failures')[0];

        $this->assertStringContainsString('توثيق البريد الإلكتروني', $row['title']);
        $this->assertStringContainsString('6', $row['title']);
        $this->assertSame('critical', $row['priority']);
        $this->assertSame('system', $row['source']);
        $this->assertContains($row, $this->items($json, 'errors'));
        $this->assertSame('/dashboard/super-admin/email-logs', $row['url']);
        $this->assertStringNotContainsString('secret', strtolower(json_encode($json, JSON_UNESCAPED_UNICODE)));
    }

    public function test_finance_and_hr_pending_items_are_counts_without_titles_or_amounts(): void
    {
        $d = $this->dept();
        FinancialRequest::forceCreate(['requester_id' => $this->owner->id, 'department_id' => $d->id, 'title' => 'SECRET-FIN-TITLE', 'request_type' => 'purchase', 'amount' => 98765.43, 'status' => 'submitted', 'finance_note' => 'SECRET-NOTE', 'submitted_at' => now()]);
        $account = \App\Models\FinanceAccount::forceCreate(['name' => 'main']);
        ManualPayment::forceCreate(['finance_account_id' => $account->id, 'student_id' => $this->owner->id, 'purchasable_type' => 'x', 'purchasable_id' => 1, 'expected_amount' => 123456.78, 'paid_amount' => 123456.78, 'status' => 'pending_review', 'payment_date' => now()->toDateString(), 'notes' => 'SECRET-PAY-NOTE', 'external_reference' => 'SECRET-REF', 'created_by' => $this->owner->id]);
        HrRequest::forceCreate(['department_id' => $d->id, 'skills_and_specs' => 'SECRET-SPECS', 'notes' => 'SECRET-HR-NOTE', 'status' => 'pending', 'required_count' => 3, 'created_by' => $this->owner->id]);

        $json = $this->feed()->assertOk()->json();
        $text = json_encode($json, JSON_UNESCAPED_UNICODE);

        $fin = $this->ofType($json, 'finance_request_pending')[0];
        $this->assertSame('finance', $fin['source']);
        $this->assertStringContainsString('العمليات', $fin['title']);
        $this->assertNotEmpty($this->ofType($json, 'manual_payments_pending'));
        $hr = $this->ofType($json, 'hr_request_pending')[0];
        $this->assertSame('hr', $hr['source']);
        $this->assertSame('/dashboard/hr/incoming-requests', $hr['url']);
        foreach (['SECRET', '98765', '123456'] as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
    }

    public function test_users_are_counted_never_listed(): void
    {
        User::factory()->create(['email' => 'private.new.user@example.com', 'name' => 'اسم خاص', 'email_verified_at' => null, 'is_active' => true]);

        $json = $this->feed()->assertOk()->json();
        $text = json_encode($json, JSON_UNESCAPED_UNICODE);

        $this->assertNotEmpty($this->ofType($json, 'users_new'));
        $this->assertNotEmpty($this->ofType($json, 'users_unverified'));
        $this->assertStringNotContainsString('private.new.user', $text);
        $this->assertStringNotContainsString('اسم خاص', $text);
    }

    public function test_failed_jobs_and_pinned_notifications_surface(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'db', 'queue' => 'emails', 'payload' => '{"secret":"SECRET-PAYLOAD"}', 'exception' => 'SECRET-TRACE', 'failed_at' => now()]);
        PlatformNotification::forceCreate(['user_id' => $this->owner->id, 'type' => 'x', 'title' => 'SECRET-N', 'message' => 'SECRET-M', 'is_read' => false, 'pinned' => true]);

        $json = $this->feed()->assertOk()->json();

        $job = $this->ofType($json, 'failed_jobs')[0];
        $this->assertSame('critical', $job['priority']);
        $this->assertContains($job, $this->items($json, 'errors'));
        $this->assertNotEmpty($this->ofType($json, 'notifications_pinned'));
        $this->assertStringNotContainsString('SECRET', json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    public function test_one_failing_source_does_not_crash_the_page(): void
    {
        $this->ticket('PENDING_APPROVAL');
        Schema::drop('quality_incidents');

        $json = $this->feed()->assertOk()->json();

        $quality = collect($json['data']['sources'])->firstWhere('key', 'quality');
        $this->assertFalse($quality['available']);
        $this->assertContains('الجودة', $quality['unavailable']);
        $this->assertTrue(collect($json['data']['sources'])->firstWhere('key', 'tickets')['available']);
        $warn = $this->ofType($json, 'source_unavailable')[0];
        $this->assertSame('errors', collect($json['data']['sections'])->first(fn ($s) => in_array($warn, $s['items']))['key']);
        $this->assertNotEmpty($this->ofType($json, 'ticket_new'), 'the other sources still load');
        $this->assertSame(6, $json['data']['summary']['sources_active']);
    }

    // ═══ Safety ══════════════════════════════════════════════════════════════

    public function test_every_item_url_is_a_safe_internal_dashboard_path_owned_by_a_catalog_page(): void
    {
        $this->ticket('PENDING_APPROVAL');
        $this->ticket('RESOLVED', ['resolved_at' => now()]);
        $this->meeting(now(config('app.booking_timezone'))->addDay()->setTime(22, 0)->format('Y-m-d H:i'));
        NotificationLog::forceCreate(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'channel' => 'email', 'type' => 'welcome', 'status' => 'failed', 'recipient' => 'x@example.com', 'failed_at' => now()]);
        DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'db', 'queue' => 'q', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        User::factory()->create(['email_verified_at' => null]);

        $items = $this->items($this->feed(['range' => 'month'])->assertOk()->json());
        $this->assertNotEmpty($items);

        foreach ($items as $i) {
            $this->assertMatchesRegularExpression('#^/dashboard/[A-Za-z0-9/_\-]*$#', $i['url'], $i['type']);
            $this->assertStringNotContainsString('//', $i['url']);
            $owned = false;
            foreach (PageAccessCatalog::all() as $e) {
                foreach ($e->routePatterns as $p) {
                    if ($i['url'] === $p || str_starts_with($i['url'], $p.'/')) {
                        $owned = true;
                    }
                }
            }
            $this->assertTrue($owned, "{$i['type']} links to {$i['url']}, which no catalog page owns");
        }
    }

    public function test_labels_are_arabic_and_no_raw_enum_codes_are_shown(): void
    {
        $this->ticket('PENDING_APPROVAL');
        $this->ticket('IN_PROGRESS', ['expected_resolution_time' => now()->subDay()]);
        DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'db', 'queue' => 'q', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        foreach ($this->items($this->feed()->json()) as $i) {
            $this->assertContains($i['priority_label'], ['عاجل', 'مهم', 'متوسط', 'منخفض', 'معلومة']);
            $this->assertDoesNotMatchRegularExpression('/[A-Z]{3,}_[A-Z_]+/', $i['title'].' '.$i['description'].' '.($i['status_label'] ?? '').' '.($i['badge'] ?? ''));
        }
    }

    public function test_the_feed_is_read_only_and_sends_nothing(): void
    {
        $this->ticket('PENDING_APPROVAL');
        $before = [NotificationLog::count(), PlatformNotification::count(), Ticket::count(), User::count()];
        Sanctum::actingAs($this->owner);

        $this->getJson(self::URL)->assertOk();
        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->{$verb}(self::URL)->assertStatus(405);
        }

        $this->assertSame($before, [NotificationLog::count(), PlatformNotification::count(), Ticket::count(), User::count()]);
    }

    public function test_internal_notes_files_and_tokens_never_appear(): void
    {
        $this->ticket('PENDING_APPROVAL');
        $text = json_encode($this->feed(['range' => 'month'])->json(), JSON_UNESCAPED_UNICODE);

        foreach (['SECRET', 'internal_notes', 'password', 'token', 'secret.reporter@example.com'] as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
    }
}
