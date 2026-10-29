<?php

namespace Tests\Feature\Volunteers;

use App\Models\Department;
use App\Models\User;
use App\Models\VolunteerHrProfile;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may change a volunteer's HR profile rests on the account's ROLE and on actually LEADING the HR department,
 * following the system's policy (route: hr_manager / admin tier; policy: manage_volunteers). Belonging to the HR
 * department, being listed in it, or naming it in the free-text department field grants nothing.
 */
class VolunteerHrEditAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private VolunteerHrProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $volunteer = User::factory()->create(['role' => 'volunteer', 'email' => 'vol@emc.test', 'is_active' => true]);
        $this->profile = VolunteerHrProfile::create([
            'full_name' => 'متطوع للاختبار', 'email' => 'vol@emc.test', 'normalized_email' => 'vol@emc.test', 'phone' => '0501234567',
            'job_title' => 'متطوع', 'join_date' => now()->toDateString(), 'cv_path' => 'cvs/sample.pdf',
            'user_id' => $volunteer->id, 'status' => 'submitted', 'city' => 'الرياض',
        ]);
    }

    private function department(string $slug, string $status = 'active'): Department
    {
        return Department::create(['name' => $slug, 'name_ar' => $slug, 'slug' => $slug, 'status' => $status, 'is_active' => $status === 'active']);
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $attributes));
    }

    private function edit(User $as): int
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as, 'sanctum')
            ->putJson("/api/hr/volunteer-hr-profiles/{$this->profile->id}", ['city' => 'جدة'])
            ->status();
    }

    private function assertUnchanged(): void
    {
        $this->assertSame('الرياض', $this->profile->fresh()->city, 'the profile must not have been written');
    }

    public function test_the_hr_manager_role_may_edit(): void
    {
        $this->assertSame(200, $this->edit($this->user('hr_manager')));
        $this->assertSame('جدة', $this->profile->fresh()->city);
    }

    public function test_a_department_manager_who_only_belongs_to_the_hr_department_may_not_edit(): void
    {
        $hr = $this->department('human-resources');
        $member = $this->user('department_manager');
        $member->departments()->attach($hr->id, ['is_primary' => true]);

        $this->assertSame(403, $this->edit($member));
        $this->assertUnchanged();
    }

    public function test_a_department_member_of_the_hr_department_may_not_edit(): void
    {
        $hr = $this->department('human-resources');
        $member = $this->user('department_member');
        $member->departments()->attach($hr->id, ['is_primary' => true]);

        $this->assertSame(403, $this->edit($member));
        $this->assertUnchanged();
    }

    public function test_naming_the_hr_department_in_the_free_text_field_grants_nothing(): void
    {
        $this->department('human-resources');

        $this->assertSame(403, $this->edit($this->user('department_manager', ['department' => 'human-resources'])));
        $this->assertUnchanged();
    }

    public function test_the_deputy_of_the_hr_department_does_not_inherit_the_role(): void
    {
        $hr = $this->department('human-resources');
        $deputy = $this->user('department_manager');
        $hr->update(['deputy_leader_id' => $deputy->id]);

        $this->assertSame(403, $this->edit($deputy));
        $this->assertUnchanged();
    }

    public function test_leading_another_department_does_not_help(): void
    {
        $finance = $this->department('finance');
        $leader = $this->user('department_manager');
        $finance->update(['leader_id' => $leader->id]);

        $this->assertSame(403, $this->edit($leader));
        $this->assertUnchanged();
    }

    public function test_leading_an_archived_hr_department_does_not_help(): void
    {
        $hr = $this->department('human-resources', 'archived');
        $leader = $this->user('department_manager');
        $hr->update(['leader_id' => $leader->id]);

        $this->assertSame(403, $this->edit($leader));
        $this->assertUnchanged();
    }

    public function test_leading_the_hr_department_without_the_department_manager_role_does_not_help(): void
    {
        $hr = $this->department('human-resources');
        $leader = $this->user('instructor');
        $hr->update(['leader_id' => $leader->id]);

        $this->assertSame(403, $this->edit($leader));
        $this->assertUnchanged();
    }

    public function test_the_recorded_leader_of_an_active_hr_department_acts_as_hr_manager(): void
    {
        $hr = $this->department('human-resources');
        $leader = $this->user('department_manager');
        $hr->update(['leader_id' => $leader->id]);

        $this->assertSame('hr_manager', $leader->fresh()->getDepartmentSpecializedRole());
        $this->assertSame(200, $this->edit($leader));
        $this->assertSame('جدة', $this->profile->fresh()->city);
    }

    public function test_unrelated_roles_and_guests_are_refused(): void
    {
        foreach (['student', 'volunteer', 'instructor', 'partner'] as $role) {
            $this->assertSame(403, $this->edit($this->user($role)), $role);
        }

        $this->app['auth']->forgetGuards();
        $this->putJson("/api/hr/volunteer-hr-profiles/{$this->profile->id}", ['city' => 'جدة'])->assertUnauthorized();
        $this->assertUnchanged();
    }
}
