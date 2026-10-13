<?php

namespace App\Support;

/**
 * Central configuration of the smart operational rules (V1: code, not database).
 *
 * Every rule has the same shape so a later version can move this into a table, add a
 * management screen, per-user preferences, snooze/dismiss or notification delivery without
 * touching the rule logic:
 *
 *   enabled     bool   switch the rule off without deleting it
 *   module      string the area it belongs to (tickets, meetings, email, users, education, finance, hr, quality)
 *   section     string where the alert lands in the command center (needs_attention | errors)
 *   severity    string the BASE severity: critical|high|medium|low|info
 *   score       int    the base priority score inside that severity's band
 *   limit       int    most alerts this rule may return (entity rules)
 *   ...         thresholds specific to the rule
 *   supersedes  array  base command-center item types this rule replaces, so the same
 *                      condition is never shown twice
 *
 * Tests (and later a settings UI) build an instance with overrides:
 *   new SmartOperationalRuleConfig(['ticket_unassigned_too_long' => ['threshold_hours' => 1]])
 */
class SmartOperationalRuleConfig
{
    /** Score bands per severity; a rule's score is always clamped inside its (final) band. */
    public const BANDS = [
        'critical' => [90, 100],
        'high' => [70, 89],
        'medium' => [40, 69],
        'low' => [20, 39],
        'info' => [1, 19],
    ];

    public const SEVERITY_LABELS = [
        'critical' => 'حرج',
        'high' => 'عاجل',
        'medium' => 'مهم',
        'low' => 'متوسط',
        'info' => 'معلومة',
    ];

    /** Most alerts the engine returns in total, after sorting by score. */
    public const GLOBAL_LIMIT = 50;

    private const DEFAULTS = [
        // ── Tickets ──────────────────────────────────────────────────────────
        'ticket_unassigned_too_long' => [
            'enabled' => true, 'module' => 'tickets', 'section' => 'needs_attention', 'severity' => 'high', 'score' => 75, 'limit' => 5,
            'threshold_hours' => 4, 'supersedes' => ['ticket_new'], 'label' => 'تذكرة بدون مكلف',
        ],
        'ticket_overdue' => [
            'enabled' => true, 'module' => 'tickets', 'section' => 'needs_attention', 'severity' => 'high', 'score' => 78, 'limit' => 5,
            // Assigned tickets only (unassigned ones belong to the rule above): overdue once the
            // expected resolution time has passed, or, with none set, after this many hours.
            'threshold_hours' => 48, 'critical_after_hours' => 72, 'supersedes' => ['ticket_overdue'], 'label' => 'تذكرة متأخرة',
        ],
        'many_new_tickets' => [
            'enabled' => true, 'module' => 'tickets', 'section' => 'needs_attention', 'severity' => 'medium', 'score' => 55, 'limit' => 1,
            'threshold_count' => 10, 'window_hours' => 24, 'high_multiplier' => 2, 'label' => 'ضغط التذاكر',
        ],
        // ── Meetings ─────────────────────────────────────────────────────────
        'meeting_report_missing' => [
            'enabled' => true, 'module' => 'meetings', 'section' => 'needs_attention', 'severity' => 'high', 'score' => 74, 'limit' => 5,
            'threshold_hours' => 24, 'lookback_days' => 30, 'supersedes' => ['meeting_missing_report'], 'label' => 'اجتماع بلا تقرير',
        ],
        'meeting_starts_soon_missing_participants' => [
            'enabled' => true, 'module' => 'meetings', 'section' => 'needs_attention', 'severity' => 'medium', 'score' => 58, 'limit' => 5,
            'window_hours' => 24, 'label' => 'اجتماع بلا نطاق أو مشاركين',
        ],
        // ── Email ────────────────────────────────────────────────────────────
        'failed_emails_recent' => [
            'enabled' => true, 'module' => 'email', 'section' => 'errors', 'severity' => 'medium', 'score' => 58, 'limit' => 1,
            'window_hours' => 24, 'threshold_count' => 3, 'supersedes' => ['email_failures'], 'label' => 'فشل إرسال البريد',
        ],
        'repeated_email_failure_type' => [
            'enabled' => true, 'module' => 'email', 'section' => 'errors', 'severity' => 'high', 'score' => 80, 'limit' => 5,
            'window_hours' => 24, 'threshold_count' => 3, 'critical_count' => 10, 'label' => 'تكرار فشل نوع بريد',
        ],
        // ── Users ────────────────────────────────────────────────────────────
        'unverified_users_old' => [
            'enabled' => true, 'module' => 'users', 'section' => 'needs_attention', 'severity' => 'medium', 'score' => 50, 'limit' => 1,
            // Accounts older than `threshold_days` but newer than `lookback_days`: legacy accounts that
            // predate verification are covered by many_unverified_users, not flagged one by one.
            'threshold_days' => 3, 'lookback_days' => 30, 'supersedes' => ['users_unverified'], 'label' => 'حسابات لم توثّق بريدها',
        ],
        'many_unverified_users' => [
            'enabled' => true, 'module' => 'users', 'section' => 'needs_attention', 'severity' => 'medium', 'score' => 52, 'limit' => 1,
            'threshold_count' => 50, 'high_multiplier' => 5, 'label' => 'كثرة الحسابات غير الموثّقة',
        ],
        // ── Courses / sessions ───────────────────────────────────────────────
        'upcoming_session_missing_instructor' => [
            'enabled' => true, 'module' => 'education', 'section' => 'needs_attention', 'severity' => 'high', 'score' => 76, 'limit' => 5,
            'window_days' => 7, 'supersedes' => ['session_no_instructor'], 'label' => 'حصة بلا مدرب',
        ],
        'course_starting_soon_low_setup' => [
            'enabled' => true, 'module' => 'education', 'section' => 'needs_attention', 'severity' => 'medium', 'score' => 60, 'limit' => 5,
            'window_days' => 14, 'label' => 'دورة قريبة غير مكتملة الإعداد',
        ],
        // ── Finance ──────────────────────────────────────────────────────────
        'finance_request_pending_too_long' => [
            'enabled' => true, 'module' => 'finance', 'section' => 'needs_attention', 'severity' => 'medium', 'score' => 62, 'limit' => 5,
            'threshold_hours' => 48, 'high_after_hours' => 168, 'label' => 'طلب مالي معلق',
        ],
        'manual_payment_pending_review' => [
            'enabled' => true, 'module' => 'finance', 'section' => 'needs_attention', 'severity' => 'medium', 'score' => 56, 'limit' => 1,
            'supersedes' => ['manual_payments_pending'], 'label' => 'دفعات يدوية بانتظار المراجعة',
        ],
        // ── HR ───────────────────────────────────────────────────────────────
        'hr_request_pending_too_long' => [
            'enabled' => true, 'module' => 'hr', 'section' => 'needs_attention', 'severity' => 'medium', 'score' => 54, 'limit' => 5,
            'threshold_hours' => 72, 'supersedes' => ['hr_request_pending'], 'label' => 'طلب موارد بشرية معلق',
        ],
        // ── Quality ──────────────────────────────────────────────────────────
        // Not implementable yet: a meeting report has no "reviewed by quality" status (the existing
        // ratings are per-attendee contribution scores). Kept so the report lists it as skipped.
        'new_meeting_report_needs_quality_review' => [
            'enabled' => false, 'module' => 'quality', 'section' => 'needs_attention', 'severity' => 'medium', 'score' => 50, 'limit' => 5,
            'label' => 'تقرير اجتماع بانتظار مراجعة الجودة', 'unavailable_reason' => 'لا توجد حالة مراجعة جودة لتقارير الاجتماعات.',
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $rules;

    /** @param array<string, array<string, mixed>> $overrides rule_key => values to override */
    public function __construct(array $overrides = [])
    {
        $this->rules = self::DEFAULTS;
        foreach ($overrides as $key => $values) {
            if (isset($this->rules[$key])) {
                $this->rules[$key] = array_merge($this->rules[$key], $values);
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->rules;
    }

    /** @return array<string, mixed> */
    public function rule(string $key): array
    {
        return $this->rules[$key] ?? [];
    }

    /** Clamp a score into the band of the severity it ends up with. */
    public static function clamp(string $severity, int $score): int
    {
        [$min, $max] = self::BANDS[$severity] ?? self::BANDS['info'];

        return max($min, min($max, $score));
    }
}
