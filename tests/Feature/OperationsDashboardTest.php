<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\PartnershipRequest;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Models\VolunteerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_real_operations_queues_and_today_work(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $department = Department::create([
            'name' => 'Operations',
            'name_ar' => 'العمليات',
            'slug' => 'operations-test',
            'status' => 'active',
            'is_active' => true,
        ]);

        Task::create([
            'title' => 'مهمة متأخرة وعاجلة',
            'department_id' => $department->id,
            'created_by' => $admin->id,
            'priority' => 'urgent',
            'status' => 'in_progress',
            'due_date' => today()->subDay(),
        ]);
        Task::create([
            'title' => 'مهمة اليوم',
            'department_id' => $department->id,
            'created_by' => $admin->id,
            'priority' => 'high',
            'status' => 'in_progress',
            'due_date' => today(),
        ]);
        Meeting::create([
            'title' => 'اجتماع متابعة اليوم',
            'organizer_id' => $admin->id,
            'meeting_date' => today(),
            'start_time' => '10:00:00',
            'status' => 'scheduled',
        ]);
        SupportTicket::create([
            'name' => 'Test User',
            'email' => 'support@example.test',
            'type' => 'technical',
            'priority' => 'urgent',
            'status' => 'new',
            'subject' => 'Login issue',
            'message' => 'Cannot access the account.',
        ]);
        PartnershipRequest::create([
            'partner_name' => 'Test Partner',
            'contact_name' => 'Partner Contact',
            'email' => 'partner@example.test',
            'phone' => '+31612345678',
            'country' => 'Netherlands',
            'type' => 'company',
            'partnership_type' => 'education_partnership',
            'message' => 'We would like to collaborate.',
            'privacy_accepted' => true,
            'status' => 'new',
        ]);
        VolunteerRequest::create([
            'full_name' => 'Test Volunteer',
            'email' => 'volunteer@example.test',
            'phone' => '+31612345679',
            'country' => 'Netherlands',
            'city' => 'Utrecht',
            'gender' => 'prefer_not_to_say',
            'desired_department' => 'Operations',
            'experience_level' => 'entry',
            'availability' => 'weekly',
            'motivation' => 'Help the education team.',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/operations/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.active_departments', 1)
            ->assertJsonPath('data.open_tasks', 2)
            ->assertJsonPath('data.overdue_tasks', 1)
            ->assertJsonPath('data.upcoming_meetings', 1)
            ->assertJsonPath('data.pending_partnership_requests', 1)
            ->assertJsonPath('data.volunteer_applications', 1)
            ->assertJsonPath('data.support_tickets_open', 1)
            ->assertJsonCount(5, 'data.priority_queues')
            ->assertJsonCount(2, 'data.today_items')
            ->assertJsonStructure([
                'data' => [
                    'recent_activity' => [['id', 'label', 'at', 'kind']],
                    'department_health' => [['department_id', 'title', 'score']],
                    'generated_at',
                ],
            ]);
    }

    public function test_operations_dashboard_remains_admin_only(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $this->getJson('/api/operations/dashboard')->assertUnauthorized();
        $this->actingAs($student, 'sanctum')->getJson('/api/operations/dashboard')->assertForbidden();
    }
}
