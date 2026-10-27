<?php

namespace Tests\Feature\Forms;

use App\Jobs\DispatchWebhookJob;
use App\Models\DynamicForm;
use App\Models\FormSubmission;
use App\Models\NotificationLog;
use App\Models\PlatformNotification;
use App\Models\WebhookEndpoint;
use App\Services\Forms\Automation\FormAutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesFormResponses;
use Tests\Concerns\MakesForms;
use Tests\TestCase;

/**
 * Smart Forms Phase 8: notifications, the respondent's receipt, automatic assignment, reminders and the webhook — all on the
 * existing notification, e-mail, webhook and scheduler infrastructure, never carrying an answer unless explicitly allowed.
 */
class FormAutomationTest extends TestCase
{
    use MakesFormResponses;
    use MakesForms;
    use RefreshDatabase;

    private $dept;

    private $owner;

    private DynamicForm $form;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->dept = $this->makeDepartment('قسم أ');
        $this->owner = $this->makeUser('operations_manager', $this->dept);
        $this->form = $this->createPublishedForm($this->owner, [
            ['label' => 'البريد', 'type' => 'email'],
            ['label' => 'رسالة سرية', 'type' => 'textarea'],
        ], ['department_id' => $this->dept->id]);
        $this->form->update(['allow_anonymous' => true]);
    }

    private function configure(array $settings, $actor = null)
    {
        return $this->actingAs($actor ?? $this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/automation", $settings);
    }

    /** A public submission through the real endpoint, so the after-commit automation runs exactly as in production. */
    private function submit(array $answers, $user = null)
    {
        $byLabel = collect($this->form->refresh()->questions)->keyBy('label');
        $payload = [];
        foreach ($answers as $label => $value) {
            $payload[$byLabel[$label]->id] = $value;
        }
        // The test client keeps the last signed-in user between requests: an anonymous response must forget it first.
        $this->app['auth']->forgetGuards();
        $request = $user ? $this->actingAs($user, 'sanctum') : $this;

        return $request->postJson("/api/forms/{$this->form->slug}/submit", ['answers' => $payload])->assertCreated()->json('data.submission_id');
    }

    private function notifications(string $type)
    {
        return PlatformNotification::query()->where('type', $type)->get();
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    public function test_defaults_change_nothing_for_existing_forms(): void
    {
        $res = $this->actingAs($this->owner, 'sanctum')->getJson("/api/operations/forms/{$this->form->id}/automation")->assertOk();
        $this->assertFalse($res->json('data.settings.notify_owner'));
        $this->assertTrue($res->json('data.settings.notify_assignee'));
        $this->assertFalse($res->json('data.settings.confirmation.enabled'));

        $this->submit(['البريد' => 'a@example.test']);
        $this->assertSame(0, PlatformNotification::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_only_editors_change_automation_and_bad_values_are_refused(): void
    {
        $stranger = $this->makeUser('operations_manager', $this->makeDepartment('قسم ب'));
        $this->configure(['notify_owner' => true], $stranger)->assertForbidden();

        $text = $this->form->questions()->where('type', 'textarea')->value('id');
        $this->configure(['confirmation' => ['enabled' => true, 'email_question_id' => $text]])->assertUnprocessable()->assertJsonValidationErrors('confirmation.email_question_id');
        $this->configure(['reminder_days' => 99])->assertUnprocessable()->assertJsonValidationErrors('reminder_days');
        $this->configure(['auto_assign_to' => $stranger->id])->assertUnprocessable()->assertJsonValidationErrors('auto_assign_to');
        $this->configure(['webhook' => true, 'webhook_include_answers' => true])->assertUnprocessable()->assertJsonValidationErrors('webhook_include_answers');

        $this->configure(['notify_owner' => true, 'reminder_days' => 3])->assertOk()->assertJsonPath('data.settings.reminder_days', 3);
        $this->assertDatabaseHas('activity_logs', ['entity_id' => $this->form->id, 'action' => 'form.automation_changed']);
    }

    // ── After a submission ───────────────────────────────────────────────────

    public function test_the_owner_is_notified_of_a_new_response_without_its_answers(): void
    {
        $this->configure(['notify_owner' => true])->assertOk();
        $id = $this->submit(['رسالة سرية' => 'لا يجب أن يظهر هذا']);

        $rows = $this->notifications('form_response_received');
        $this->assertCount(1, $rows);
        $this->assertSame($this->owner->id, (int) $rows[0]->user_id);
        $this->assertStringContainsString((string) $id, $rows[0]->message);
        $this->assertStringNotContainsString('لا يجب أن يظهر هذا', $rows[0]->message.$rows[0]->title);
    }

    public function test_the_owner_answering_their_own_form_is_not_notified(): void
    {
        $this->configure(['notify_owner' => true])->assertOk();
        $this->submit(['رسالة سرية' => 'x'], $this->owner);
        $this->assertCount(0, $this->notifications('form_response_received'));
    }

    public function test_department_leaders_are_notified_only_when_they_may_read_the_responses(): void
    {
        $leader = $this->makeUser('community_manager', $this->dept);
        $this->dept->update(['leader_id' => $leader->id]);
        $this->configure(['notify_department' => true])->assertOk();

        // a private form with restricted responses: the leader may not read them, so is not told
        $this->submit(['رسالة سرية' => 'x']);
        $this->assertCount(0, $this->notifications('form_response_received'));

        $this->form->update(['visibility' => 'organization', 'responses_visibility' => 'form_viewers']);
        $this->submit(['رسالة سرية' => 'y']);
        $this->assertSame([$leader->id], $this->notifications('form_response_received')->pluck('user_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_a_signed_in_respondent_gets_a_receipt_in_app_and_by_email_once(): void
    {
        $this->configure(['confirmation' => ['enabled' => true]])->assertOk();
        $respondent = $this->makeUser('student');
        $id = $this->submit(['رسالة سرية' => 'x'], $respondent);

        $this->assertCount(1, $this->notifications('form_submission_confirmed'));
        Mail::assertSent(\App\Mail\FormSubmissionConfirmationMail::class, fn ($m) => $m->hasTo($respondent->email) && $m->submissionId === $id);
        $this->assertSame(1, NotificationLog::query()->where('type', FormAutomationService::EMAIL_KEY)->count());

        // running the automation again for the same response sends nothing new
        app(FormAutomationService::class)->submitted(FormSubmission::findOrFail($id));
        $this->assertCount(1, $this->notifications('form_submission_confirmed'));
        $this->assertSame(1, NotificationLog::query()->where('type', FormAutomationService::EMAIL_KEY)->count());
    }

    public function test_an_anonymous_receipt_goes_to_the_typed_address_and_is_rate_limited(): void
    {
        $email = $this->form->questions()->where('type', 'email')->value('id');
        $this->configure(['confirmation' => ['enabled' => true, 'email_question_id' => $email]])->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->submit(['البريد' => 'Victim@Example.test', 'رسالة سرية' => 'x']);
        }

        Mail::assertSent(\App\Mail\FormSubmissionConfirmationMail::class, FormAutomationService::CONFIRMATIONS_PER_ADDRESS_PER_HOUR);
        $this->assertSame(5, FormSubmission::query()->where('form_id', $this->form->id)->count(), 'every response is stored even when no receipt is sent');
    }

    public function test_responses_are_assigned_automatically_and_the_assignee_is_told(): void
    {
        $reviewer = $this->makeUser('community_manager', $this->makeDepartment('قسم ج'));
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $reviewer->id, 'access_level' => 'editor', 'can_view_responses' => true])->assertCreated();
        $this->configure(['auto_assign_to' => $reviewer->id])->assertOk();

        $id = $this->submit(['رسالة سرية' => 'x']);

        $submission = FormSubmission::findOrFail($id);
        $this->assertSame($reviewer->id, (int) $submission->assigned_to);
        $this->assertDatabaseHas('form_submission_events', ['submission_id' => $id, 'type' => 'assigned', 'to_value' => $reviewer->name]);
        $this->assertSame([$reviewer->id], $this->notifications('form_response_assigned')->pluck('user_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_a_manual_assignment_notifies_the_assignee_but_not_someone_assigning_themselves(): void
    {
        $id = $this->submit(['رسالة سرية' => 'x']);
        $reviewer = $this->makeUser('community_manager', $this->makeDepartment('قسم ج'));
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/operations/forms/{$this->form->id}/collaborators", ['user_id' => $reviewer->id, 'access_level' => 'editor', 'can_view_responses' => true])->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/submissions/{$id}/assignment", ['assignee_id' => $this->owner->id])->assertOk();
        $this->assertCount(0, $this->notifications('form_response_assigned'));

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/submissions/{$id}/assignment", ['assignee_id' => $reviewer->id])->assertOk();
        $this->assertSame([$reviewer->id], $this->notifications('form_response_assigned')->pluck('user_id')->map(fn ($v) => (int) $v)->all());

        $this->configure(['notify_assignee' => false])->assertOk();
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/submissions/{$id}/assignment", ['assignee_id' => null])->assertOk();
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/operations/forms/{$this->form->id}/submissions/{$id}/assignment", ['assignee_id' => $reviewer->id])->assertOk();
        $this->assertCount(1, $this->notifications('form_response_assigned'), 'switched off: no new notice');
    }

    public function test_the_webhook_carries_answers_only_when_a_platform_manager_allowed_it(): void
    {
        Queue::fake([DispatchWebhookJob::class]);
        WebhookEndpoint::create(['name' => 'crm', 'url' => 'https://crm.example.test/hook', 'secret' => 's', 'events' => ['form.submitted'], 'status' => 'active']);

        $this->configure(['webhook' => true])->assertOk();
        $this->submit(['رسالة سرية' => 'سر']);
        Queue::assertPushed(DispatchWebhookJob::class, fn ($job) => $this->payloadOf($job)['form']['id'] === $this->form->id && ! array_key_exists('answers', $this->payloadOf($job)));

        $this->configure(['webhook_include_answers' => true], $this->makeUser('admin'))->assertOk();
        $this->submit(['رسالة سرية' => 'سر']);
        Queue::assertPushed(DispatchWebhookJob::class, fn ($job) => ($this->payloadOf($job)['answers']['رسالة سرية'] ?? null) === 'سر');
    }

    private function payloadOf(DispatchWebhookJob $job): array
    {
        return $job->payload;
    }

    // ── Reminders ────────────────────────────────────────────────────────────

    public function test_reminders_go_once_a_day_to_the_assignee_or_the_owner(): void
    {
        $this->configure(['reminder_days' => 2])->assertOk();
        $old = $this->respond($this->form, ['رسالة سرية' => 'x'], ['submitted_at' => now()->subDays(3)]);
        $this->respond($this->form, ['رسالة سرية' => 'y'], ['submitted_at' => now()->subDays(3), 'status' => 'completed']);
        $this->respond($this->form, ['رسالة سرية' => 'z']); // too recent

        $this->artisan('forms:remind-pending')->assertSuccessful();
        $this->artisan('forms:remind-pending')->assertSuccessful();

        $rows = $this->notifications('form_responses_pending');
        $this->assertCount(1, $rows, 'once per person, form and day');
        $this->assertSame($this->owner->id, (int) $rows[0]->user_id);
        $this->assertStringContainsString('1 ', $rows[0]->message);

        // the next day it reminds again
        app(FormAutomationService::class)->remindPending(CarbonImmutable::now()->addDay());
        $this->assertCount(2, $this->notifications('form_responses_pending'));
        $this->assertNotNull($old->id);
    }

    public function test_forms_without_a_reminder_delay_send_none(): void
    {
        $this->respond($this->form, ['رسالة سرية' => 'x'], ['submitted_at' => now()->subDays(10)]);
        $this->artisan('forms:remind-pending')->assertSuccessful();
        $this->assertCount(0, $this->notifications('form_responses_pending'));
    }
}
