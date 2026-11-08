<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\VolunteerOpportunity;
use App\Models\VolunteerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * The general volunteer endpoint (POST /api/volunteer-requests) must never be a way around the rules of
 * POST /api/volunteer-opportunities/{id}/applications. It used to accept `opportunity_id`, which let a
 * visitor attach an application to a draft or closed opportunity, skip the 20-character motivation and the
 * stricter answers, and trigger an unhandled unique-index violation (HTTP 500) by submitting twice.
 */
class VolunteerRequestOpportunityBindingTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about validation and lifecycle, not about rate limiting.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->department = Department::create(['name' => 'Marketing', 'name_ar' => 'التسويق والإعلام', 'slug' => 'marketing', 'status' => 'active', 'is_active' => true]);
    }

    private function opportunity(string $status): VolunteerOpportunity
    {
        $o = VolunteerOpportunity::create([
            'department_id' => $this->department->id,
            'title' => 'فرصة تطوعية: مصمم محتوى',
            'summary' => 'تبحث إدارة التسويق والإعلام عن متطوعين.',
            'responsibilities' => ['تصميم منشورات المنصة'],
            'required_skills' => ['تصميم جرافيك'],
            'volunteers_needed' => 2, 'availability' => '8 ساعات أسبوعيًا', 'duration' => '3 أشهر', 'location_mode' => 'remote',
            'application_instructions' => 'قدّم عبر المنصة.',
        ]);
        $o->forceFill([
            'status' => $status,
            'published_at' => $status === 'draft' ? null : now()->subDay(),
            'closed_at' => $status === 'closed' ? now() : null,
        ])->save();

        return $o;
    }

    /** What the five-level assessment wizard sends: no opportunity_id. */
    private function generalPayload(array $over = []): array
    {
        return array_merge([
            'full_name' => 'Sara Ahmad', 'email' => 'sara@example.com', 'phone' => '+31600000000', 'country' => 'NL', 'city' => 'Amsterdam',
            'gender' => 'female', 'desired_department' => 'التسويق والإعلام', 'agree_terms' => true,
        ], $over);
    }

    /** What the opportunity page's own form sends. */
    private function opportunityPayload(array $over = []): array
    {
        return array_merge([
            'full_name' => 'Sara Ahmad', 'email' => 'sara@example.com', 'phone' => '+31600000000', 'country' => 'NL', 'city' => 'Amsterdam',
            'gender' => 'female', 'experience_level' => 'متوسط', 'availability' => '5-10 ساعات أسبوعيًا',
            'motivation' => 'أرغب في المساهمة بخبرتي في تصميم المحتوى.', 'agree_terms' => true,
        ], $over);
    }

    // ── The general endpoint refuses the id, whatever the opportunity looks like ──────────────

    public function test_the_general_endpoint_refuses_an_opportunity_id_in_every_lifecycle_state(): void
    {
        foreach (['draft', 'published', 'closed', 'archived'] as $status) {
            $o = $this->opportunity($status);

            $this->postJson('/api/volunteer-requests', $this->generalPayload(['opportunity_id' => $o->id, 'email' => "$status@example.com"]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('opportunity_id');
        }

        $this->assertSame(0, VolunteerRequest::count(), 'nothing was stored by a refused submission');
    }

    public function test_the_general_endpoint_refuses_an_unknown_opportunity_id_with_the_same_error(): void
    {
        $this->postJson('/api/volunteer-requests', $this->generalPayload(['opportunity_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('opportunity_id');
    }

    public function test_the_general_endpoint_cannot_be_used_to_skip_the_motivation_minimum_of_an_opportunity(): void
    {
        $o = $this->opportunity('published');

        $this->postJson('/api/volunteer-requests', $this->generalPayload(['opportunity_id' => $o->id, 'motivation' => 'x']))
            ->assertStatus(422);

        $this->assertDatabaseCount('volunteer_requests', 0);
    }

    public function test_repeating_a_refused_submission_is_a_validation_error_never_a_server_error(): void
    {
        $o = $this->opportunity('published');
        $payload = $this->generalPayload(['opportunity_id' => $o->id]);

        $this->postJson('/api/volunteer-requests', $payload)->assertStatus(422);
        $this->postJson('/api/volunteer-requests', $payload)->assertStatus(422);

        $this->assertDatabaseCount('volunteer_requests', 0);
    }

    public function test_an_empty_opportunity_id_is_ignored_so_form_posts_that_leave_it_blank_still_work(): void
    {
        // A multipart form that appends an empty field arrives as null once empty strings are normalised.
        $this->postJson('/api/volunteer-requests', $this->generalPayload(['opportunity_id' => '']))->assertCreated();
        $this->postJson('/api/volunteer-requests', $this->generalPayload(['opportunity_id' => null, 'email' => 'other@example.com']))->assertCreated();

        $this->assertSame(2, VolunteerRequest::whereNull('opportunity_id')->count());
    }

    // ── The five-level assessment (no opportunity) keeps working ──────────────────────────────

    public function test_the_assessment_wizard_payload_without_an_opportunity_is_still_accepted(): void
    {
        $this->postJson('/api/volunteer-requests', $this->generalPayload(['education_level' => 'بكالوريوس', 'volunteer_days_availability' => 'مرن خلال الأسبوع']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertNull(VolunteerRequest::firstOrFail()->opportunity_id);
    }

    // ── The dedicated endpoint keeps enforcing the opportunity lifecycle ──────────────────────

    public function test_the_dedicated_endpoint_accepts_a_published_opportunity_and_binds_the_application_to_it(): void
    {
        $o = $this->opportunity('published');

        $this->postJson("/api/volunteer-opportunities/{$o->id}/applications", $this->opportunityPayload())->assertCreated();

        $this->assertSame($o->id, VolunteerRequest::firstOrFail()->opportunity_id);
    }

    public function test_the_dedicated_endpoint_refuses_draft_and_closed_opportunities(): void
    {
        $draft = $this->opportunity('draft');
        $closed = $this->opportunity('closed');

        $this->postJson("/api/volunteer-opportunities/{$draft->id}/applications", $this->opportunityPayload())->assertNotFound();
        $this->postJson("/api/volunteer-opportunities/{$closed->id}/applications", $this->opportunityPayload(['email' => 'late@example.com']))->assertStatus(409);

        $this->assertDatabaseCount('volunteer_requests', 0);
    }

    public function test_the_dedicated_endpoint_answers_a_duplicate_with_a_conflict_not_a_server_error(): void
    {
        $o = $this->opportunity('published');

        $this->postJson("/api/volunteer-opportunities/{$o->id}/applications", $this->opportunityPayload())->assertCreated();
        $this->postJson("/api/volunteer-opportunities/{$o->id}/applications", $this->opportunityPayload(['email' => 'SARA@example.com']))->assertStatus(409);

        $this->assertSame(1, VolunteerRequest::where('opportunity_id', $o->id)->count());
    }

    public function test_the_dedicated_endpoint_keeps_the_twenty_character_motivation_and_required_answers(): void
    {
        $o = $this->opportunity('published');

        $this->postJson("/api/volunteer-opportunities/{$o->id}/applications", $this->opportunityPayload(['motivation' => 'قصير']))
            ->assertStatus(422)->assertJsonValidationErrors('motivation');
        $this->postJson("/api/volunteer-opportunities/{$o->id}/applications", array_diff_key($this->opportunityPayload(), array_flip(['gender', 'experience_level', 'availability'])))
            ->assertStatus(422)->assertJsonValidationErrors(['gender', 'experience_level', 'availability']);
    }
}
