<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\HrRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $departmentUser;
    protected User $hrUser;
    protected User $topManagementUser;
    protected Department $techDept;
    protected Department $hrDept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->departmentUser = User::factory()->create([
            'role' => 'department_manager',
        ]);

        $this->hrUser = User::factory()->create([
            'role' => 'hr_manager',
        ]);

        $this->topManagementUser = User::factory()->create([
            'role' => 'super_admin',
        ]);

        $this->techDept = Department::create([
            'name'      => 'Technology',
            'name_ar'   => 'إدارة التقنية',
            'slug'      => 'tech',
            'leader_id' => $this->departmentUser->id,
        ]);

        $this->hrDept = Department::create([
            'name'      => 'Human Resources',
            'name_ar'   => 'إدارة الموارد البشرية',
            'slug'      => 'hr',
            'leader_id' => $this->hrUser->id,
        ]);
    }

    public function test_department_can_submit_staffing_request(): void
    {
        $response = $this->actingAs($this->departmentUser, 'sanctum')
            ->postJson('/api/admin/hr-requests', [
                'department_id'    => $this->techDept->id,
                'request_type'     => 'نقص في الأعضاء',
                'required_count'   => 2,
                'skills_and_specs' => 'Laravel Backend Developer with MySQL & REST API expertise',
                'notes'            => 'عاجل جداً لتغطية مشاريع الفصل',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.required_count', 2)
            ->assertJsonPath('data.target_audience', 'hr');

        $this->assertDatabaseHas('hr_requests', [
            'department_id'   => $this->techDept->id,
            'required_count'  => 2,
            'target_audience' => 'hr',
            'status'          => 'pending',
        ]);
    }

    public function test_hr_department_request_is_targeted_to_top_management(): void
    {
        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->postJson('/api/admin/hr-requests', [
                'department_id'    => $this->hrDept->id,
                'request_type'     => 'احتياج متخصص توظيف وتقييم',
                'required_count'   => 1,
                'skills_and_specs' => 'خبرة في الاستقطاب وإدارة المقابلات',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.target_audience', 'top_management');

        $this->assertDatabaseHas('hr_requests', [
            'department_id'   => $this->hrDept->id,
            'target_audience' => 'top_management',
        ]);
    }

    public function test_hr_manager_can_update_request_status(): void
    {
        $hrReq = HrRequest::create([
            'department_id'    => $this->techDept->id,
            'request_type'     => 'نقص في الأعضاء',
            'required_count'   => 1,
            'skills_and_specs' => 'React Frontend Specialist',
            'status'           => 'pending',
            'target_audience'  => 'hr',
            'created_by'       => $this->departmentUser->id,
        ]);

        $response = $this->actingAs($this->hrUser, 'sanctum')
            ->patchJson("/api/admin/hr-requests/{$hrReq->id}/status", [
                'status'      => 'approved',
                'hr_feedback' => 'تمت الموافقة وسيتم البدء في إجراءات التوظيف فورا',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('hr_requests', [
            'id'          => $hrReq->id,
            'status'      => 'approved',
            'hr_feedback' => 'تمت الموافقة وسيتم البدء في إجراءات التوظيف فورا',
        ]);
    }
}
