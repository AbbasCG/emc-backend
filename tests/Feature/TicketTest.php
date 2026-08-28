<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_submit_ticket_and_lists_in_pending_approval(): void
    {
        $dept = Department::create([
            'name' => 'Tech',
            'name_ar' => 'إدارة التقنية',
            'slug' => 'tech-support',
        ]);

        $response = $this->postJson('/api/v1/tickets', [
            'title' => 'بطء في النظام',
            'description' => 'شرح البطء الحاصل',
            'ticket_category' => 'OLD_ISSUE',
            'priority' => 'HIGH',
            'department_id' => $dept->id,
            'created_by_name' => 'محمد أحمد',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'PENDING_APPROVAL');

        $this->assertDatabaseHas('tickets', [
            'title' => 'بطء في النظام',
            'status' => 'PENDING_APPROVAL',
        ]);
    }

    public function test_manager_can_approve_ticket_and_set_sla(): void
    {
        $dept = Department::create([
            'name' => 'Tech',
            'name_ar' => 'إدارة التقنية',
            'slug' => 'tech-support',
        ]);

        $unit = DepartmentalUnit::create([
            'department_id' => $dept->id,
            'code' => 'UI_UX',
            'name_ar' => 'وحدة التصميم',
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'EMC-TCK-2026-999',
            'title' => 'تحسين واجهة المستخدم',
            'description' => 'تفاصيل التحسين',
            'ticket_category' => 'NEW_SUGGESTION',
            'department_id' => $dept->id,
            'created_by_name' => 'سارة',
            'status' => 'PENDING_APPROVAL',
        ]);

        $response = $this->postJson("/api/v1/tickets/{$ticket->id}/approve", [
            'unit_id' => $unit->id,
            'sla_hours' => 24,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ASSIGNED');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'unit_id' => $unit->id,
            'status' => 'ASSIGNED',
        ]);
    }

    public function test_admin_rejection_requires_mandatory_reason(): void
    {
        $dept = Department::create([
            'name' => 'Tech',
            'name_ar' => 'إدارة التقنية',
            'slug' => 'tech-support',
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'EMC-TCK-2026-888',
            'title' => 'طلب غير منطقي',
            'description' => 'تفاصيل الطلب',
            'ticket_category' => 'NEW_SUGGESTION',
            'department_id' => $dept->id,
            'created_by_name' => 'علي',
            'status' => 'PENDING_APPROVAL',
        ]);

        // Attempt without reason -> fails
        $failResponse = $this->postJson("/api/v1/tickets/{$ticket->id}/reject", []);
        $failResponse->assertStatus(422);

        // Attempt with reason -> succeeds
        $successResponse = $this->postJson("/api/v1/tickets/{$ticket->id}/reject", [
            'admin_rejection_reason' => 'الطلب خارج النطاق الفني المعتمد للمركز',
        ]);
        $successResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'REJECTED_BY_ADMIN');
    }
}
