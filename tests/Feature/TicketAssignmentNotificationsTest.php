<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EmailSettingsController;
use App\Jobs\SendLoggedEmailJob;
use App\Mail\TicketAssignedMail;
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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TICKET ASSIGNMENT NOTIFICATIONS.
 *
 * Whoever a ticket is handed to — by approve-with-assignee, reassign or bulk
 * reassign — hears about it in the notification centre and by a logged email
 * that links straight to the ticket in review mode. Every assignment goes
 * through the real endpoints, so authorization is in the path.
 */
class TicketAssignmentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_NOTE = 'ملاحظة داخلية سرية للإدارة';

    private Department $dept;

    private User $leader;

    private User $submitter;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SendLoggedEmailJob::class]);

        $this->dept = Department::create([
            'name' => 'Dept '.Str::random(5), 'name_ar' => 'إدارة التقنية', 'slug' => 'dept-'.Str::random(8),
            'status' => 'active', 'is_active' => true,
        ]);
        $this->leader = $this->user('programs_manager', ['name' => 'قائد الإدارة']);
        $this->dept->update(['leader_id' => $this->leader->id]);
        $this->submitter = $this->user('student', ['email' => 'submitter@example.com']);
        // Eligibility is department membership (TicketAccess::eligibleAssigneesQuery); there is no global-role shortcut.
        $this->alice = $this->user('tech_admin', ['name' => 'أليس', 'email' => 'alice@example.com']);
        $this->bob = $this->user('tech_admin', ['name' => 'بوب', 'email' => 'bob@example.com']);
        $this->dept->users()->attach([$this->alice->id, $this->bob->id]);
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
            'description' => 'لا يمكن إتمام الدفع من صفحة الدورات منذ صباح اليوم.',
            'ticket_category' => 'OLD_ISSUE', 'priority' => 'HIGH',
            'department_id' => $this->dept->id,
            'created_by_id' => $this->submitter->id, 'created_by_name' => $this->submitter->name,
            'status' => 'PENDING_APPROVAL',
            'internal_notes' => self::SECRET_NOTE,
        ], $over));
    }

    private function approve(Ticket $t, array $body = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/approve", array_merge(['sla_hours' => 24, 'task_scope' => 'simple', 'task_points' => 30], $body));
    }

    private function reassign(Ticket $t, User $to, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $to->id, 'task_scope' => 'simple', 'task_points' => 30]);
    }

    private function assignedTo(User $u)
    {
        return PlatformNotification::where('user_id', $u->id)->where('type', 'operations_ticket_assigned')->get();
    }

    /** @return SendLoggedEmailJob[] */
    private function emailJobs(): array
    {
        $jobs = [];
        Queue::assertPushed(SendLoggedEmailJob::class, function ($job) use (&$jobs) {
            if ($job->mailable instanceof TicketAssignedMail) {
                $jobs[] = $job;
            }

            return true;
        });

        return $jobs;
    }

    // ═══ Approve with an assignee ════════════════════════════════════════════

    public function test_approving_with_an_assignee_notifies_them_in_app_with_a_review_link(): void
    {
        $t = $this->ticket();

        $this->approve($t, ['assigned_to_id' => $this->alice->id])->assertOk();

        $n = $this->assignedTo($this->alice)->sole();
        $this->assertSame('أُسندت إليك تذكرة', $n->title);
        $this->assertStringContainsString('تعطل بوابة الدفع الإلكتروني', $n->message);
        $this->assertSame('ticket', $n->entity_type);
        $this->assertSame($t->id, (int) $n->entity_id);
        $this->assertSame("/dashboard/tickets/{$t->id}?review=assigned", $n->action_url);
        $this->assertSame($this->leader->id, (int) $n->created_by);
    }

    public function test_approving_with_an_assignee_queues_a_logged_email_to_them(): void
    {
        $t = $this->ticket();

        $this->approve($t, ['assigned_to_id' => $this->alice->id])->assertOk();

        $jobs = $this->emailJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('alice@example.com', $jobs[0]->toEmail);

        $log = NotificationLog::where('type', 'operations_ticket_assigned')->sole();
        $this->assertSame('email', $log->channel);
        $this->assertSame('pending', $log->status);
        $this->assertSame($this->alice->id, (int) $log->user_id);
        $this->assertSame($t->id, (int) $log->payload['ticket_id']);
        $this->assertSame($this->leader->id, (int) $log->triggered_by_user_id);
        $this->assertStringContainsString('أُسندت إليك تذكرة', $log->subject);
    }

    public function test_the_email_has_what_is_needed_to_review_but_no_internal_notes(): void
    {
        $t = $this->ticket();
        $this->approve($t, ['assigned_to_id' => $this->alice->id])->assertOk();

        $mail = $this->emailJobs()[0]->mailable;
        $html = $mail->render();
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $this->assertStringContainsString('[أولوية عالية]', $mail->subjectLine());
        $this->assertStringContainsString($t->ticket_number, $mail->subjectLine());
        $this->assertStringContainsString('تعطل بوابة الدفع الإلكتروني', $html);
        $this->assertStringContainsString($t->ticket_number, $html);
        $this->assertStringContainsString('عالية', $html);
        $this->assertStringContainsString('إدارة التقنية', $html);
        $this->assertStringContainsString('قائد الإدارة', $html);
        $this->assertStringContainsString('لا يمكن إتمام الدفع', $html);
        $this->assertStringContainsString('الموعد النهائي للحل', $html);
        $this->assertStringContainsString('مراجعة التذكرة', $html);
        $this->assertStringContainsString("{$frontend}/dashboard/tickets/{$t->id}?review=assigned", $html);
        $this->assertStringContainsString("{$frontend}/dashboard/tickets/workspace", $html);
        $this->assertStringNotContainsString(self::SECRET_NOTE, $html);
        $this->assertStringNotContainsString('submitter@example.com', $html);
    }

    public function test_approving_without_an_assignee_notifies_nobody(): void
    {
        $this->approve($this->ticket())->assertOk();

        $this->assertSame(0, PlatformNotification::where('type', 'operations_ticket_assigned')->count());
        $this->assertSame(0, NotificationLog::where('type', 'operations_ticket_assigned')->count());
    }

    // ═══ Reassign ════════════════════════════════════════════════════════════

    public function test_reassigning_notifies_the_new_assignee_only(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->alice->id]);

        $this->reassign($t, $this->bob)->assertOk();

        $this->assertCount(1, $this->assignedTo($this->bob));
        $this->assertCount(0, $this->assignedTo($this->alice));
        $this->assertCount(1, $this->emailJobs());
        $this->assertSame('bob@example.com', $this->emailJobs()[0]->toEmail);
    }

    public function test_reassigning_back_to_a_previous_assignee_notifies_them_again(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->alice->id]);

        $this->reassign($t, $this->bob)->assertOk();
        $this->reassign($t, $this->alice)->assertOk();
        $this->reassign($t, $this->bob)->assertOk();

        $this->assertCount(1, $this->assignedTo($this->alice));
        $this->assertCount(2, $this->assignedTo($this->bob));
        $this->assertCount(3, $this->emailJobs());
    }

    public function test_reassigning_to_the_same_person_before_they_accepted_sends_nothing(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->alice->id]);

        $this->reassign($t, $this->alice)->assertOk();

        $this->assertCount(0, $this->assignedTo($this->alice));
        $this->assertSame(0, NotificationLog::where('type', 'operations_ticket_assigned')->count());
    }

    public function test_sending_an_accepted_ticket_back_to_the_same_person_asks_them_again(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->alice->id, 'accepted_at' => now()->subHour()]);

        $this->reassign($t, $this->alice)->assertOk();

        $this->assertSame('ASSIGNED', $t->fresh()->status);
        $this->assertCount(1, $this->assignedTo($this->alice));
        $this->assertCount(1, $this->emailJobs());
    }

    public function test_bulk_reassign_notifies_the_assignee_once_per_ticket(): void
    {
        $a = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->alice->id]);
        $b = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->alice->id]);

        Sanctum::actingAs($this->leader);
        $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$a->id, $b->id], 'assigned_to_id' => $this->bob->id, 'task_scope' => 'simple', 'task_points' => 30])
            ->assertOk()->assertJsonPath('data.succeeded', 2);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->assignedTo($this->bob)->pluck('entity_id')->map(fn ($v) => (int) $v)->all());
        $this->assertCount(2, $this->emailJobs());
    }

    // ═══ Who is skipped ═════════════════════════════════════════════════════

    public function test_assigning_a_ticket_to_yourself_sends_nothing(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->bob->id]);

        // A global role may both manage and be assigned.
        $this->reassign($t, $this->alice, $this->alice)->assertOk();

        $this->assertCount(0, $this->assignedTo($this->alice));
        Queue::assertNothingPushed();
    }

    public function test_an_inactive_assignee_is_skipped_safely(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->alice->id]);
        $this->bob->update(['is_active' => false]);

        // Update the model directly: the eligibility check is the endpoint's business, not ours.
        $t->update(['assigned_to_id' => $this->bob->id]);

        $this->assertCount(0, $this->assignedTo($this->bob));
        Queue::assertNothingPushed();
    }

    public function test_an_unauthorized_reassign_notifies_nobody(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->alice->id]);

        $this->reassign($t, $this->bob, $this->submitter)->assertForbidden();

        $this->assertSame(0, PlatformNotification::where('type', 'operations_ticket_assigned')->count());
        Queue::assertNothingPushed();
    }

    public function test_a_rolled_back_assignment_notifies_nobody(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->alice->id]);

        try {
            DB::transaction(function () use ($t) {
                $t->update(['assigned_to_id' => $this->bob->id]);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame($this->alice->id, (int) $t->fresh()->assigned_to_id);
        $this->assertCount(0, $this->assignedTo($this->bob));
        Queue::assertNothingPushed();
    }

    public function test_a_priority_change_or_plain_save_sends_nothing(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->alice->id]);

        $t->update(['priority' => 'LOW', 'internal_notes' => 'تعديل']);

        $this->assertSame(0, PlatformNotification::where('type', 'operations_ticket_assigned')->count());
        Queue::assertNothingPushed();
    }

    // ═══ Email setting and dedup ════════════════════════════════════════════

    public function test_turning_the_email_off_keeps_the_in_app_notification_and_logs_a_skip(): void
    {
        EmailSetting::updateOrCreate(['key' => 'operations_ticket_assigned'], ['enabled' => false]);
        $t = $this->ticket();

        $this->approve($t, ['assigned_to_id' => $this->alice->id])->assertOk();

        $this->assertCount(1, $this->assignedTo($this->alice));
        $this->assertSame('skipped_disabled', NotificationLog::where('type', 'operations_ticket_assigned')->sole()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_retried_notification_for_the_same_assignment_is_not_sent_twice(): void
    {
        NotificationService::ticketAssigned($this->alice->id, $this->ticket()->id, 'عنوان', 'none@2026-11-21T10:00:00.000000', $this->leader->id);
        $id = (int) PlatformNotification::where('type', 'operations_ticket_assigned')->value('entity_id');
        NotificationService::ticketAssigned($this->alice->id, $id, 'عنوان', 'none@2026-11-21T10:00:00.000000', $this->leader->id);

        $this->assertCount(1, $this->assignedTo($this->alice));
        $this->assertSame(1, NotificationLog::where('type', 'operations_ticket_assigned')->count());
    }

    public function test_the_email_key_is_listed_in_the_admin_email_settings(): void
    {
        $this->assertArrayHasKey('operations_ticket_assigned', EmailSettingsController::LABELS);
    }
}
