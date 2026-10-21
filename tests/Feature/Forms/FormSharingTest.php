<?php

namespace Tests\Feature\Forms;

use App\Models\ActivityLog;
use App\Models\DynamicForm;
use App\Models\FormInvitation;
use App\Models\FormShareLink;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Secure sharing of a form: share links (hashed, expiring, limited, revocable), the three access modes, invitations, and who may
 * manage any of it. Every refusal is checked at the server, through the same endpoints a browser uses.
 */
class FormSharingTest extends TestCase
{
    use MakesForms;
    use RefreshDatabase;

    private $owner;

    private DynamicForm $form;

    protected function setUp(): void
    {
        parent::setUp();
        // The per-address submission limiter has its own test (PublicFormSubmissionTest); these tests submit many times.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->owner = $this->makeUser('operations_manager');
        $this->form = $this->publishedForm();
    }

    private function publishedForm(string $title = 'استبيان'): DynamicForm
    {
        $id = $this->as($this->owner)->postJson('/api/operations/forms', ['title' => $title, 'questions' => [['type' => 'text', 'label' => 'الاسم', 'is_required' => true]]])->assertCreated()->json('data.id');
        $this->as($this->owner)->postJson("/api/operations/forms/$id/publish")->assertOk();

        return DynamicForm::findOrFail($id);
    }

    private function as($user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function sharing(array $payload = [], ?DynamicForm $form = null, $user = null)
    {
        $form ??= $this->form;

        return $this->as($user ?? $this->owner)->putJson("/api/operations/forms/{$form->id}/sharing", $payload);
    }

    /** @return array<string, mixed> the created link, including its one-time token */
    private function makeLink(array $payload = [], ?DynamicForm $form = null): array
    {
        $form ??= $this->form;

        return $this->as($this->owner)->postJson("/api/operations/forms/{$form->id}/share-links", $payload + ['label' => 'ملصق المدخل'])->assertCreated()->json('data');
    }

    private function openForm(?string $token = null, ?DynamicForm $form = null, $user = null)
    {
        $form ??= $this->form;
        $headers = $token ? ['X-Form-Share' => $token] : [];
        $client = $user ? $this->actingAs($user, 'sanctum') : $this->anonymous();

        return $client->getJson("/api/forms/{$form->slug}", $headers);
    }

    private function submit(?string $token = null, ?DynamicForm $form = null, $user = null, string $answer = 'سارة')
    {
        $form ??= $this->form;
        $headers = $token ? ['X-Form-Share' => $token] : [];
        $id = $form->questions()->first()->id;
        $client = $user ? $this->actingAs($user, 'sanctum') : $this->anonymous();

        return $client->postJson("/api/forms/{$form->slug}/submit", ['answers' => [$id => $answer]], $headers);
    }

    /** A request with nobody signed in: an earlier actingAs() in the same test would otherwise carry over. */
    private function anonymous(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function state($response): string
    {
        return $response->json('data.availability.state');
    }

    // ── Links: creation and storage ──────────────────────────────────────────

    public function test_a_link_is_made_with_a_token_that_is_shown_once_and_only_its_hash_is_stored(): void
    {
        $link = $this->makeLink(['label' => '  ملصق المدخل  ', 'max_uses' => 5]);

        $this->assertSame(48, strlen($link['token']));
        $this->assertSame("/forms/{$this->form->slug}/s/{$link['token']}", $link['path']);
        $this->assertSame('ملصق المدخل', $link['label']);
        $this->assertSame('active', $link['status']);

        $row = FormShareLink::findOrFail($link['id']);
        $this->assertSame(hash('sha256', $link['token']), $row->token_hash);
        $this->assertSame(substr($link['token'], -4), $row->token_hint);
        foreach ($row->getAttributes() as $column => $value) {
            $this->assertStringNotContainsString($link['token'], (string) $value, "the token is not stored in $column");
        }

        // Nothing can show it again: not the screen's data, not the history.
        $screen = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/sharing")->assertOk();
        $this->assertStringNotContainsString($link['token'], $screen->getContent());
        $this->assertStringNotContainsString($link['token'], json_encode(ActivityLog::all()->toArray()));
        $this->assertArrayNotHasKey('token', $screen->json('data.links.0'));
        $this->assertArrayNotHasKey('token_hash', $screen->json('data.links.0'));
    }

    public function test_the_label_dates_and_limits_are_checked_and_clear_messages_are_given(): void
    {
        $post = fn (array $p) => $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/share-links", $p);

        $post([])->assertStatus(422)->assertJsonValidationErrors('label');
        $post(['label' => '   '])->assertStatus(422)->assertJsonValidationErrors('label');
        $post(['label' => str_repeat('ا', 101)])->assertStatus(422)->assertJsonValidationErrors('label');
        $post(['label' => 'x', 'max_uses' => 0])->assertStatus(422)->assertJsonValidationErrors('max_uses');
        $post(['label' => 'x', 'max_uses' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('max_uses');
        $post(['label' => 'x', 'expires_at' => 'not a date'])->assertStatus(422)->assertJsonValidationErrors('expires_at');
        $post(['label' => 'x', 'expires_at' => now()->subHour()->toIso8601String()])->assertStatus(422)->assertJsonValidationErrors('expires_at');
        $this->assertSame(0, FormShareLink::count());
    }

    public function test_the_number_of_active_links_is_capped_and_revoking_frees_room(): void
    {
        for ($i = 0; $i < 50; $i++) {
            FormShareLink::create(['form_id' => $this->form->id, 'label' => "l$i", 'token_hash' => hash('sha256', "t$i"), 'token_hint' => 'abcd']);
        }
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/share-links", ['label' => 'واحد زائد'])->assertStatus(422)->assertJsonValidationErrors('form');

        FormShareLink::first()->update(['revoked_at' => now()]);
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/share-links", ['label' => 'واحد زائد'])->assertCreated();
    }

    // ── Links: using them ────────────────────────────────────────────────────

    public function test_a_valid_link_opens_the_form_and_the_response_is_tied_to_it_by_its_label(): void
    {
        $link = $this->makeLink(['label' => 'حملة الجامعة']);

        $this->openForm($link['token'])->assertOk()->assertJsonPath('data.availability.state', 'open')->assertJsonPath('data.title', 'استبيان');
        $res = $this->submit($link['token'])->assertCreated();

        $submission = FormSubmission::findOrFail($res->json('data.submission_id'));
        $this->assertSame($link['id'], (int) $submission->share_link_id);

        $list = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/submissions")->assertOk();
        $this->assertSame('حملة الجامعة', $list->json('data.data.0.share_link.label'));
        $this->assertStringNotContainsString($link['token'], $list->getContent());
    }

    public function test_the_existing_address_keeps_working_when_no_link_is_required(): void
    {
        $this->makeLink();

        $this->openForm()->assertOk()->assertJsonPath('data.availability.state', 'open');
        $this->submit()->assertCreated();
        $this->assertNull(FormSubmission::first()->share_link_id, 'an answer through the plain address belongs to no link');
    }

    public function test_a_token_that_is_wrong_or_belongs_to_another_form_is_refused_even_on_an_open_form(): void
    {
        $other = $this->publishedForm('نموذج آخر');
        $foreign = $this->makeLink([], $other);

        foreach (['garbage', str_repeat('a', 48), $foreign['token'], str_repeat('x', 500)] as $token) {
            $this->openForm($token)->assertOk()->assertJsonPath('data.availability.state', 'share_link_invalid')->assertJsonCount(0, 'data.questions');
            $this->submit($token)->assertForbidden()->assertJsonPath('code', 'share_link_invalid');
        }
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_a_form_that_requires_a_link_shows_nothing_about_itself_without_one(): void
    {
        $link = $this->makeLink();
        $this->sharing(['access_mode' => 'public', 'requires_share_link' => true])->assertOk();

        $res = $this->openForm()->assertOk();
        $res->assertJsonPath('data.availability.state', 'share_link_required')->assertJsonPath('data.title', 'نموذج مقيَّد')->assertJsonPath('data.description', null)->assertJsonCount(0, 'data.questions');
        $this->assertStringNotContainsString('استبيان', $res->getContent());
        $this->assertStringNotContainsString('الاسم', $res->getContent());
        $this->submit()->assertForbidden()->assertJsonPath('code', 'share_link_required');

        $this->openForm($link['token'])->assertOk()->assertJsonPath('data.title', 'استبيان');
        $this->submit($link['token'])->assertCreated();
    }

    public function test_requiring_a_link_needs_a_link_to_exist_so_nobody_is_locked_out(): void
    {
        $this->sharing(['access_mode' => 'public', 'requires_share_link' => true])->assertStatus(422)->assertJsonValidationErrors('requires_share_link');
        $this->assertFalse((bool) $this->form->fresh()->requires_share_link);
    }

    public function test_an_expired_link_is_refused_and_expiry_is_judged_when_the_answer_is_sent(): void
    {
        $link = $this->makeLink(['expires_at' => now()->addHour()->toIso8601String()]);
        $this->openForm($link['token'])->assertJsonPath('data.availability.state', 'open');

        $this->travel(2)->hours();

        $this->openForm($link['token'])->assertOk()->assertJsonPath('data.availability.state', 'share_link_expired')->assertJsonCount(0, 'data.questions');
        $this->submit($link['token'])->assertForbidden()->assertJsonPath('code', 'share_link_expired');
        $this->assertSame('expired', $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/sharing")->json('data.links.0.status'));
        $this->assertSame(0, FormSubmission::count());
    }

    public function test_a_revoked_link_stops_at_once_everywhere_and_nothing_historic_is_lost(): void
    {
        $link = $this->makeLink();
        $this->submit($link['token'])->assertCreated();

        $res = $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/share-links/{$link['id']}/revoke")->assertOk();
        $res->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.revoked_by.id', $this->owner->id);

        $this->openForm($link['token'])->assertOk()->assertJsonPath('data.availability.state', 'share_link_revoked');
        $this->submit($link['token'])->assertForbidden()->assertJsonPath('code', 'share_link_revoked');
        // The plain address is a separate door and is unaffected (this form does not require a link).
        $this->submit()->assertCreated();

        $this->assertSame(2, FormSubmission::count());
        $this->assertSame($link['id'], (int) FormSubmission::orderBy('id')->first()->share_link_id, 'the response still points at the revoked link');
        $row = FormShareLink::findOrFail($link['id']);
        $this->assertNotNull($row->revoked_at);
        $this->assertSame($this->owner->id, (int) $row->revoked_by);
        $this->assertSame($this->owner->id, (int) $row->created_by);
    }

    public function test_revoking_twice_changes_nothing_and_records_one_history_line(): void
    {
        $link = $this->makeLink();
        $url = "/api/operations/forms/{$this->form->id}/share-links/{$link['id']}/revoke";
        $this->as($this->owner)->postJson($url)->assertOk();
        $first = FormShareLink::find($link['id'])->revoked_at;
        $this->travel(1)->hours();
        $this->as($this->owner)->postJson($url)->assertOk();

        $this->assertEquals($first, FormShareLink::find($link['id'])->revoked_at);
        $this->assertSame(1, ActivityLog::where('entity_id', $this->form->id)->where('action', 'form.share_link_revoked')->count());
    }

    public function test_the_limit_counts_submissions_through_the_link_not_visits_and_other_links_are_untouched(): void
    {
        $limited = $this->makeLink(['label' => 'محدود', 'max_uses' => 2]);
        $open = $this->makeLink(['label' => 'مفتوح']);

        for ($i = 0; $i < 5; $i++) {
            $this->openForm($limited['token'])->assertJsonPath('data.availability.state', 'open'); // looking uses nothing
        }
        $this->submit($limited['token'])->assertCreated();
        $this->submit($open['token'])->assertCreated();
        $this->submit($limited['token'])->assertCreated();

        $this->openForm($limited['token'])->assertJsonPath('data.availability.state', 'share_link_exhausted');
        $this->submit($limited['token'])->assertForbidden()->assertJsonPath('code', 'share_link_exhausted');
        $this->submit($open['token'])->assertCreated();
        $this->submit()->assertCreated();

        $rows = collect($this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/sharing")->json('data.links'))->keyBy('label');
        $this->assertSame(['exhausted', 2, 2], [$rows['محدود']['status'], $rows['محدود']['uses'], $rows['محدود']['max_uses']]);
        $this->assertSame(['active', 2], [$rows['مفتوح']['status'], $rows['مفتوح']['uses']]);
    }

    public function test_the_forms_own_limit_and_dates_still_apply_through_a_link(): void
    {
        $link = $this->makeLink();
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/settings", ['max_submissions' => 1])->assertOk();
        $this->submit($link['token'])->assertCreated();
        $this->submit($link['token'])->assertForbidden()->assertJsonPath('code', 'limit_reached');

        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/settings", ['max_submissions' => null, 'closes_at' => now()->addMinute()->toIso8601String()])->assertOk();
        $this->travel(5)->minutes();
        $this->submit($link['token'])->assertForbidden()->assertJsonPath('code', 'ended');
    }

    // ── Drafts, closed and archived forms ────────────────────────────────────

    public function test_a_draft_is_never_exposed_by_a_link_and_a_link_may_be_prepared_for_it(): void
    {
        $draftId = $this->as($this->owner)->postJson('/api/operations/forms', ['title' => 'مسودة', 'questions' => [['type' => 'text', 'label' => 'س']]])->json('data.id');
        $draft = DynamicForm::findOrFail($draftId);

        $link = $this->makeLink(['label' => 'للمطبوعات'], $draft);
        $this->openForm($link['token'], $draft)->assertNotFound();
        $this->submit($link['token'], $draft)->assertNotFound();

        $status = $this->as($this->owner)->getJson("/api/operations/forms/$draftId/sharing")->json('data.public');
        $this->assertSame(['draft', false, false], [$status['state'], $status['reachable'], $status['accepting']]);

        // Once published the prepared link works.
        $this->as($this->owner)->postJson("/api/operations/forms/$draftId/publish")->assertOk();
        $this->openForm($link['token'], $draft)->assertOk()->assertJsonPath('data.availability.state', 'open');
    }

    public function test_an_archived_form_answers_404_gets_no_new_links_and_its_links_can_still_be_revoked(): void
    {
        $link = $this->makeLink();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/archive")->assertOk();

        $this->openForm($link['token'])->assertNotFound();
        $this->submit($link['token'])->assertNotFound();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/share-links", ['label' => 'جديد'])->assertStatus(422)->assertJsonValidationErrors('form');
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/share-links/{$link['id']}/revoke")->assertOk();
        $this->assertSame('archived', $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/sharing")->json('data.public.state'));
    }

    public function test_a_closed_form_says_it_is_closed_even_to_a_valid_link(): void
    {
        $link = $this->makeLink();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/close")->assertOk();

        $this->openForm($link['token'])->assertOk()->assertJsonPath('data.availability.state', 'closed');
        $this->submit($link['token'])->assertForbidden()->assertJsonPath('code', 'closed');
        $this->assertSame(['closed', true, false], array_values(array_intersect_key($this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/sharing")->json('data.public'), array_flip(['state', 'reachable', 'accepting']))));
    }

    public function test_the_published_version_is_what_a_link_serves_not_the_draft(): void
    {
        $link = $this->makeLink();
        $id = $this->form->questions()->first()->id;
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}", ['title' => 'عنوان المسودة', 'questions' => [['id' => $id, 'type' => 'text', 'label' => 'سؤال المسودة']]])->assertOk();

        $res = $this->openForm($link['token'])->assertOk();
        $res->assertJsonPath('data.title', 'استبيان')->assertJsonPath('data.questions.0.label', 'الاسم');
    }

    // ── Access modes ─────────────────────────────────────────────────────────

    public function test_the_three_modes_map_onto_the_stored_flags_and_the_existing_anonymous_setting_agrees(): void
    {
        $mode = fn () => $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}")->json('data.settings');

        $this->assertSame(['public', true], [$mode()['access_mode'], $mode()['allow_anonymous']]);
        $this->sharing(['access_mode' => 'authenticated', 'requires_share_link' => false])->assertOk()->assertJsonPath('data.access.mode', 'authenticated');
        $this->assertSame(['authenticated', false, true], [$mode()['access_mode'], $mode()['allow_anonymous'], $mode()['requires_login']]);
        $this->sharing(['access_mode' => 'invited', 'requires_share_link' => false])->assertOk();
        $this->assertSame(['invited', false, true], [$mode()['access_mode'], $mode()['allow_anonymous'], $mode()['requires_login']]);
        $this->sharing(['access_mode' => 'public', 'requires_share_link' => false])->assertOk();
        $this->assertSame(['public', true], [$mode()['access_mode'], $mode()['allow_anonymous']]);

        // The Phase 1 settings endpoint still works and is read back as the same mode.
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/settings", ['allow_anonymous' => false])->assertOk();
        $this->assertSame('authenticated', $mode()['access_mode']);

        $this->sharing(['access_mode' => 'everyone', 'requires_share_link' => false])->assertStatus(422)->assertJsonValidationErrors('access_mode');
        $this->sharing(['access_mode' => 'public'])->assertStatus(422)->assertJsonValidationErrors('requires_share_link');
    }

    public function test_changing_the_mode_is_recorded_and_an_unchanged_save_is_not(): void
    {
        $this->sharing(['access_mode' => 'authenticated', 'requires_share_link' => false])->assertOk();
        $this->sharing(['access_mode' => 'authenticated', 'requires_share_link' => false])->assertOk();

        $lines = ActivityLog::where('entity_id', $this->form->id)->where('action', 'form.access_changed')->get();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('عام', $lines[0]->new_values['changes'][0]);
        $this->assertStringContainsString('مستخدمو EMC المسجّلون', $lines[0]->new_values['changes'][0]);
    }

    public function test_an_authenticated_form_needs_a_signed_in_respondent(): void
    {
        $this->sharing(['access_mode' => 'authenticated', 'requires_share_link' => false])->assertOk();
        $student = $this->makeUser('student');

        $this->openForm()->assertOk()->assertJsonPath('data.availability.state', 'login_required');
        $this->submit()->assertUnauthorized()->assertJsonPath('code', 'login_required');
        $this->openForm(null, null, $student)->assertJsonPath('data.availability.state', 'open');
        $this->submit(null, null, $student)->assertCreated();
        $this->assertSame($student->id, (int) FormSubmission::first()->user_id);
    }

    // ── Invitations ──────────────────────────────────────────────────────────

    private function invitedForm(): array
    {
        $this->sharing(['access_mode' => 'invited', 'requires_share_link' => false])->assertOk();
        $invited = $this->makeUser('student');
        $stranger = $this->makeUser('student');
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/invitations", ['user_id' => $invited->id])->assertCreated();

        return [$invited, $stranger];
    }

    public function test_only_the_invited_signed_in_user_can_open_or_answer_a_restricted_form(): void
    {
        [$invited, $stranger] = $this->invitedForm();

        // Nobody who is not signed in learns anything, not even the title.
        $anon = $this->openForm()->assertOk();
        $anon->assertJsonPath('data.availability.state', 'login_required')->assertJsonPath('data.title', 'نموذج مقيَّد')->assertJsonCount(0, 'data.questions');
        $this->submit()->assertUnauthorized();

        $notInvited = $this->openForm(null, null, $stranger)->assertOk();
        $notInvited->assertJsonPath('data.availability.state', 'not_invited')->assertJsonPath('data.title', 'نموذج مقيَّد')->assertJsonCount(0, 'data.questions');
        $this->assertStringNotContainsString('استبيان', $notInvited->getContent());
        $this->submit(null, null, $stranger)->assertForbidden()->assertJsonPath('code', 'not_invited');
        $this->assertSame(0, FormSubmission::count());

        $this->openForm(null, null, $invited)->assertOk()->assertJsonPath('data.availability.state', 'open')->assertJsonPath('data.title', 'استبيان');
        $this->submit(null, null, $invited)->assertCreated();
        $this->assertSame($invited->id, (int) FormSubmission::first()->user_id);
    }

    public function test_a_share_link_never_stands_in_for_being_the_invited_person(): void
    {
        [$invited, $stranger] = $this->invitedForm();
        $link = $this->makeLink();

        $this->openForm($link['token'])->assertJsonPath('data.availability.state', 'login_required');
        $this->submit($link['token'])->assertUnauthorized();
        $this->openForm($link['token'], null, $stranger)->assertJsonPath('data.availability.state', 'not_invited');
        $this->submit($link['token'], null, $stranger)->assertForbidden()->assertJsonPath('code', 'not_invited');
        $this->assertSame(0, FormSubmission::count());

        // The invited person, through the link, is fine and the response is tied to both.
        $this->submit($link['token'], null, $invited)->assertCreated();
        $this->assertSame([$invited->id, $link['id']], [(int) FormSubmission::first()->user_id, (int) FormSubmission::first()->share_link_id]);
    }

    public function test_revoking_an_invitation_takes_the_access_back_and_keeps_what_was_answered(): void
    {
        [$invited] = $this->invitedForm();
        $this->submit(null, null, $invited)->assertCreated();
        $invitation = FormInvitation::first();

        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/invitations/{$invitation->id}/revoke")->assertOk()->assertJsonPath('data.revoked_by.id', $this->owner->id);

        $this->submit(null, null, $invited)->assertForbidden()->assertJsonPath('code', 'not_invited');
        $this->assertSame(1, FormSubmission::count());
        $this->assertNotNull($invitation->fresh()->revoked_at);
    }

    public function test_inviting_is_checked_and_a_revoked_invitation_is_brought_back_not_duplicated(): void
    {
        $this->sharing(['access_mode' => 'invited', 'requires_share_link' => false])->assertOk();
        $user = $this->makeUser('student');
        $inactive = $this->makeUser('student');
        $inactive->forceFill(['is_active' => false])->save();
        $invite = fn ($id) => $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/invitations", ['user_id' => $id]);

        $invite(null)->assertStatus(422)->assertJsonValidationErrors('user_id');
        $invite(999999)->assertStatus(422)->assertJsonValidationErrors('user_id');
        $invite($inactive->id)->assertStatus(422)->assertJsonValidationErrors('user_id');
        $invite($user->id)->assertCreated();
        $invite($user->id)->assertStatus(422)->assertJsonValidationErrors('user_id');

        $id = FormInvitation::first()->id;
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/invitations/$id/revoke")->assertOk();
        $invite($user->id)->assertCreated();

        $this->assertSame(1, FormInvitation::count());
        $this->assertNull(FormInvitation::first()->revoked_at);
    }

    public function test_candidates_are_names_only_active_not_yet_invited_and_need_three_characters(): void
    {
        $this->sharing(['access_mode' => 'invited', 'requires_share_link' => false])->assertOk();
        $a = User::factory()->create(['role' => 'student', 'name' => 'سلمى العتيبي', 'email' => 'salma@example.test']);
        User::factory()->create(['role' => 'student', 'name' => 'سلمان الغامدي', 'is_active' => false]);
        $b = User::factory()->create(['role' => 'instructor', 'name' => 'سلطان القحطاني']);
        $search = fn (string $q) => $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/invitation-candidates?q=".urlencode($q));

        $this->assertSame([], $search('سل')->assertOk()->json('data'));
        $res = $search('سل' . 'ط');
        $this->assertSame([['id' => $b->id, 'name' => 'سلطان القحطاني']], $res->json('data'));
        $found = $search('سلم')->json('data');
        $this->assertSame([['id' => $a->id, 'name' => 'سلمى العتيبي']], $found, 'the inactive "سلمان" is not offered, and only id and name are returned');
        $this->assertStringNotContainsString('salma@example.test', $search('سلم')->getContent());

        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/invitations", ['user_id' => $a->id])->assertCreated();
        $this->assertSame([], $search('سلم')->json('data'), 'someone already invited is not offered again');
    }

    // ── Who may manage ───────────────────────────────────────────────────────

    public function test_management_is_for_the_owner_and_admins_and_reading_for_whoever_may_edit(): void
    {
        $viewer = $this->makeUser('operations_manager');
        $editor = $this->makeUser('operations_manager');
        $stranger = $this->makeUser('operations_manager');
        $admin = $this->makeUser('admin');
        $student = $this->makeUser('student');
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $viewer->id, 'access_level' => 'viewer'])->assertCreated();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $editor->id, 'access_level' => 'editor'])->assertCreated();
        $link = $this->makeLink();
        $base = "/api/operations/forms/{$this->form->id}";

        $writes = fn ($who) => [
            $this->as($who)->putJson("$base/sharing", ['access_mode' => 'public', 'requires_share_link' => false])->getStatusCode(),
            $this->as($who)->postJson("$base/share-links", ['label' => 'x'])->getStatusCode(),
            $this->as($who)->postJson("$base/share-links/{$link['id']}/revoke")->getStatusCode(),
            $this->as($who)->postJson("$base/invitations", ['user_id' => $student->id])->getStatusCode(),
            $this->as($who)->getJson("$base/invitation-candidates?q=abc")->getStatusCode(),
        ];

        foreach ([$viewer, $stranger, $student] as $who) {
            $this->as($who)->getJson("$base/sharing")->assertForbidden();
        }
        $this->as($editor)->getJson("$base/sharing")->assertOk()->assertJsonPath('data.abilities.manage', false);
        $this->assertSame([403, 403, 403, 403, 403], $writes($editor), 'an editor sees how the form is shared but cannot change it');
        $this->assertSame([403, 403, 403, 403, 403], $writes($viewer));
        $this->assertSame([403, 403, 403, 403, 403], $writes($stranger));
        $this->assertSame([403, 403, 403, 403, 403], $writes($student));
        $this->assertSame('active', FormShareLink::find($link['id'])->statusFor(0), 'no refused attempt changed anything');

        $this->as($admin)->getJson("$base/sharing")->assertOk()->assertJsonPath('data.abilities.manage', true);
        $this->as($admin)->postJson("$base/share-links", ['label' => 'من المدير'])->assertCreated();
        $this->as($this->owner)->getJson("$base/sharing")->assertOk()->assertJsonPath('data.abilities.manage', true);
    }

    public function test_a_link_or_invitation_cannot_be_reached_through_another_forms_address(): void
    {
        $other = $this->publishedForm('آخر');
        $user = $this->makeUser('student');
        $link = $this->makeLink();
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/invitations", ['user_id' => $user->id])->assertCreated();
        $invitation = FormInvitation::first();

        $this->as($this->owner)->postJson("/api/operations/forms/{$other->id}/share-links/{$link['id']}/revoke")->assertNotFound();
        $this->as($this->owner)->postJson("/api/operations/forms/{$other->id}/invitations/{$invitation->id}/revoke")->assertNotFound();
        $this->assertNull(FormShareLink::find($link['id'])->revoked_at);
        $this->assertNull($invitation->fresh()->revoked_at);
    }

    public function test_the_sharing_screen_says_what_the_public_can_do_for_every_state(): void
    {
        $status = fn () => $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/sharing")->json('data.public');

        $this->assertSame(['open', true, true], [$status()['state'], $status()['reachable'], $status()['accepting']]);
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/settings", ['opens_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->assertSame(['not_open_yet', true, false], [$status()['state'], $status()['reachable'], $status()['accepting']]);
        $this->as($this->owner)->putJson("/api/operations/forms/{$this->form->id}/settings", ['opens_at' => null])->assertOk();
        $this->sharing(['access_mode' => 'authenticated', 'requires_share_link' => false])->assertOk();
        $this->assertSame(['login_required', true, false], [$status()['state'], $status()['reachable'], $status()['accepting']]);
        $this->makeLink();
        $this->sharing(['access_mode' => 'public', 'requires_share_link' => true])->assertOk();
        $this->assertSame(['share_link_required', false, false], [$status()['state'], $status()['reachable'], $status()['accepting']]);
    }

    public function test_link_and_invitation_history_is_readable_without_secrets(): void
    {
        $link = $this->makeLink(['label' => 'ملصق', 'max_uses' => 3, 'expires_at' => now()->addDay()->toIso8601String()]);
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/share-links/{$link['id']}/revoke")->assertOk();
        $user = $this->makeUser('student');
        $this->as($this->owner)->postJson("/api/operations/forms/{$this->form->id}/invitations", ['user_id' => $user->id])->assertCreated();

        $history = $this->as($this->owner)->getJson("/api/operations/forms/{$this->form->id}/activity")->assertOk()->json('data.data');
        $labels = array_column($history, 'label');
        $this->assertContains('إنشاء رابط مشاركة', $labels);
        $this->assertContains('إلغاء رابط مشاركة', $labels);
        $this->assertContains('دعوة مستخدم إلى النموذج', $labels);
        $this->assertStringNotContainsString($link['token'], json_encode($history));
        $this->assertStringNotContainsString($user->email, json_encode($history));
    }
}
