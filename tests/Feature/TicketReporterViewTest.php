<?php

namespace Tests\Feature;

use App\Jobs\SendLoggedEmailJob;
use App\Mail\TicketStatusUpdateMail;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE REPORTER'S READ-ONLY VIEW.
 *
 * The person who submitted a report or suggestion lands on its details page from
 * the "resolved / approved / implemented" notification or email. They must get a
 * whitelisted, public-safe payload — never the operational one — and must not be
 * able to change anything. Staff (global, department leader, assignee) keep the
 * full payload and every workflow capability.
 */
class TicketReporterViewTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_NOTE = 'ملاحظة داخلية سرية للإدارة';
    private const REASSIGN_REASON = 'سبب إعادة التوجيه الداخلي';
    private const APOLOGY = 'اعتذار المكلف الداخلي';
    private const UNRESOLVED_REASON = 'سبب التصعيد الداخلي';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Department $dept;
    private User $leader;
    private User $submitter;
    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Queue::fake([SendLoggedEmailJob::class]);

        $this->dept = Department::create([
            'name' => 'Dept '.Str::random(5), 'name_ar' => 'إدارة التقنية', 'slug' => 'dept-'.Str::random(8),
            'status' => 'active', 'is_active' => true,
        ]);
        $this->leader = $this->user('programs_manager', ['name' => 'قائد الإدارة']);
        $this->dept->update(['leader_id' => $this->leader->id]);
        $this->submitter = $this->user('student', ['name' => 'مقدّم البلاغ', 'email' => 'submitter@example.com']);
        $this->assignee = $this->user('student', ['name' => 'المكلّف السري', 'email' => 'assignee-secret@example.com']);
    }

    private function user(string $role, array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $attrs));
    }

    private function ticket(array $over = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-'.Str::random(8),
            'title' => 'تعطل بوابة الدفع الإلكتروني',
            'description' => 'وصف المشكلة كما كتبه المقدّم',
            'ticket_category' => 'OLD_ISSUE', 'priority' => 'HIGH',
            'department_id' => $this->dept->id,
            'created_by_id' => $this->submitter->id, 'created_by_name' => $this->submitter->name,
            'created_by_email' => $this->submitter->email,
            'approved_by_id' => $this->leader->id,
            'assigned_to_id' => $this->assignee->id,
            'status' => 'RESOLVED',
            'resolved_at' => now(),
            'expected_resolution_time' => now()->addDay(),
            'resolution_summary' => 'تم إصلاح إعدادات البوابة وإعادة تشغيل الخدمة.',
            'internal_notes' => self::SECRET_NOTE,
            'assignee_rejection_reason' => self::APOLOGY,
            'unresolved_reason' => self::UNRESOLVED_REASON,
        ], $over));
    }

    private function log(Ticket $t, string $action, ?string $details = null, ?User $by = null): TicketActivityLog
    {
        return TicketActivityLog::create([
            'ticket_id' => $t->id, 'action' => $action, 'previous_state' => $t->status, 'new_state' => $t->status,
            'performed_by' => ($by ?? $this->leader)->id, 'performed_by_name' => ($by ?? $this->leader)->name,
            'details' => $details, 'logged_at' => now(),
        ]);
    }

    private function attach(Ticket $t, string $name, string $context = 'INITIAL_SUBMISSION'): TicketAttachment
    {
        $path = 'tickets/attachments/'.md5($name).'.bin';
        Storage::disk('private')->put($path, base64_decode(self::PNG));

        return TicketAttachment::create([
            'ticket_id' => $t->id, 'file_url' => $path, 'file_name' => $name, 'file_type' => 'IMAGE',
            'mime_type' => 'image/png', 'file_size' => 70, 'attachment_context' => $context,
            'uploaded_by' => $t->created_by_id,
        ]);
    }

    /**
     * The response as readable text. getContent() escapes Arabic to \uXXXX, which
     * would make every "must not contain" assertion pass vacuously.
     */
    private function text($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_UNICODE);
    }

    private function show(Ticket $t, User $as)
    {
        Sanctum::actingAs($as);

        return $this->getJson("/api/v1/tickets/{$t->id}");
    }

    // ═══ The reporter payload ═══════════════════════════════════════════════

    public function test_the_reporter_opens_their_resolved_ticket_and_gets_the_reporter_view(): void
    {
        $t = $this->ticket();

        $res = $this->show($t, $this->submitter)->assertOk();

        $res->assertJsonPath('data.viewer_role', 'reporter')
            ->assertJsonPath('data.id', $t->id)
            ->assertJsonPath('data.ticket_number', $t->ticket_number)
            ->assertJsonPath('data.title', 'تعطل بوابة الدفع الإلكتروني')
            ->assertJsonPath('data.description', 'وصف المشكلة كما كتبه المقدّم')
            ->assertJsonPath('data.status', 'RESOLVED')
            ->assertJsonPath('data.ticket_category', 'OLD_ISSUE')
            ->assertJsonPath('data.department.name', 'إدارة التقنية');
    }

    public function test_the_reporter_payload_is_a_whitelist_and_leaks_no_internal_data(): void
    {
        $t = $this->ticket();
        $this->log($t, 'SUBMITTED', 'تم إنشاء التذكرة بنجاح');
        $this->log($t, 'APPROVED_AND_ASSIGNED', 'تم اعتماد التذكرة وإسنادها. مهلة الحل: 24 ساعة');
        $this->log($t, 'REASSIGNED', self::REASSIGN_REASON);
        $this->log($t, 'REJECTED_BY_ASSIGNEE', self::APOLOGY);
        $this->log($t, 'INTERNAL_NOTE', self::SECRET_NOTE);
        $this->log($t, 'RESOLVED', 'تفصيل داخلي عن الحل', $this->assignee);

        $body = $this->text($this->show($t, $this->submitter)->assertOk());

        foreach ([
            self::SECRET_NOTE, self::REASSIGN_REASON, self::APOLOGY, self::UNRESOLVED_REASON,
            'assignee-secret@example.com', 'المكلّف السري', 'قائد الإدارة', 'submitter@example.com',
            'مهلة الحل', 'تفصيل داخلي عن الحل',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "leaked: {$secret}");
        }

        $data = $this->show($t, $this->submitter)->json('data');
        foreach ([
            'internal_notes', 'created_by_email', 'assignee', 'approver', 'creator', 'activity_logs', 'unit',
            'assigned_to_id', 'approved_by_id', 'expected_resolution_time', 'is_delayed', 'priority',
            'assignee_rejection_reason', 'unresolved_reason', 'admin_rejection_reason', 'resolution_summary',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $data, "reporter payload must not carry {$key}");
        }
    }

    public function test_every_workflow_capability_is_off_for_the_reporter(): void
    {
        $t = $this->ticket(['status' => 'PENDING_APPROVAL']);

        $caps = $this->show($t, $this->submitter)->json('data.capabilities');

        foreach (['approve', 'reject', 'reassign', 'accept_task', 'reject_task', 'complete_task', 'add_internal_note'] as $cap) {
            $this->assertFalse($caps[$cap], "{$cap} must be false for a reporter");
        }
    }

    public function test_the_resolution_note_is_shown_when_resolved_and_absent_otherwise(): void
    {
        $resolved = $this->ticket();
        $this->assertSame('تم إصلاح إعدادات البوابة وإعادة تشغيل الخدمة.', $this->show($resolved, $this->submitter)->json('data.resolution_note'));

        $open = $this->ticket(['status' => 'IN_PROGRESS', 'resolved_at' => null, 'resolution_summary' => 'مسودة']);
        $this->assertNull($this->show($open, $this->submitter)->json('data.resolution_note'));

        $noNote = $this->ticket(['resolution_summary' => null]);
        $this->assertNull($this->show($noNote, $this->submitter)->json('data.resolution_note'));
    }

    public function test_a_rejection_reason_for_the_submitter_is_shown_but_other_internal_reasons_are_not(): void
    {
        $t = $this->ticket(['status' => 'REJECTED_BY_ADMIN', 'admin_rejection_reason' => 'الطلب خارج نطاق الخدمة', 'resolved_at' => null]);

        $res = $this->show($t, $this->submitter);

        $this->assertSame('الطلب خارج نطاق الخدمة', $res->json('data.rejection_reason'));
        $this->assertStringNotContainsString(self::APOLOGY, $this->text($res));
        $this->assertStringNotContainsString(self::UNRESOLVED_REASON, $this->text($res));
    }

    public function test_an_unresolved_escalation_reason_never_reaches_the_reporter(): void
    {
        $t = $this->ticket(['status' => 'UNRESOLVED', 'resolved_at' => null]);
        $this->log($t, 'MARKED_UNRESOLVED', self::UNRESOLVED_REASON, $this->assignee);

        $res = $this->show($t, $this->submitter)->assertOk();

        $this->assertStringNotContainsString(self::UNRESOLVED_REASON, $this->text($res));
        $this->assertContains('escalated', array_column($res->json('data.timeline'), 'key'));
    }

    // ═══ Timeline ═══════════════════════════════════════════════════════════

    public function test_the_timeline_is_simplified_ordered_and_has_no_system_noise(): void
    {
        $t = $this->ticket();
        $this->log($t, 'SUBMITTED', 'تم إنشاء التذكرة');
        $this->log($t, 'APPROVED_AND_ASSIGNED', 'x');
        $this->log($t, 'REASSIGNED', self::REASSIGN_REASON);
        $this->log($t, 'INTERNAL_NOTE', self::SECRET_NOTE);
        $this->log($t, 'COMMENT', 'تعقيب من أحد الموظفين', $this->leader);
        $this->log($t, 'ACCEPTED_BY_ASSIGNEE', 'x', $this->assignee);
        $this->log($t, 'RESOLVED', 'x', $this->assignee);

        $keys = array_column($this->show($t, $this->submitter)->json('data.timeline'), 'key');

        $this->assertSame(['submitted', 'approved', 'in_progress', 'resolved'], $keys);
    }

    public function test_the_timeline_labels_a_suggestion_as_approved_and_implemented(): void
    {
        $s = $this->ticket(['ticket_category' => 'NEW_SUGGESTION', 'title' => 'إضافة وضع ليلي']);
        $this->log($s, 'APPROVED_AND_ASSIGNED', 'x');
        $this->log($s, 'RESOLVED', 'x', $this->assignee);

        $labels = array_column($this->show($s, $this->submitter)->json('data.timeline'), 'label');

        $this->assertContains('تم تقديم المقترح', $labels);
        $this->assertContains('تم اعتماد المقترح', $labels);
        $this->assertContains('تم تنفيذ المقترح', $labels);
    }

    public function test_a_resolved_ticket_without_log_rows_still_ends_in_a_resolved_step(): void
    {
        $t = $this->ticket();

        $keys = array_column($this->show($t, $this->submitter)->json('data.timeline'), 'key');

        $this->assertSame(['submitted', 'resolved'], $keys);
    }

    public function test_only_the_reporters_own_comments_appear_in_their_timeline(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'resolved_at' => null]);
        $this->log($t, 'COMMENT', 'سؤالي للفريق', $this->submitter);
        $this->log($t, 'COMMENT', 'رد داخلي بين الموظفين', $this->leader);

        $res = $this->show($t, $this->submitter);

        $this->assertStringContainsString('سؤالي للفريق', $this->text($res));
        $this->assertStringNotContainsString('رد داخلي بين الموظفين', $this->text($res));
    }

    // ═══ Attachments ════════════════════════════════════════════════════════

    public function test_the_reporter_sees_their_own_attachments_but_not_resolution_proof(): void
    {
        $t = $this->ticket();
        $mine = $this->attach($t, 'my-shot.png');
        $proof = $this->attach($t, 'internal-proof.png', 'RESOLUTION_PROOF');

        $res = $this->show($t, $this->submitter)->assertOk();
        $ids = array_column($res->json('data.attachments'), 'id');

        $this->assertSame([$mine->id], $ids);
        $this->assertStringNotContainsString('internal-proof.png', $this->text($res));
        $this->assertStringNotContainsString('file_url', $this->text($res));
        $this->assertTrue($res->json('data.attachments.0.is_image'));

        Sanctum::actingAs($this->submitter);
        $this->get("/api/v1/tickets/{$t->id}/attachments/{$mine->id}/download")->assertOk();
        $this->get("/api/v1/tickets/{$t->id}/attachments/{$proof->id}/download")->assertNotFound();
    }

    public function test_staff_can_still_open_resolution_proof_files(): void
    {
        $t = $this->ticket();
        $proof = $this->attach($t, 'internal-proof.png', 'RESOLUTION_PROOF');

        Sanctum::actingAs($this->assignee);
        $this->get("/api/v1/tickets/{$t->id}/attachments/{$proof->id}/download")->assertOk();

        Sanctum::actingAs($this->leader);
        $this->get("/api/v1/tickets/{$t->id}/attachments/{$proof->id}/download")->assertOk();
    }

    // ═══ Read-only: nothing can be changed ══════════════════════════════════

    public function test_the_reporter_cannot_approve_reject_reassign_or_complete(): void
    {
        $t = $this->ticket(['status' => 'PENDING_APPROVAL', 'resolved_at' => null]);
        Sanctum::actingAs($this->submitter);

        $this->postJson("/api/v1/tickets/{$t->id}/approve", ['sla_hours' => 24])->assertForbidden();
        $this->postJson("/api/v1/tickets/{$t->id}/reject", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->submitter->id])->assertForbidden();
        $this->postJson("/api/v1/tickets/{$t->id}/accept-task")->assertForbidden();
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'self resolve'])->assertForbidden();

        $this->assertSame('PENDING_APPROVAL', $t->fresh()->status);
    }

    public function test_the_reporter_cannot_post_an_internal_note(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'resolved_at' => null]);
        Sanctum::actingAs($this->submitter);

        $this->postJson("/api/v1/tickets/{$t->id}/comment", ['comment' => 'x', 'is_internal' => true])->assertForbidden();
    }

    public function test_the_reporter_can_follow_up_while_open_but_not_after_it_is_closed(): void
    {
        $open = $this->ticket(['status' => 'IN_PROGRESS', 'resolved_at' => null]);
        Sanctum::actingAs($this->submitter);
        $this->postJson("/api/v1/tickets/{$open->id}/comment", ['comment' => 'هل من جديد؟'])->assertOk();
        $this->assertTrue($this->show($open, $this->submitter)->json('data.capabilities.comment'));

        $closed = $this->ticket();
        Sanctum::actingAs($this->submitter);
        $this->postJson("/api/v1/tickets/{$closed->id}/comment", ['comment' => 'متأخر'])->assertForbidden();
        $this->assertFalse($this->show($closed, $this->submitter)->json('data.capabilities.comment'));
    }

    public function test_another_user_cannot_open_the_ticket_at_all(): void
    {
        $t = $this->ticket();
        $stranger = $this->user('student');

        $this->show($t, $stranger)->assertForbidden();
    }

    // ═══ Staff are unaffected ═══════════════════════════════════════════════

    public function test_staff_keep_the_full_operational_payload(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'resolved_at' => null]);

        foreach ([$this->assignee, $this->leader, $this->user('super_admin')] as $staff) {
            $data = $this->show($t, $staff)->assertOk()->json('data');

            $this->assertSame('staff', $data['viewer_role']);
            $this->assertSame(self::SECRET_NOTE, $data['internal_notes']);
            $this->assertArrayHasKey('assignee', $data);
            $this->assertArrayHasKey('activity_logs', $data);
            $this->assertArrayHasKey('expected_resolution_time', $data);
        }
    }

    public function test_staff_capabilities_are_unchanged(): void
    {
        $t = $this->ticket(['status' => 'PENDING_APPROVAL', 'resolved_at' => null]);
        $this->assertTrue($this->show($t, $this->leader)->json('data.capabilities.approve'));

        $t2 = $this->ticket(['status' => 'IN_PROGRESS', 'resolved_at' => null]);
        $this->assertTrue($this->show($t2, $this->assignee)->json('data.capabilities.complete_task'));
    }

    public function test_a_submitter_who_is_also_global_staff_keeps_the_staff_view(): void
    {
        $admin = $this->user('super_admin', ['email' => 'boss@example.com']);
        $t = $this->ticket(['created_by_id' => $admin->id, 'created_by_name' => $admin->name]);

        $this->assertSame('staff', $this->show($t, $admin)->json('data.viewer_role'));
    }

    public function test_a_submitter_who_leads_the_department_keeps_the_staff_view(): void
    {
        $t = $this->ticket(['created_by_id' => $this->leader->id, 'created_by_name' => $this->leader->name]);

        $this->assertSame('staff', $this->show($t, $this->leader)->json('data.viewer_role'));
    }

    // ═══ Email design ═══════════════════════════════════════════════════════

    private function mail(string $kind, string $headline, string $status, array $over = []): TicketStatusUpdateMail
    {
        return new TicketStatusUpdateMail(
            $over['name'] ?? 'مقدّم البلاغ', $kind, $over['title'] ?? 'تعطل بوابة الدفع', $status, $headline,
            'https://app.example.test/dashboard/tickets/42', $over['number'] ?? 'EMC-TCK-ABC123', $over['at'] ?? '12 أكتوبر 2026، 09:30 ص',
        );
    }

    public function test_the_email_is_rtl_arabic_and_has_the_brand_header_and_footer(): void
    {
        $html = $this->mail('ticket_resolved', 'تم حل البلاغ', 'تم الحل')->render();

        $this->assertStringContainsString('lang="ar" dir="rtl"', $html);
        $this->assertStringContainsString('direction:rtl', $html);
        $this->assertStringContainsString('EMC', $html);
        $this->assertStringContainsString('support@edumc.nl', $html);
        $this->assertStringContainsString('width="100%"', $html);
        $this->assertStringContainsString('max-width:600px', $html);
        $this->assertStringContainsString('@media only screen and (max-width: 620px)', $html);
    }

    public function test_the_email_summary_card_shows_title_number_status_and_date(): void
    {
        $html = $this->mail('ticket_resolved', 'تم حل البلاغ', 'تم الحل')->render();

        $this->assertStringContainsString('تم حل البلاغ', $html);
        $this->assertStringContainsString('تعطل بوابة الدفع', $html);
        $this->assertStringContainsString('EMC-TCK-ABC123', $html);
        $this->assertStringContainsString('تم الحل', $html);
        $this->assertStringContainsString('12 أكتوبر 2026، 09:30 ص', $html);
        $this->assertStringContainsString('مقدّم البلاغ', $html);
    }

    public function test_the_email_cta_points_at_the_details_page(): void
    {
        $html = $this->mail('ticket_resolved', 'تم حل البلاغ', 'تم الحل')->render();

        $this->assertMatchesRegularExpression('#<a href="https://app\.example\.test/dashboard/tickets/42"[^>]*>\s*عرض التفاصيل\s*</a>#u', $html);
    }

    public function test_each_email_kind_has_its_own_headline_and_intro(): void
    {
        $resolved = $this->mail('ticket_resolved', 'تم حل البلاغ', 'تم الحل')->render();
        $approved = $this->mail('suggestion_approved', 'تم اعتماد المقترح', 'معتمد', ['title' => 'وضع ليلي'])->render();
        $done = $this->mail('suggestion_implemented', 'تم تنفيذ المقترح', 'تم التنفيذ', ['title' => 'وضع ليلي'])->render();

        $this->assertStringContainsString('تم حل البلاغ الذي قدّمته', $resolved);
        $this->assertStringContainsString('رقم البلاغ', $resolved);
        $this->assertStringContainsString('تمت مراجعة مقترحك واعتماده', $approved);
        $this->assertStringContainsString('رقم المقترح', $approved);
        $this->assertStringContainsString('تم تنفيذ مقترحك وإضافته إلى النظام', $done);
        $this->assertStringNotContainsString('تم حل البلاغ الذي قدّمته', $approved.$done);
    }

    public function test_an_unknown_kind_falls_back_to_a_generic_status_update(): void
    {
        $html = $this->mail('ticket_status_updated', 'تم تحديث الحالة', 'قيد المراجعة')->render();

        $this->assertStringContainsString('تم تحديث حالة طلبك', $html);
        $this->assertStringContainsString('تم تحديث الحالة', $html);
    }

    public function test_the_email_renders_without_the_optional_number_and_date(): void
    {
        $m = new TicketStatusUpdateMail('مقدّم البلاغ', 'ticket_resolved', 'عنوان', 'تم الحل', 'تم حل البلاغ', 'https://app.example.test/dashboard/tickets/1');
        $html = $m->render();

        $this->assertStringNotContainsString('رقم البلاغ', $html);
        $this->assertStringNotContainsString('آخر تحديث', $html);
        $this->assertStringContainsString('عنوان', $html);
    }

    public function test_the_email_escapes_a_hostile_title(): void
    {
        $html = $this->mail('ticket_resolved', 'تم حل البلاغ', 'تم الحل', ['title' => '<script>alert(1)</script>'])->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_real_dispatch_puts_the_ticket_number_and_a_date_in_the_email_but_no_internal_data(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'resolved_at' => null, 'task_scope' => 'simple', 'task_points' => 30]);
        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", [
            'resolution_type' => 'RESOLVED', 'resolution_summary' => 'تفاصيل الحل الداخلية الحساسة',
        ])->assertOk();
        // The email goes out at the FINAL approval, not when the assignee submits the work.
        Queue::assertNothingPushed();
        Sanctum::actingAs($this->leader);
        $this->postJson("/api/v1/tickets/{$t->id}/approve-completion")->assertOk();

        $mailable = null;
        Queue::assertPushed(SendLoggedEmailJob::class, function ($job) use (&$mailable) {
            $mailable = $job->mailable;

            return true;
        });
        $html = $mailable->render();

        $this->assertStringContainsString($t->ticket_number, $html);
        $this->assertStringContainsString('آخر تحديث', $html);
        $this->assertStringNotContainsString('تفاصيل الحل الداخلية الحساسة', $html);
        $this->assertStringNotContainsString(self::SECRET_NOTE, $html);
        $this->assertStringNotContainsString('assignee-secret@example.com', $html);
    }
}
