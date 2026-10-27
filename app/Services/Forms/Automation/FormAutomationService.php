<?php

namespace App\Services\Forms\Automation;

use App\Mail\FormSubmissionConfirmationMail;
use App\Models\Department;
use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormSubmission;
use App\Models\FormSubmissionEvent;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Forms\FormAccessService;
use App\Services\Forms\FormResponseQuery;
use App\Services\Forms\FormSnapshotService;
use App\Services\NotificationService;
use App\Services\WebhookDispatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * What happens automatically around a form's responses (Smart Forms Phase 8), on the platform's existing infrastructure:
 * NotificationService (queued, de-duplicated by idempotency key), EmailService (queued, logged, switchable per key), the webhook
 * endpoints (WebhookDispatchService, signed and queued) and the scheduler.
 *
 * Everything runs AFTER the response is committed, and nothing here can make a submission fail: a respondent's answers are stored
 * whatever happens to a notification. Nothing sent carries an answer, except the webhook when a platform manager explicitly allowed
 * it for that form.
 */
class FormAutomationService
{
    /** A confirmation e-mail to an address typed into a public form: at most this many per address per hour (no mail bombing). */
    public const CONFIRMATIONS_PER_ADDRESS_PER_HOUR = 3;

    public const EMAIL_KEY = 'form_submission_confirmation';

    public function __construct(private readonly FormAccessService $access) {}

    /** A response was stored. Called once, after commit, by FormSubmissionService::submit. */
    public function submitted(FormSubmission $submission): void
    {
        try {
            $form = DynamicForm::query()->find($submission->form_id);
            if ($form === null) {
                return;
            }
            $settings = FormAutomationSettings::of($form);

            if ($settings['auto_assign_to'] !== null) {
                $this->autoAssign($form, $submission, (int) $settings['auto_assign_to']);
            }
            $this->notifyReviewers($form, $submission, $settings);
            if ($settings['confirmation']['enabled']) {
                $this->confirm($form, $submission, $settings);
            }
            if ($settings['webhook']) {
                app(WebhookDispatchService::class)->fire('form.submitted', $this->webhookPayload($form, $submission, (bool) $settings['webhook_include_answers']));
            }
        } catch (\Throwable $e) {
            // The response is already stored; a failed notification must never turn it into an error for the respondent.
            Log::error('Form automation failed after a submission', ['submission_id' => $submission->id, 'error' => $e->getMessage()]);
        }
    }

    /** A reviewer (or the automatic assignment) assigned a response. The person who assigned it to themselves is not told. */
    public function assigned(FormSubmission $submission, ?User $actor): void
    {
        if ($submission->assigned_to === null || ($actor !== null && (int) $actor->id === (int) $submission->assigned_to)) {
            return;
        }
        $form = DynamicForm::query()->find($submission->form_id);
        if ($form === null || ! FormAutomationSettings::of($form)['notify_assignee']) {
            return;
        }

        NotificationService::formResponseAssigned(
            (int) $submission->assigned_to,
            $form->id,
            (string) $form->title,
            $submission->id,
            (string) ($submission->assigned_at?->toIso8601String() ?? now()->toIso8601String()),
        );
    }

    // ── After a submission ───────────────────────────────────────────────────

    /**
     * The start of the review workflow: the response goes straight to the chosen reviewer, if they may still review this form's
     * responses (access can change after the setting was saved). Recorded in the response's history like a manual assignment.
     */
    private function autoAssign(DynamicForm $form, FormSubmission $submission, int $assigneeId): void
    {
        $assignee = $this->access->assignableEmployees($form)->firstWhere('id', $assigneeId);
        if ($assignee === null) {
            Log::warning('Form auto-assignment skipped: the reviewer can no longer review this form', ['form_id' => $form->id, 'user_id' => $assigneeId]);

            return;
        }

        $assigned = DB::transaction(function () use ($submission, $assignee, $form) {
            $locked = FormSubmission::query()->whereKey($submission->id)->lockForUpdate()->first();
            if ($locked === null || $locked->assigned_to !== null) {
                return null;
            }
            $locked->forceFill(['assigned_to' => $assignee->id, 'assigned_at' => now()])->save();
            FormSubmissionEvent::create([
                'submission_id' => $locked->id,
                // The history names who decided it: the form's owner, who configured the automatic assignment.
                'user_id'       => (int) ($form->owner_id ?? $form->created_by ?? $assignee->id),
                'type'          => FormSubmissionEvent::ASSIGNED,
                'from_value'    => null,
                'to_value'      => $assignee->name,
                'meta'          => ['automatic' => true],
            ]);

            return $locked;
        });

        if ($assigned !== null) {
            $submission->setRawAttributes($assigned->getAttributes(), true);
            $this->assigned($assigned, null);
        }
    }

    /** The owner and/or the department's leaders, if they asked for it and may read the responses. Never the respondent. */
    private function notifyReviewers(DynamicForm $form, FormSubmission $submission, array $settings): void
    {
        $candidates = [];
        if ($settings['notify_owner']) {
            $candidates[] = (int) ($form->owner_id ?? $form->created_by);
        }
        if ($settings['notify_department'] && $form->department_id !== null) {
            $department = Department::query()->find($form->department_id, ['id', 'leader_id', 'deputy_leader_id']);
            foreach ([$department?->leader_id, $department?->deputy_leader_id] as $id) {
                if ($id !== null) {
                    $candidates[] = (int) $id;
                }
            }
        }

        $recipients = User::query()->whereIn('id', array_unique(array_filter($candidates)))->where('is_active', true)->get()
            ->filter(fn (User $u) => (int) $u->id !== (int) $submission->user_id && $this->access->canViewResponses($u, $form))
            ->pluck('id')->all();

        if ($recipients !== []) {
            NotificationService::formResponseReceived($recipients, $form->id, (string) $form->title, $submission->id);
        }
    }

    /**
     * The respondent's receipt. Signed in: in-app plus an e-mail to their account. Anonymous: an e-mail to the address typed in the
     * chosen e-mail question, rate-limited per address. Each response is e-mailed once at most (the e-mail log is the record).
     */
    private function confirm(DynamicForm $form, FormSubmission $submission, array $settings): void
    {
        $submittedAt = ($submission->submitted_at ?? now())->copy()->timezone(FormResponseQuery::DISPLAY_TIMEZONE)->locale('ar')->translatedFormat('j F Y، H:i');
        $user = $submission->user_id !== null ? User::query()->find($submission->user_id) : null;

        if ($user !== null) {
            NotificationService::formSubmissionConfirmed($user->id, (string) $form->title, $submission->id);
            $this->email($user->email, $user->name, $user->id, $form, $submission, $submittedAt);

            return;
        }

        $questionId = $settings['confirmation']['email_question_id'];
        if ($questionId === null) {
            return;
        }
        $address = trim((string) FormAnswer::query()->where('submission_id', $submission->id)->where('question_id', $questionId)->value('value'));
        if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $key = 'form-confirmation:'.sha1(mb_strtolower($address));
        if (RateLimiter::tooManyAttempts($key, self::CONFIRMATIONS_PER_ADDRESS_PER_HOUR)) {
            Log::notice('Form confirmation e-mail rate-limited', ['form_id' => $form->id, 'submission_id' => $submission->id]);

            return;
        }
        RateLimiter::hit($key, 3600);
        $this->email($address, '', null, $form, $submission, $submittedAt);
    }

    private function email(?string $address, string $name, ?int $userId, DynamicForm $form, FormSubmission $submission, string $submittedAt): void
    {
        if (! $address) {
            return;
        }
        $already = NotificationLog::query()->where('channel', 'email')->where('type', self::EMAIL_KEY)->where('payload->submission_id', $submission->id)->exists();
        if ($already) {
            return;
        }

        EmailService::dispatch(
            self::EMAIL_KEY,
            $address,
            $name,
            $userId,
            new FormSubmissionConfirmationMail($name, (string) $form->title, $submission->id, $submittedAt),
            'تم استلام ردك: '.mb_strimwidth((string) $form->title, 0, 90, '…'),
            ['form_id' => $form->id, 'submission_id' => $submission->id],
        );
    }

    /**
     * The `form.submitted` event. Ids, times and where the response came from; the answers only when a platform manager allowed it
     * for this form, keyed by the question's label as the respondent saw it. Never a file's content, only its name.
     *
     * @return array<string, mixed>
     */
    public function webhookPayload(DynamicForm $form, FormSubmission $submission, bool $withAnswers): array
    {
        $payload = [
            'form'       => ['id' => $form->id, 'slug' => $form->slug, 'title' => $form->title, 'type' => $form->type, 'department_id' => $form->department_id],
            'submission' => [
                'id'           => $submission->id,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'version'      => $submission->form_version_id !== null ? (int) DB::table('form_versions')->where('id', $submission->form_version_id)->value('version_number') : null,
                'source'       => $submission->share_link_id !== null ? 'share_link' : 'public',
                'signed_in'    => $submission->user_id !== null,
                'status'       => $submission->status,
            ],
        ];

        if ($withAnswers) {
            $schema = $submission->form_version_id !== null ? DB::table('form_versions')->where('id', $submission->form_version_id)->value('schema') : null;
            $labels = $schema !== null
                ? FormSnapshotService::questionModels(json_decode((string) $schema, true) ?: [])->pluck('label', 'id')->all()
                : $form->questions()->withTrashed()->pluck('label', 'id')->all();
            $answers = [];
            foreach (FormAnswer::query()->where('submission_id', $submission->id)->get(['question_id', 'value']) as $answer) {
                $label = (string) ($labels[$answer->question_id] ?? 'سؤال '.$answer->question_id);
                $decoded = is_string($answer->value) && str_starts_with($answer->value, '[') ? json_decode($answer->value, true) : null;
                $answers[$label] = is_array($decoded) ? $decoded : $answer->value;
            }
            $payload['answers'] = $answers;
        }

        return $payload;
    }

    // ── The daily reminder ───────────────────────────────────────────────────

    /**
     * For every form with a reminder delay: the responses still open after it, counted per person (the assignee, or the owner
     * when nobody is assigned), one notification per person and form and day. Returns how many reminders were sent.
     */
    public function remindPending(?\DateTimeInterface $now = null): int
    {
        $now = $now !== null ? \Carbon\CarbonImmutable::instance($now) : \Carbon\CarbonImmutable::now();
        $day = $now->setTimezone(FormResponseQuery::DISPLAY_TIMEZONE)->format('Y-m-d');
        $sent = 0;

        DynamicForm::query()->whereNotNull('automation')->whereIn('status', ['active', 'closed'])->orderBy('id')
            ->chunkById(100, function ($forms) use ($now, $day, &$sent) {
                foreach ($forms as $form) {
                    $days = FormAutomationSettings::of($form)['reminder_days'];
                    if ($days === null) {
                        continue;
                    }
                    $counts = FormSubmission::query()->where('form_id', $form->id)
                        ->whereIn('status', [FormSubmission::NEW, FormSubmission::IN_REVIEW, FormSubmission::NEEDS_FOLLOWUP])
                        ->where('submitted_at', '<=', $now->subDays((int) $days))
                        ->selectRaw('assigned_to, count(*) as c')->groupBy('assigned_to')->pluck('c', 'assigned_to');

                    $perUser = [];
                    foreach ($counts as $assignee => $count) {
                        $userId = $assignee !== '' && $assignee !== null ? (int) $assignee : (int) ($form->owner_id ?? $form->created_by);
                        $perUser[$userId] = ($perUser[$userId] ?? 0) + (int) $count;
                    }
                    foreach ($perUser as $userId => $count) {
                        $user = User::query()->where('is_active', true)->find($userId);
                        if ($user === null || ! $this->access->canViewResponses($user, $form)) {
                            continue;
                        }
                        NotificationService::formResponsesPending($user->id, $form->id, (string) $form->title, $count, $day);
                        $sent++;
                    }
                }
            });

        return $sent;
    }
}
