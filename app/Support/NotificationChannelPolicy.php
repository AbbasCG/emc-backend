<?php

namespace App\Support;

/**
 * Ticket 8 channel-policy completion pass.
 *
 * Product-level, code-controlled defaults for which channels a given
 * NotificationService notification `type` is allowed to use, on top of the
 * per-user NotificationPreference toggle (database-controlled — a channel
 * enabled here can still be silenced by preference; a channel absent here
 * is never sent regardless of preference).
 *
 * Design principle (conservative by default — see ADR §21): in-app is the
 * only channel every scenario gets automatically. Push is added only where
 * missing the notification for a few hours would materially reduce its
 * usefulness. SMS is reserved for a small, explicitly time-sensitive
 * subset and is never enabled merely because the event is "important".
 * `urgent` (quiet-hours bypass) defaults to false everywhere — none of the
 * 49 scenario methods carry a verified, unambiguous "this is an immediate
 * operational emergency" signal, so none are marked urgent; see ADR §21
 * for the specific scenarios considered and rejected.
 */
class NotificationChannelPolicy
{
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_PUSH = 'push';
    public const CHANNEL_SMS = 'sms';

    public const KNOWN_CHANNELS = [self::CHANNEL_IN_APP, self::CHANNEL_PUSH, self::CHANNEL_SMS];

    /** Reschedule/cancellation within this many hours of start is "imminent" enough to add SMS. */
    public const IMMINENT_THRESHOLD_HOURS = 24;

    /**
     * @return array{channels: string[], urgent: bool, email: string, whatsapp: string, rationale: string}
     */
    private const POLICY = [
        // ── In-app only — routine, non-actionable, or already covered elsewhere ──
        'course_created' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Admin-facing catalog event, not time-sensitive.'],
        'instructor_assigned' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Assignment is visible in the instructor dashboard; not urgent to push.'],
        'workshop_instructor_assigned' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Same as instructor_assigned.'],
        'registration_opened' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Admin-only informational event.'],
        'new_registration' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'existing_separate', 'whatsapp' => 'none', 'rationale' => 'Admin notification of a new signup; AdminNewRegistrationMail already covers email awareness.'],
        'student_registered' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'existing_separate', 'whatsapp' => 'none', 'rationale' => 'CourseEnrollmentMail already sent from the same method — push would duplicate the "you are enrolled" signal.'],
        'workshop_request_submitted' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Admin queue item, reviewed asynchronously.'],
        'workshop_request_step' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Internal workflow step notification for reviewers.'],
        'account_created' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'existing_separate', 'whatsapp' => 'none', 'rationale' => 'WelcomeMail/AccountCreatedMail already delivers the first-access signal by email.'],
        'volunteer_request' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'existing_separate', 'whatsapp' => 'none', 'rationale' => 'VolunteerApplicationAdminMail already covers admin email awareness.'],
        'member_converted' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Internal admin/HR record update.'],
        'placement_test_completed' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Instructor reviews on their own schedule from the placement queue.'],
        'oral_assessment_completed' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Result is visible in the student dashboard; not time-critical to push.'],
        'oral_assessment_status_changed' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Status-only update; no verified urgency signal in the method.'],
        'oral_assessment_message' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Free-text instructor message — conservative default, no push (Class B, legitimately repeats).'],
        'class_group_assigned' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Visible on next dashboard visit; not actionable within hours.'],
        'class_group_student_assigned' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Instructor-facing roster update.'],
        'report_ready' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Reports are pulled on demand; no verified strong time-sensitivity.'],
        'assignment_created' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Routine content addition, visible next dashboard visit.'],
        'assignment_deadline_changed' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Method receives only the new deadline string, no "is this imminent" signal safe to parse — kept in-app only rather than guessing from free text.'],
        'assignment_submitted' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Instructor-facing, reviewed asynchronously.'],
        'assignment_resubmitted' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Same as assignment_submitted.'],
        'resubmission_requested' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Student-facing but not time-critical within hours.'],
        'attendance_marked' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Routine per-session confirmation.'],
        'attendance_repeated_absence' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Warning is informational, not actionable within hours.'],
        'attendance_recovered' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Positive/informational update.'],
        'class_attendance_completed' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Instructor-facing summary, not time-critical.'],
        'attendance_low_warning' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Warning is informational; escalating to push/SMS for an attendance percentage was judged disproportionate.'],
        'class_announcement_published' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Announcement content varies too widely to default to push for every one.'],
        'learning_path_enrolled' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'existing_separate', 'whatsapp' => 'none', 'rationale' => 'LearningPathEnrollmentMail already covers email awareness.'],
        'learning_path_completed' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'existing_separate', 'whatsapp' => 'none', 'rationale' => 'LearningPathCompletionMail already covers email awareness.'],
        'course_completed_student' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'existing_separate', 'whatsapp' => 'none', 'rationale' => 'CourseCompletionMail already covers email awareness.'],
        'course_completed' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Achievement-type event, not time-critical.'],
        'ambassador_application' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'existing_separate', 'whatsapp' => 'none', 'rationale' => 'AmbassadorApplicationAdminMail already covers admin email awareness.'],
        'ambassador_status_changed' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Admin-facing status log; the applicant email is dispatched separately by the controller, not this method.'],
        'module_activated' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Routine content publication.'],
        'lesson_activated' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Routine content publication.'],
        'lesson_content_updated' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Routine content update, legitimately repeats (Class B).'],
        'material_visible' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Routine content publication.'],
        'workshop_request_rejected' => ['channels' => [self::CHANNEL_IN_APP], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Admin-facing outcome, reviewed asynchronously — kept in-app only rather than the recommended-default push, since no student/requester-facing urgency was verified for this admin queue event.'],

        // ── In-app + push — timely, actionable, user-relevant ──────────────────
        'course_date_updated' => ['channels' => [self::CHANNEL_IN_APP, self::CHANNEL_PUSH], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'A real schedule change is actionable; push helps students notice before their next dashboard visit.'],
        'course_schedule_updated' => ['channels' => [self::CHANNEL_IN_APP, self::CHANNEL_PUSH], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Same reasoning as course_date_updated.'],
        'registration_confirmed' => ['channels' => [self::CHANNEL_IN_APP, self::CHANNEL_PUSH], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Confirms a paid action succeeded — timely reassurance is genuinely useful.'],
        'oral_assessment_booked' => ['channels' => [self::CHANNEL_IN_APP, self::CHANNEL_PUSH], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'A scheduled appointment is actionable information both recipients benefit from promptly.'],
        'assignment_graded' => ['channels' => [self::CHANNEL_IN_APP, self::CHANNEL_PUSH], 'urgent' => false, 'email' => 'existing_separate', 'whatsapp' => 'none', 'rationale' => 'A grade is a genuinely useful timely result; EmailService already sends assignment_graded email separately, push adds immediacy without SMS cost.'],
        'certificate_issued' => ['channels' => [self::CHANNEL_IN_APP, self::CHANNEL_PUSH], 'urgent' => false, 'email' => 'existing_separate', 'whatsapp' => 'none', 'rationale' => 'A completed achievement worth a timely nudge; SMS judged disproportionate cost for a non-time-critical event.'],

        // ── Context-dependent — see resolveWithContext() ────────────────────────
        'course_cancelled' => ['channels' => [self::CHANNEL_IN_APP, self::CHANNEL_PUSH], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'A cancellation is actionable; SMS would be justified only if the cancellation is imminent, but courseCancelled(courseId, courseTitle) carries no start-time parameter to verify that safely — kept push-only, documented limitation (see ADR §21).'],
        'session_scheduled' => ['channels' => [self::CHANNEL_IN_APP, self::CHANNEL_PUSH], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Base policy; resolveWithContext() adds SMS when the session starts within IMMINENT_THRESHOLD_HOURS and this is an update (reschedule), using the method\'s own real $startsAt/$isUpdate parameters.'],
        'oral_assessment_rescheduled' => ['channels' => [self::CHANNEL_IN_APP, self::CHANNEL_PUSH], 'urgent' => false, 'email' => 'none', 'whatsapp' => 'none', 'rationale' => 'Base policy; resolveWithContext() adds SMS when the new slot is within IMMINENT_THRESHOLD_HOURS, using the method\'s own real $newSlotDatetime parameter.'],
    ];

    /** @return array{channels: string[], urgent: bool, email: string, whatsapp: string, rationale: string} */
    public static function for(string $type): array
    {
        return self::POLICY[$type] ?? [
            'channels' => [self::CHANNEL_IN_APP],
            'urgent' => false,
            'email' => 'none',
            'whatsapp' => 'none',
            'rationale' => 'No explicit policy registered for this type — safe in-app-only default, never silently escalated to push/SMS.',
        ];
    }

    /** @return string[] */
    public static function channelsFor(string $type): array
    {
        return self::for($type)['channels'];
    }

    public static function isUrgent(string $type): bool
    {
        return self::for($type)['urgent'];
    }

    /**
     * Every type this policy has an explicit entry for — used by the
     * completeness test to assert no scenario silently falls through to
     * the safe default above without a deliberate, documented decision.
     *
     * @return array<string, array{channels: string[], urgent: bool, email: string, whatsapp: string, rationale: string}>
     */
    public static function all(): array
    {
        return self::POLICY;
    }

    /**
     * Context-dependent resolution for the handful of scenarios whose own
     * method signature already carries real timing data. Never parses
     * title/message text — only real typed parameters the caller already
     * has. Falls back to the static policy (safer, less disruptive channel
     * set) when context is absent, per the explicit instruction to prefer
     * the safe default rather than guessing.
     *
     * @param  array{starts_at?: string|null, is_update?: bool}  $context
     * @return string[]
     */
    public static function resolveWithContext(string $type, array $context = []): array
    {
        $base = self::channelsFor($type);

        if (! in_array($type, ['session_scheduled', 'oral_assessment_rescheduled'], true)) {
            return $base;
        }

        $startsAt = $context['starts_at'] ?? null;
        if (! $startsAt) {
            return $base;
        }

        if ($type === 'session_scheduled' && ! ($context['is_update'] ?? false)) {
            // A brand-new session being scheduled is useful to know about,
            // but not urgent enough for SMS even if it happens to be soon —
            // only a *change* to an existing plan warrants the extra channel.
            return $base;
        }

        try {
            $hoursUntil = now()->diffInHours(\Illuminate\Support\Carbon::parse($startsAt), false);
        } catch (\Throwable) {
            return $base;
        }

        if ($hoursUntil >= 0 && $hoursUntil <= self::IMMINENT_THRESHOLD_HOURS) {
            return array_values(array_unique([...$base, self::CHANNEL_SMS]));
        }

        return $base;
    }
}
