<?php

namespace Tests\Feature\Volunteers;

use App\Http\Middleware\EnsureVolunteerHrPipelineEnabled;
use App\Models\Department;
use App\Models\User;
use App\Models\VolunteerHrProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Path B (the volunteer HR-profile pipeline) is cancelled: with volunteer_onboarding.hr_profile_pipeline_enabled OFF (the default) its
 * WRITE endpoints answer 410 and its READ endpoints keep showing historical data. With the switch ON the legacy behaviour is back.
 * The full legacy behaviour is proven by the other tests in this folder, which switch it on in setUp.
 */
class VolunteerHrPipelineSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function volunteerWithProfile(): array
    {
        $user = User::factory()->create(['role' => 'volunteer', 'email' => 'vol@emc.test', 'is_active' => true]);
        $profile = VolunteerHrProfile::create([
            'full_name' => 'متطوع', 'email' => 'vol@emc.test', 'normalized_email' => 'vol@emc.test', 'phone' => '0501234567',
            'job_title' => 'متطوع', 'join_date' => now()->toDateString(), 'cv_path' => 'cvs/sample.pdf',
            'user_id' => $user->id, 'status' => 'submitted', 'city' => 'الرياض',
        ]);

        return [$user, $profile];
    }

    private function hr(): User
    {
        return User::factory()->create(['role' => 'hr_manager', 'is_active' => true]);
    }

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    public function test_the_switch_is_off_by_default(): void
    {
        $this->assertFalse(config('volunteer_onboarding.hr_profile_pipeline_enabled'));
    }

    public function test_with_the_switch_off_every_write_endpoint_answers_410_in_arabic(): void
    {
        [$user, $profile] = $this->volunteerWithProfile();
        $dept = Department::create(['name' => 'T', 'name_ar' => 'التقنية', 'slug' => 'tech', 'is_active' => true]);
        $hr = $this->hr();

        $calls = [
            [$user, 'postJson',  '/api/volunteer/hr-profile'],
            [$user, 'putJson',   "/api/volunteer/hr-profile/{$profile->id}"],
            [$hr,   'putJson',   "/api/hr/volunteer-hr-profiles/{$profile->id}"],
            [$hr,   'patchJson', "/api/hr/volunteer-hr-profiles/{$profile->id}/review"],
            [$hr,   'postJson',  "/api/hr/volunteer-hr-profiles/{$profile->id}/approve"],
            [$hr,   'postJson',  "/api/hr/volunteer-hr-profiles/{$profile->id}/reject"],
        ];

        foreach ($calls as [$actor, $method, $uri]) {
            $this->as($actor)->{$method}($uri, ['department_id' => $dept->id])
                ->assertStatus(410)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', EnsureVolunteerHrPipelineEnabled::MESSAGE);
        }

        $this->assertSame(EnsureVolunteerHrPipelineEnabled::MESSAGE, 'تم إيقاف هذا المسار. التقديم للتطوع يتم الآن عبر طلب التطوع، وبعد قبوله تُنشأ لك عضوية وحساب.');
        $this->assertSame('submitted', $profile->fresh()->status, 'nothing was changed');
        $this->assertSame(1, VolunteerHrProfile::count(), 'nothing was created');
    }

    public function test_with_the_switch_off_historical_profiles_can_still_be_read(): void
    {
        [$user, $profile] = $this->volunteerWithProfile();
        $hr = $this->hr();

        $this->as($user)->getJson('/api/volunteer/hr-profile')->assertOk();
        $this->as($hr)->getJson('/api/hr/volunteer-hr-profiles')->assertOk();
        $this->as($hr)->getJson("/api/hr/volunteer-hr-profiles/{$profile->id}")->assertOk()->assertJsonPath('data.id', $profile->id);
        $this->as($hr)->getJson('/api/hr/volunteer-hr-profiles/filter-options')->assertOk();
    }

    public function test_with_the_switch_on_the_legacy_endpoints_work_again(): void
    {
        config(['volunteer_onboarding.hr_profile_pipeline_enabled' => true]);
        [$user, $profile] = $this->volunteerWithProfile();

        // review moves a submitted profile into review (the 410 gate is gone)
        $this->as($this->hr())->patchJson("/api/hr/volunteer-hr-profiles/{$profile->id}/review")->assertOk();
        $this->assertSame('under_review', $profile->fresh()->status);

        // the volunteer's own endpoint reaches validation instead of 410
        $this->as($user)->postJson('/api/volunteer/hr-profile', [])->assertStatus(422);
    }

    public function test_authorization_still_comes_before_the_gate(): void
    {
        [, $profile] = $this->volunteerWithProfile();
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $this->as($student)->postJson("/api/hr/volunteer-hr-profiles/{$profile->id}/approve")->assertStatus(403);
    }
}
