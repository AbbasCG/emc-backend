<?php

namespace Tests\Feature\Forms;

use App\Models\DynamicForm;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * When a published form takes answers: schedule, limits and sign-in, all decided on the server.
 */
class FormAvailabilityTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private $owner;

    private $form;

    private int $q;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('operations_manager');
        $res = $this->actingAs($this->owner, 'sanctum')->postJson('/api/operations/forms', [
            'title' => 'متاح', 'status' => 'active', 'questions' => [['label' => 'س', 'type' => 'text']],
        ])->assertCreated();
        $this->form = DynamicForm::findOrFail($res->json('data.id'));
        $this->q = $this->form->questions->first()->id;
    }

    private function settings(array $payload)
    {
        return $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/settings", $payload);
    }

    /** A fresh request from a visitor: the test process keeps the staff member from settings() logged in otherwise. */
    private function asVisitor(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
    }

    private function submit(?string $token = null, string $answer = 'ok')
    {
        $this->asVisitor();
        $client = $token ? $this->withToken($token) : $this;

        return $client->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$this->q => $answer]]);
    }

    private function state(): string
    {
        $this->asVisitor();

        return $this->getJson("/api/forms/{$this->form->slug}")->assertOk()->json('data.availability.state');
    }

    // ── Opening and closing dates ────────────────────────────────────────────

    public function test_before_the_opening_date_the_form_says_so_and_takes_no_answers(): void
    {
        $this->settings(['opens_at' => now()->addDay()->toIso8601String()])->assertOk();

        $this->assertSame('not_open_yet', $this->state());
        $this->getJson("/api/forms/{$this->form->slug}")->assertJsonPath('data.questions', [])->assertJsonPath('data.availability.opens_at', fn ($v) => $v !== null);
        $this->submit()->assertForbidden()->assertJsonPath('code', 'not_open_yet');
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_after_the_closing_date_the_form_says_so_and_takes_no_answers(): void
    {
        $this->settings(['closes_at' => now()->subMinute()->toIso8601String()])->assertOk();

        $this->assertSame('ended', $this->state());
        $this->submit()->assertForbidden()->assertJsonPath('code', 'ended');
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_inside_the_window_it_is_open_and_the_dates_are_evaluated_when_the_answer_arrives(): void
    {
        $this->settings(['opens_at' => now()->subHour()->toIso8601String(), 'closes_at' => now()->addHour()->toIso8601String()])->assertOk();
        $this->assertSame('open', $this->state());
        $this->submit()->assertCreated();

        $this->travel(2)->hours();

        $this->assertSame('ended', $this->state());
        $this->submit()->assertForbidden();
    }

    public function test_the_closing_date_must_be_after_the_opening_date(): void
    {
        $this->settings(['opens_at' => now()->addDays(2)->toIso8601String(), 'closes_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('closes_at');
        $this->settings(['opens_at' => 'not a date'])->assertStatus(422)->assertJsonValidationErrors('opens_at');
        $this->assertNull($this->form->fresh()->opens_at);
    }

    public function test_the_schedule_can_be_cleared(): void
    {
        $this->settings(['closes_at' => now()->subMinute()->toIso8601String()])->assertOk();
        $this->settings(['closes_at' => null])->assertOk();

        $this->assertSame('open', $this->state());
    }

    // ── Maximum submissions ──────────────────────────────────────────────────

    public function test_the_maximum_number_of_submissions_is_enforced(): void
    {
        $this->settings(['max_submissions' => 2])->assertOk();

        $this->submit()->assertCreated();
        $this->submit()->assertCreated();
        $this->submit()->assertForbidden()->assertJsonPath('code', 'limit_reached');

        $this->assertSame(2, FormSubmission::count());
        $this->assertSame('limit_reached', $this->state());
    }

    public function test_raising_the_limit_reopens_a_full_form_and_it_is_never_below_one(): void
    {
        $this->settings(['max_submissions' => 1])->assertOk();
        $this->submit()->assertCreated();
        $this->submit()->assertForbidden();

        $this->settings(['max_submissions' => 2])->assertOk();
        $this->submit()->assertCreated();
        $this->settings(['max_submissions' => 0])->assertStatus(422)->assertJsonValidationErrors('max_submissions');
        $this->settings(['max_submissions' => -3])->assertStatus(422);
    }

    public function test_a_failed_submission_does_not_use_up_the_allowance(): void
    {
        $this->settings(['max_submissions' => 1])->assertOk();
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$this->q => str_repeat('x', 1001)]])->assertStatus(422);

        $this->submit()->assertCreated();
    }

    // ── Sign-in, anonymous respondents and the per-respondent limit ─────────

    public function test_a_form_that_does_not_allow_anonymous_answers_refuses_visitors_and_accepts_signed_in_people(): void
    {
        $this->settings(['allow_anonymous' => false])->assertOk();
        $user = $this->makeUser('student');
        $token = $user->createToken('t')->plainTextToken;

        $this->assertSame('login_required', $this->state());
        $this->asVisitor();
        $this->getJson("/api/forms/{$this->form->slug}")->assertJsonPath('data.questions', [])->assertJsonPath('data.availability.requires_login', true);
        $this->submit()->assertUnauthorized()->assertJsonPath('code', 'login_required');
        $this->assertSame(0, FormSubmission::count());

        $this->submit($token)->assertCreated();
        $this->assertSame($user->id, (int) FormSubmission::first()->user_id);
    }

    public function test_the_signed_in_respondent_sees_the_questions(): void
    {
        $this->settings(['allow_anonymous' => false])->assertOk();
        $token = $this->makeUser('student')->createToken('t')->plainTextToken;

        $this->asVisitor();
        $this->withToken($token)->getJson("/api/forms/{$this->form->slug}")->assertOk()->assertJsonPath('data.availability.state', 'open')->assertJsonCount(1, 'data.questions');
    }

    public function test_a_bad_or_foreign_token_does_not_get_around_the_sign_in_requirement(): void
    {
        $this->settings(['allow_anonymous' => false])->assertOk();

        $this->submit('999|not-a-real-token')->assertUnauthorized();
        $this->asVisitor();
        $this->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => [$this->q => 'x'], 'user_id' => 1, 'authenticated' => true])->assertUnauthorized();
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_the_per_respondent_limit_counts_each_signed_in_person_separately(): void
    {
        $this->settings(['per_respondent_limit' => 1])->assertOk();
        $a = $this->makeUser('student')->createToken('t')->plainTextToken;
        $b = $this->makeUser('student')->createToken('t')->plainTextToken;

        $this->submit($a)->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->submit($a)->assertForbidden()->assertJsonPath('code', 'respondent_limit');
        $this->app['auth']->forgetGuards();
        $this->submit($b)->assertCreated();

        $this->assertSame(2, FormSubmission::count());
    }

    public function test_a_per_respondent_limit_makes_signing_in_mandatory_so_it_cannot_be_dodged_anonymously(): void
    {
        $this->settings(['per_respondent_limit' => 1, 'allow_anonymous' => true])->assertOk()->assertJsonPath('data.settings.requires_login', true);

        $this->assertSame('login_required', $this->state());
        $this->submit()->assertUnauthorized();
        $this->submit()->assertUnauthorized();
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_anonymous_forms_without_an_identity_limit_still_count_everyone_towards_the_overall_maximum(): void
    {
        $this->settings(['max_submissions' => 3])->assertOk();

        for ($i = 0; $i < 3; $i++) {
            $this->submit(null, (string) $i)->assertCreated();
        }
        $this->submit()->assertForbidden();
    }

    // ── Settings are not content ─────────────────────────────────────────────

    public function test_changing_availability_is_not_a_content_change_and_makes_no_version(): void
    {
        $this->settings(['max_submissions' => 10, 'closes_at' => now()->addWeek()->toIso8601String(), 'allow_anonymous' => false])->assertOk()
            ->assertJsonPath('data.has_unpublished_changes', false)->assertJsonPath('data.settings.max_submissions', 10);

        $this->assertSame(1, FormVersion::count());
    }

    public function test_availability_applies_at_once_to_a_live_form_without_republishing(): void
    {
        $this->settings(['max_submissions' => 1])->assertOk();
        $this->submit()->assertCreated();
        $this->submit()->assertForbidden();

        $this->settings(['max_submissions' => null])->assertOk();

        $this->submit()->assertCreated();
        $this->assertSame(1, FormVersion::count());
    }

    public function test_only_people_who_can_change_the_form_can_change_its_availability(): void
    {
        $stranger = $this->makeUser('community_manager');

        $this->actingAs($stranger, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/settings", ['max_submissions' => 1])->assertForbidden();
        $this->actingAs($this->makeUser('student'), 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/settings", ['max_submissions' => 1])->assertForbidden();
        $this->assertNull($this->form->fresh()->max_submissions);
    }

    public function test_the_draft_status_closed_status_and_the_public_link_are_unaffected_by_settings(): void
    {
        $this->settings(['opens_at' => now()->subDay()->toIso8601String()])->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/close")->assertOk();

        $this->assertSame('closed', $this->state());
        $this->getJson('/api/forms/'.$this->form->slug)->assertOk();
    }
}
