<?php

namespace Tests\Feature;

use App\Jobs\SendLoggedEmailJob;
use App\Mail\TicketStatusUpdateMail;
use App\Models\Department;
use App\Models\EmailSetting;
use App\Models\NotificationLog;
use App\Models\PlatformNotification;
use App\Models\Ticket;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REPORT / SUGGESTION STATUS NOTIFICATIONS.
 *
 * Reports (OLD_ISSUE) and suggestions (NEW_SUGGESTION) are one Ticket model. The
 * person who submitted it is told — in the notification center and by email —
 * when a report is RESOLVED, a suggestion is approved (PENDING_APPROVAL ->
 * ASSIGNED) or a suggestion is implemented (-> RESOLVED).
 *
 * Every status change here goes through the real controller endpoints, so
 * authorization is genuinely in the path: an unauthorized request never reaches
 * the update and therefore can never notify anyone.
 */
class TicketStatusNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_NOTE = 'ملاحظة داخلية سرية للإدارة';
    private const SECRET_SUMMARY = 'تفاصيل الحل الداخلية الحساسة';

    private Department $dept;
    private User $leader;
    private User $submitter;
    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();

        // Capture email jobs; platform notifications still run synchronously.
        Queue::fake([SendLoggedEmailJob::class]);

        $this->dept = Department::create([
            'name' => 'Dept '.Str::random(5), 'name_ar' => 'إدارة التقنية', 'slug' => 'dept-'.Str::random(8),
            'status' => 'active', 'is_active' => true,
        ]);
        $this->leader = $this->user('programs_manager');
        $this->dept->update(['leader_id' => $this->leader->id]);
        $this->submitter = $this->user('student', ['name' => 'مقدّم البلاغ', 'email' => 'submitter@example.com']);
        $this->assignee = $this->user('student');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(string $role, array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $attrs));
    }

    private function ticket(array $over = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-'.Str::random(8),
            'title' => 'تعطل بوابة الدفع الإلكتروني',
            'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->dept->id,
            'created_by_id' => $this->submitter->id, 'created_by_name' => $this->submitter->name,
            'status' => 'PENDING_APPROVAL',
            'internal_notes' => self::SECRET_NOTE,
        ], $over));
    }

    private function suggestion(array $over = []): Ticket
    {
        return $this->ticket(array_merge(['ticket_category' => 'NEW_SUGGESTION', 'title' => 'إضافة وضع ليلي للمنصة'], $over));
    }

    private function inProgress(array $over = []): Ticket
    {
        return $this->ticket(array_merge(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id], $over));
    }

    private function approve(Ticket $t, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/approve", ['sla_hours' => 24]);
    }

    private function resolve(Ticket $t, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->assignee);

        return $this->postJson("/api/v1/tickets/{$t->id}/complete-task", [
            'resolution_type' => 'RESOLVED', 'resolution_summary' => self::SECRET_SUMMARY,
        ]);
    }

    private function notificationsFor(User $u, ?string $type = null)
    {
        return PlatformNotification::where('user_id', $u->id)->when($type, fn ($q) => $q->where('type', $type))->get();
    }

    private function emailJobs(): array
    {
        $jobs = [];
        Queue::assertPushed(SendLoggedEmailJob::class, function ($job) use (&$jobs) {
            $jobs[] = $job;

            return true;
        });

        return $jobs;
    }

    private function allNotificationCount(): int
    {
        return PlatformNotification::count();
    }

    // ═══ Reports: resolved ═══════════════════════════════════════════════════

    public function test_resolving_a_report_notifies_the_submitter_in_the_notification_center(): void
    {
        $ticket = $this->inProgress();

        $this->resolve($ticket)->assertOk();

        $n = $this->notificationsFor($this->submitter, 'ticket_resolved')->sole();
        $this->assertSame('تم حل البلاغ', $n->title);
        $this->assertSame('ticket', $n->entity_type);
        $this->assertSame($ticket->id, (int) $n->entity_id);
        $this->assertFalse((bool) $n->is_read);
    }

    public function test_the_report_notification_contains_title_status_and_a_working_link(): void
    {
        $ticket = $this->inProgress();

        $this->resolve($ticket)->assertOk();

        $n = $this->notificationsFor($this->submitter, 'ticket_resolved')->sole();
        $this->assertStringContainsString('تعطل بوابة الدفع الإلكتروني', $n->message);
        $this->assertStringContainsString('تم الحل', $n->message);
        $this->assertStringContainsString('تم حل بلاغك', $n->message);
        // The route the SPA actually serves (/dashboard/tickets/:id), not an invented path.
        $this->assertSame("/dashboard/tickets/{$ticket->id}", $n->action_url);
    }

    public function test_resolving_a_report_queues_a_logged_email_to_the_submitter(): void
    {
        $ticket = $this->inProgress();

        $this->resolve($ticket)->assertOk();

        $jobs = $this->emailJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('submitter@example.com', $jobs[0]->toEmail);
        $this->assertInstanceOf(TicketStatusUpdateMail::class, $jobs[0]->mailable);
        $this->assertSame('ticket_resolved', $jobs[0]->mailable->kind);

        $log = NotificationLog::where('type', 'ticket_resolved')->sole();
        $this->assertSame('email', $log->channel);
        $this->assertSame('pending', $log->status);
        $this->assertSame($this->submitter->id, (int) $log->user_id);
        $this->assertSame($ticket->id, (int) $log->payload['ticket_id']);
    }

    public function test_the_email_carries_title_status_and_link_but_no_internal_data(): void
    {
        $ticket = $this->inProgress();
        $this->resolve($ticket)->assertOk();

        $html = $this->emailJobs()[0]->mailable->render();

        $this->assertStringContainsString('تعطل بوابة الدفع الإلكتروني', $html);
        $this->assertStringContainsString('تم الحل', $html);
        $this->assertStringContainsString('تم حل البلاغ الذي قدّمته', $html);
        $this->assertStringContainsString(rtrim((string) config('app.frontend_url'), '/')."/dashboard/tickets/{$ticket->id}", $html);
        $this->assertStringNotContainsString(self::SECRET_NOTE, $html);
        $this->assertStringNotContainsString(self::SECRET_SUMMARY, $html);
    }

    public function test_internal_notes_and_the_resolution_summary_never_reach_the_in_app_notification(): void
    {
        $ticket = $this->inProgress();
        $this->resolve($ticket)->assertOk();

        $n = $this->notificationsFor($this->submitter, 'ticket_resolved')->sole();

        foreach ([self::SECRET_NOTE, self::SECRET_SUMMARY] as $secret) {
            $this->assertStringNotContainsString($secret, $n->title.$n->message.$n->action_url);
        }
    }

    public function test_only_the_submitter_is_notified_never_the_resolver_or_anyone_else(): void
    {
        $bystander = $this->user('student');
        $this->inProgress();
        $ticket = Ticket::first();

        $this->resolve($ticket)->assertOk();

        $this->assertSame(1, $this->allNotificationCount());
        $this->assertCount(1, $this->notificationsFor($this->submitter));
        $this->assertCount(0, $this->notificationsFor($this->assignee), 'the resolver is not the recipient');
        $this->assertCount(0, $this->notificationsFor($this->leader));
        $this->assertCount(0, $this->notificationsFor($bystander));
    }

    public function test_an_unresolved_outcome_is_not_announced_as_resolved(): void
    {
        $ticket = $this->inProgress();

        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$ticket->id}/complete-task", [
            'resolution_type' => 'UNRESOLVED', 'unresolved_reason' => 'تعذر الحل بسبب قيود الخادم',
        ])->assertOk();

        $this->assertSame(0, $this->allNotificationCount());
        Queue::assertNothingPushed();
    }

    public function test_approving_a_report_does_not_notify(): void
    {
        $this->approve($this->ticket())->assertOk();

        $this->assertSame(0, $this->allNotificationCount());
    }

    // ── Idempotency (reports) ────────────────────────────────────────────────

    public function test_resolving_an_already_resolved_report_again_sends_nothing_more(): void
    {
        $ticket = $this->inProgress();
        $this->resolve($ticket)->assertOk();

        // The endpoint refuses (409), and a direct re-save of the same status is not a transition.
        $this->resolve($ticket)->assertStatus(409);
        $ticket->refresh()->update(['status' => 'RESOLVED']);

        $this->assertCount(1, $this->notificationsFor($this->submitter, 'ticket_resolved'));
        Queue::assertPushed(SendLoggedEmailJob::class, 1);
    }

    public function test_editing_a_resolved_report_does_not_notify_again(): void
    {
        $ticket = $this->inProgress();
        $this->resolve($ticket)->assertOk();

        $ticket->refresh()->update(['title' => 'عنوان معدّل', 'description' => 'وصف معدّل', 'priority' => 'HIGH']);

        $this->assertCount(1, $this->notificationsFor($this->submitter));
        Queue::assertPushed(SendLoggedEmailJob::class, 1);
    }

    public function test_a_retried_delivery_for_the_same_ticket_is_deduplicated(): void
    {
        $ticket = $this->inProgress();
        $this->resolve($ticket)->assertOk();

        // e.g. a job retry or a duplicated dispatch of the same scenario.
        NotificationService::ticketResolved($this->submitter->id, $ticket->id, $ticket->title);
        NotificationService::ticketResolved($this->submitter->id, $ticket->id, $ticket->title);

        $this->assertCount(1, $this->notificationsFor($this->submitter, 'ticket_resolved'));
        Queue::assertPushed(SendLoggedEmailJob::class, 1);
        $this->assertSame(1, NotificationLog::where('type', 'ticket_resolved')->count());
    }

    public function test_two_different_reports_each_notify(): void
    {
        $a = $this->inProgress(['title' => 'بلاغ أول']);
        $b = $this->inProgress(['title' => 'بلاغ ثان']);

        $this->resolve($a)->assertOk();
        $this->resolve($b)->assertOk();

        $this->assertCount(2, $this->notificationsFor($this->submitter, 'ticket_resolved'));
    }

    // ── Authorization (reports) ──────────────────────────────────────────────

    public function test_an_unauthorized_attempt_to_resolve_sends_nothing(): void
    {
        $ticket = $this->inProgress();

        foreach ([$this->user('student'), $this->leader, $this->submitter] as $notTheAssignee) {
            $this->resolve($ticket, $notTheAssignee)->assertForbidden();
        }

        $this->assertSame('IN_PROGRESS', $ticket->fresh()->status);
        $this->assertSame(0, $this->allNotificationCount());
        Queue::assertNothingPushed();
    }

    public function test_an_unauthenticated_attempt_sends_nothing(): void
    {
        $ticket = $this->inProgress();

        $this->postJson("/api/v1/tickets/{$ticket->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'xxxxxxx'])->assertUnauthorized();

        $this->assertSame(0, $this->allNotificationCount());
    }

    public function test_a_failed_validation_does_not_notify(): void
    {
        $ticket = $this->inProgress();

        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$ticket->id}/complete-task", ['resolution_type' => 'RESOLVED'])->assertStatus(422);

        $this->assertSame('IN_PROGRESS', $ticket->fresh()->status);
        $this->assertSame(0, $this->allNotificationCount());
    }

    public function test_a_rolled_back_status_change_never_notifies(): void
    {
        $ticket = $this->inProgress();

        try {
            DB::transaction(function () use ($ticket) {
                $ticket->update(['status' => 'RESOLVED']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('IN_PROGRESS', $ticket->fresh()->status);
        $this->assertSame(0, $this->allNotificationCount());
        Queue::assertNothingPushed();
    }

    // ── Recipient edge cases (reports) ───────────────────────────────────────

    public function test_a_deleted_submitter_is_skipped_without_breaking_the_resolution(): void
    {
        $ticket = $this->inProgress();
        $this->submitter->delete();

        $this->resolve($ticket)->assertOk();

        $this->assertSame('RESOLVED', $ticket->fresh()->status);
        $this->assertSame(0, $this->allNotificationCount());
        Queue::assertNothingPushed();
    }

    public function test_an_inactive_submitter_is_skipped(): void
    {
        $ticket = $this->inProgress();
        $this->submitter->update(['is_active' => false]);

        $this->resolve($ticket)->assertOk();

        $this->assertSame(0, $this->allNotificationCount());
    }

    public function test_a_ticket_with_no_recorded_submitter_is_skipped(): void
    {
        $ticket = $this->inProgress(['created_by_id' => null]);

        $this->resolve($ticket)->assertOk();

        $this->assertSame('RESOLVED', $ticket->fresh()->status);
        $this->assertSame(0, $this->allNotificationCount());
    }

    public function test_a_submitter_without_an_email_still_gets_the_in_app_notification(): void
    {
        $ticket = $this->inProgress();
        $this->submitter->forceFill(['email' => ''])->save();

        $this->resolve($ticket)->assertOk();

        $this->assertCount(1, $this->notificationsFor($this->submitter, 'ticket_resolved'));
        Queue::assertNothingPushed();
    }

    public function test_a_submitter_who_resolves_their_own_ticket_is_not_notified_about_their_own_action(): void
    {
        $ticket = $this->inProgress(['assigned_to_id' => $this->submitter->id]);

        $this->resolve($ticket, $this->submitter)->assertOk();

        $this->assertSame(0, $this->allNotificationCount());
    }

    public function test_a_failure_while_notifying_never_fails_the_status_change(): void
    {
        $ticket = $this->inProgress();
        // Make the notification write itself blow up: the notification table is gone.
        Schema::drop('platform_notifications');

        $this->resolve($ticket)->assertOk();

        $this->assertSame('RESOLVED', $ticket->fresh()->status, 'the status change stands even though telling the user failed');
    }

    public function test_when_the_email_setting_is_off_the_in_app_notification_still_arrives_and_the_skip_is_logged(): void
    {
        EmailSetting::create(['key' => 'ticket_resolved', 'enabled' => false]);
        $ticket = $this->inProgress();

        $this->resolve($ticket)->assertOk();

        $this->assertCount(1, $this->notificationsFor($this->submitter, 'ticket_resolved'));
        Queue::assertNothingPushed();
        $this->assertSame('skipped_disabled', NotificationLog::where('type', 'ticket_resolved')->sole()->status);
    }

    // ═══ Suggestions ═════════════════════════════════════════════════════════

    public function test_approving_a_suggestion_notifies_the_submitter(): void
    {
        $s = $this->suggestion();

        $this->approve($s)->assertOk();

        $n = $this->notificationsFor($this->submitter, 'suggestion_approved')->sole();
        $this->assertSame('تم اعتماد المقترح', $n->title);
        $this->assertStringContainsString('إضافة وضع ليلي للمنصة', $n->message);
        $this->assertStringContainsString('معتمد', $n->message);
        $this->assertStringContainsString('تم اعتماد مقترحك', $n->message);
        $this->assertSame("/dashboard/tickets/{$s->id}", $n->action_url);
        $this->assertSame($s->id, (int) $n->entity_id);
    }

    public function test_the_approval_email_is_queued_with_the_right_content(): void
    {
        $s = $this->suggestion();
        $this->approve($s)->assertOk();

        $job = $this->emailJobs()[0];
        $this->assertSame('submitter@example.com', $job->toEmail);
        $this->assertSame('suggestion_approved', $job->mailable->kind);
        $html = $job->mailable->render();
        $this->assertStringContainsString('تمت مراجعة مقترحك واعتماده', $html);
        $this->assertStringContainsString('إضافة وضع ليلي للمنصة', $html);
        $this->assertStringContainsString("/dashboard/tickets/{$s->id}", $html);
        $this->assertStringNotContainsString(self::SECRET_NOTE, $html);
    }

    public function test_implementing_a_suggestion_notifies_the_submitter_as_implemented_not_as_a_resolved_report(): void
    {
        $s = $this->suggestion(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id]);

        $this->resolve($s)->assertOk();

        $n = $this->notificationsFor($this->submitter, 'suggestion_implemented')->sole();
        $this->assertSame('تم تنفيذ المقترح', $n->title);
        $this->assertStringContainsString('إضافة وضع ليلي للمنصة', $n->message);
        $this->assertStringContainsString('تم تنفيذ مقترحك', $n->message);
        $this->assertStringContainsString('شكرًا لمساهمتك', $n->message);
        $this->assertSame("/dashboard/tickets/{$s->id}", $n->action_url);
        $this->assertCount(0, $this->notificationsFor($this->submitter, 'ticket_resolved'));

        $html = $this->emailJobs()[0]->mailable->render();
        $this->assertStringContainsString('تم تنفيذ مقترحك وإضافته إلى النظام. شكرًا لمساهمتك في تطوير المنصة.', $html);
        $this->assertStringNotContainsString(self::SECRET_SUMMARY, $html);
    }

    public function test_the_whole_suggestion_journey_notifies_exactly_twice(): void
    {
        $s = $this->suggestion();

        Sanctum::actingAs($this->leader);
        $this->postJson("/api/v1/tickets/{$s->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => null])->assertOk();
        $s->refresh()->update(['assigned_to_id' => $this->assignee->id]);

        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$s->id}/accept-task")->assertOk();
        $this->resolve($s)->assertOk();

        $types = $this->notificationsFor($this->submitter)->pluck('type')->sort()->values()->all();
        $this->assertSame(['suggestion_approved', 'suggestion_implemented'], $types);
    }

    public function test_repeating_an_approval_or_editing_an_approved_suggestion_does_not_duplicate(): void
    {
        $s = $this->suggestion();
        $this->approve($s)->assertOk();

        $this->approve($s)->assertStatus(409);
        $s->refresh()->update(['status' => 'ASSIGNED']);
        $s->update(['title' => 'عنوان معدل', 'internal_notes' => 'ملاحظة جديدة']);

        $this->assertCount(1, $this->notificationsFor($this->submitter, 'suggestion_approved'));
        Queue::assertPushed(SendLoggedEmailJob::class, 1);
    }

    public function test_repeating_an_implementation_or_re_saving_it_does_not_duplicate(): void
    {
        $s = $this->suggestion(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id]);
        $this->resolve($s)->assertOk();

        $this->resolve($s)->assertStatus(409);
        $s->refresh()->update(['status' => 'RESOLVED']);
        $s->update(['resolution_summary' => 'تعديل لاحق']);

        $this->assertCount(1, $this->notificationsFor($this->submitter, 'suggestion_implemented'));
        Queue::assertPushed(SendLoggedEmailJob::class, 1);
    }

    public function test_reassigning_a_suggestion_in_progress_is_not_mistaken_for_an_approval(): void
    {
        // TicketController::reassign writes IN_PROGRESS -> ASSIGNED. That is a hand-over
        // between assignees, not the management approval (which starts at PENDING_APPROVAL).
        $s = $this->suggestion(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id]);

        $newAssignee = $this->user('student');
        $s->update(['status' => 'ASSIGNED', 'assigned_to_id' => $newAssignee->id]);

        $this->assertCount(0, $this->notificationsFor($this->submitter, 'suggestion_approved'));
        // The submitter hears nothing; only the new assignee is told it was handed to them
        // (TicketAssignmentObserver, covered in TicketAssignmentNotificationsTest).
        $this->assertCount(0, PlatformNotification::where('user_id', $this->submitter->id)->get());
        $this->assertCount(1, $this->notificationsFor($newAssignee, 'operations_ticket_assigned'));
        $this->assertSame(1, $this->allNotificationCount());
    }

    public function test_an_unauthorized_suggestion_update_sends_nothing(): void
    {
        $s = $this->suggestion();

        foreach ([$this->user('student'), $this->submitter, $this->assignee] as $nobody) {
            $this->approve($s, $nobody)->assertForbidden();
        }

        $this->assertSame('PENDING_APPROVAL', $s->fresh()->status);
        $this->assertSame(0, $this->allNotificationCount());

        $inProgress = $this->suggestion(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id]);
        $this->resolve($inProgress, $this->leader)->assertForbidden();
        $this->assertSame(0, $this->allNotificationCount());
        Queue::assertNothingPushed();
    }

    public function test_a_deleted_or_missing_suggestion_submitter_is_handled_safely(): void
    {
        $s = $this->suggestion();
        $this->submitter->delete();
        $this->approve($s)->assertOk();

        $orphan = $this->suggestion(['created_by_id' => null, 'status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id]);
        $this->resolve($orphan)->assertOk();

        $this->assertSame(0, $this->allNotificationCount());
        Queue::assertNothingPushed();
    }

    public function test_only_the_suggestion_submitter_is_notified(): void
    {
        $s = $this->suggestion();

        $this->approve($s)->assertOk();

        $this->assertSame(1, $this->allNotificationCount());
        $this->assertCount(0, $this->notificationsFor($this->leader));
    }

    // ═══ Notification center API ═════════════════════════════════════════════

    public function test_the_notification_center_api_returns_the_new_notification_with_its_link_and_unread_state(): void
    {
        $ticket = $this->inProgress();
        $this->resolve($ticket)->assertOk();

        Sanctum::actingAs($this->submitter);
        $res = $this->getJson('/api/notifications')->assertOk();

        $row = collect($res->json('data.data') ?? $res->json('data') ?? [])->firstWhere('type', 'ticket_resolved');
        $this->assertNotNull($row, 'the new type must appear in the notification center');
        $this->assertSame("/dashboard/tickets/{$ticket->id}", $row['action_url']);
        $this->assertSame('تم حل البلاغ', $row['title']);
        $this->assertFalse((bool) $row['is_read']);

        $this->getJson('/api/notifications/unread-count')->assertOk();
    }

    public function test_another_user_cannot_see_the_submitters_notification(): void
    {
        $ticket = $this->inProgress();
        $this->resolve($ticket)->assertOk();

        Sanctum::actingAs($this->user('student'));
        $res = $this->getJson('/api/notifications')->assertOk();

        $this->assertStringNotContainsString('تعطل بوابة الدفع الإلكتروني', json_encode($res->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_marking_the_notification_read_works_as_for_any_other_type(): void
    {
        $ticket = $this->inProgress();
        $this->resolve($ticket)->assertOk();
        $n = $this->notificationsFor($this->submitter, 'ticket_resolved')->sole();

        Sanctum::actingAs($this->submitter);
        $this->postJson('/api/notifications/read-all')->assertOk();

        $this->assertTrue((bool) $n->fresh()->is_read);
    }
}
