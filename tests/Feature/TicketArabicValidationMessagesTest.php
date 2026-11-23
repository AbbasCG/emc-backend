<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** No English framework message may reach the UI from the ticket action endpoints. */
class TicketArabicValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    private User $leader;

    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'إدارة', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $this->leader = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $this->dept->update(['leader_id' => $this->leader->id]);
        $this->assignee = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $this->dept->users()->attach($this->assignee->id);
    }

    private function ticket(string $status): Ticket
    {
        return Ticket::create([
            'ticket_number' => 'EMC-TCK-V-'.Str::random(8), 'title' => 'تذكرة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->dept->id, 'created_by_name' => 'مُبلِّغ', 'status' => $status, 'assigned_to_id' => $this->assignee->id,
            'task_scope' => 'simple', 'task_points' => 30,
        ]);
    }

    /** Every message in the 422 body must contain Arabic letters and none of the stock English framework phrases. */
    private function assertArabicErrors($response): void
    {
        $response->assertStatus(422);
        $messages = collect($response->json('errors'))->flatten()->push($response->json('message') === 'Validation failed.' ? null : $response->json('message'))->filter(); // the app-wide validation wrapper's own generic text is not ours to translate here
        $this->assertNotEmpty($messages);
        foreach ($messages as $m) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $m, "not Arabic: {$m}");
            $this->assertDoesNotMatchRegularExpression('/\b(field|must|required|invalid|selected|characters)\b/i', (string) $m);
        }
    }

    public function test_reject_task_messages_are_arabic(): void
    {
        $t = $this->ticket('ASSIGNED');
        Sanctum::actingAs($this->assignee);
        $url = "/api/v1/tickets/{$t->id}/reject-task";

        $this->assertArabicErrors($this->postJson($url, []));
        $this->assertArabicErrors($this->postJson($url, ['assignee_rejection_reason' => 'abc']));
        $this->assertArabicErrors($this->postJson($url, ['assignee_rejection_reason' => ['x']]));
        $this->assertArabicErrors($this->postJson($url, ['assignee_rejection_reason' => str_repeat('س', 1001)]));
    }

    public function test_complete_task_messages_are_arabic(): void
    {
        $t = $this->ticket('IN_PROGRESS');
        Sanctum::actingAs($this->assignee);
        $url = "/api/v1/tickets/{$t->id}/complete-task";

        $this->assertArabicErrors($this->postJson($url, []));
        $this->assertArabicErrors($this->postJson($url, ['resolution_type' => 'MAYBE']));
        $this->assertArabicErrors($this->postJson($url, ['resolution_type' => 'RESOLVED']));
        $this->assertArabicErrors($this->postJson($url, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'abc']));
        $this->assertArabicErrors($this->postJson($url, ['resolution_type' => 'UNRESOLVED']));
        $this->assertArabicErrors($this->postJson($url, ['resolution_type' => 'UNRESOLVED', 'unresolved_reason' => 'abc']));
        $this->assertArabicErrors($this->postJson($url, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الإصلاح كاملًا', 'proof_files' => [UploadedFile::fake()->create('x.exe', 10)]]));
        $this->assertSame('IN_PROGRESS', $t->fresh()->status);
    }

    public function test_the_ops_endpoints_messages_are_arabic(): void
    {
        $t = $this->ticket('ASSIGNED');
        Sanctum::actingAs($this->leader);

        // priority
        $this->assertArabicErrors($this->patchJson("/api/v1/tickets/{$t->id}/priority", ['priority' => 'URGENT!']));
        $this->assertArabicErrors($this->patchJson("/api/v1/tickets/{$t->id}/priority", ['priority' => 'HIGH', 'reason' => str_repeat('س', 501)]));
        // bulk
        $this->assertArabicErrors($this->postJson('/api/v1/tickets/bulk', []));
        $this->assertArabicErrors($this->postJson('/api/v1/tickets/bulk', ['action' => 'explode', 'ticket_ids' => [$t->id]]));
        $this->assertArabicErrors($this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => ['x', 0], 'assigned_to_id' => 999999]));
        $this->assertArabicErrors($this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$t->id], 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 'abc']));
        // work-estimate
        $this->assertArabicErrors($this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 5, 'task_points' => 'x', 'estimated_hours' => 'y']));
        // reassign / approve
        $this->assertArabicErrors($this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => 999999, 'unit_id' => 999999, 'reason' => str_repeat('س', 501)]));
        $pending = $this->ticket('PENDING_APPROVAL');
        $this->assertArabicErrors($this->postJson("/api/v1/tickets/{$pending->id}/approve", ['sla_hours' => 0]));
        $this->assertArabicErrors($this->postJson("/api/v1/tickets/{$pending->id}/approve", ['sla_hours' => 'x', 'assigned_to_id' => 999999]));
    }

    public function test_ticket_submission_messages_are_arabic(): void
    {
        Sanctum::actingAs($this->assignee);

        $this->assertArabicErrors($this->postJson('/api/v1/tickets', []));
        $this->assertArabicErrors($this->postJson('/api/v1/tickets', [
            'title' => str_repeat('س', 256), 'description' => ['x'], 'ticket_category' => 'WHATEVER', 'priority' => 'ASAP', 'department_id' => 999999,
            'created_by_name' => str_repeat('س', 151), 'created_by_email' => 'not-an-email',
        ]));
        $this->assertArabicErrors($this->postJson('/api/v1/tickets', [
            'title' => 'مشكلة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'department_id' => $this->dept->id, 'created_by_name' => 'اسم',
            'attachments' => [UploadedFile::fake()->create('x.exe', 10)],
        ]));
    }

    public function test_my_work_filter_messages_are_arabic(): void
    {
        Sanctum::actingAs($this->assignee);

        $this->assertArabicErrors($this->getJson('/api/my-work/tasks?status=zzz'));
        $this->assertArabicErrors($this->getJson('/api/my-work/tasks?acceptance_status=zzz'));
        $this->assertArabicErrors($this->getJson('/api/v1/tickets/my-work?status=zzz'));
        $this->assertArabicErrors($this->getJson('/api/v1/tickets/my-work?status[]=x'));
    }

    public function test_the_approve_headline_names_the_field_that_failed(): void
    {
        $t = $this->ticket('PENDING_APPROVAL');
        Sanctum::actingAs($this->leader);
        $url = "/api/v1/tickets/{$t->id}/approve";

        // Only the assignee is wrong: not an SLA problem.
        $r = $this->postJson($url, ['sla_hours' => 24, 'assigned_to_id' => 999999])->assertStatus(422);
        $this->assertSame('بيانات الاعتماد غير صحيحة', $r->json('message'));
        $this->assertArrayHasKey('assigned_to_id', $r->json('errors'));

        // The SLA is wrong: SLA text, even when something else is wrong too.
        $this->postJson($url, ['sla_hours' => 0, 'assigned_to_id' => 999999])->assertStatus(422)->assertJsonPath('message', 'الرجاء تحديد المهلة الزمنية للـ SLA بشكل صحيح');
        $this->postJson($url, [])->assertStatus(422)->assertJsonPath('message', 'الرجاء تحديد المهلة الزمنية للـ SLA بشكل صحيح');

        // The estimate is wrong (SLA fine): the estimate's own message.
        $e = $this->postJson($url, ['sla_hours' => 24, 'estimated_hours' => 0])->assertStatus(422);
        $this->assertSame($e->json('errors.estimated_hours.0'), $e->json('message'));
    }
}
