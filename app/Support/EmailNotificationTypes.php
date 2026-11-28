<?php

namespace App\Support;

use App\Http\Controllers\Admin\EmailSettingsController;
use App\Models\Department;
use App\Models\Instructor;
use App\Models\User;

/**
 * THE registry of email types: which ones a person can set a preference for, which ones are mandatory, and who can actually
 * receive each one. One place, so the admin settings page, the user's preference page and the sender can never disagree.
 *
 * Labels, descriptions and categories stay where they always were (EmailSettingsController::LABELS, the list of real
 * EmailService::dispatch() keys). This class adds, per key, three facts the sender already knew but nobody had written down:
 *
 *   mandatory          security / credentials mail (password reset, verification, a password you were given). Never a personal
 *                      choice and never switched by the central default; only the old administrator stop (`paused`) still
 *                      applies, exactly as before.
 *   user_configurable  a registered person can choose for this type. False when the recipient has no account to hold a choice
 *                      (a visitor's confirmation, a fixed support address) or the mail happens once, at account creation.
 *   roles              who can receive it: null = anybody with an account (the type follows the EVENT: your own registration,
 *                      your own ticket...); a list = only those roles (staff fan-out). Evaluated with User::hasRole(), so the
 *                      effective role and the department-specialised role count, and a user with several roles qualifies once
 *                      for the union. `leader` adds the leader of any department (the volunteer-application fan-out sends to them).
 *
 * The role lists mirror the recipient queries of the senders (NotificationService, WorkshopRequestController,
 * CertificateGeneratorService, NotifyAdminCertificateFailure); a test pins the pairs so they cannot drift apart silently.
 */
final class EmailNotificationTypes
{
    /** Security / credentials mail: no personal choice, no central default. */
    public const MANDATORY = ['password_reset', 'email_verification', 'account_created', 'temporary_password'];

    /**
     * Per key: audience wording for the administrator, who can choose (user_configurable), and who can receive (roles / leader).
     * Keys with no entry fall back to a central-only profile (see profile()).
     *
     * @var array<string, array{audience: string, user_configurable?: bool, roles?: array<int, string>|null, leader?: bool}>
     */
    private const PROFILES = [
        // ── Account ──────────────────────────────────────────────────────────────
        'welcome'            => ['audience' => 'المستخدم الجديد مرة واحدة عند إنشاء حسابه', 'user_configurable' => false],
        'account_created'    => ['audience' => 'المستخدم الذي أنشأت الإدارة حسابه (يحمل كلمة المرور المؤقتة)'],
        'temporary_password' => ['audience' => 'المستخدم عند إرسال كلمة مرور مؤقتة'],
        'email_verification' => ['audience' => 'صاحب الحساب الذي يوثّق بريده'],
        'password_reset'     => ['audience' => 'صاحب الحساب الذي طلب إعادة تعيين كلمة المرور'],
        // ── Learning (the person the event is about: every role can enrol and learn) ──
        'course_enrollment'        => ['audience' => 'المستخدم الذي سجّل في الدورة', 'roles' => null],
        'learning_path_enrollment' => ['audience' => 'المستخدم الذي انضم للمسار', 'roles' => null],
        'course_completion'        => ['audience' => 'المستخدم الذي أتمّ الدورة', 'roles' => null],
        'learning_path_completion' => ['audience' => 'المستخدم الذي أتمّ المسار', 'roles' => null],
        'assignment_graded'        => ['audience' => 'صاحب الواجب الذي قُيّم', 'roles' => null],
        'session_reminder'         => ['audience' => 'المسجّلون في الجلسة', 'roles' => null],
        'assignment_submitted'     => ['audience' => 'مدرّب الدورة التي سُلّم فيها الواجب', 'roles' => ['instructor', 'admin', 'super_admin'], 'instructor_profile' => true],
        'admin_new_registration'   => ['audience' => 'المشرفون عند تسجيل طالب جديد', 'roles' => ['admin', 'super_admin', 'executive_admin']],
        // ── Payments ─────────────────────────────────────────────────────────────
        'payment_confirmed' => ['audience' => 'الدافع الذي أُكّد دفعه (قد يكون زائرًا بلا حساب)', 'roles' => null],
        // ── Support ──────────────────────────────────────────────────────────────
        'contact_confirmation'       => ['audience' => 'مرسل نموذج التواصل (قد يكون زائرًا بلا حساب)', 'roles' => null],
        'contact_admin_notification' => ['audience' => 'عنوان الدعم الثابت في الإعدادات', 'user_configurable' => false],
        'support_ticket_reply'       => ['audience' => 'صاحب تذكرة الدعم (قد يكون زائرًا بلا حساب)', 'roles' => null],
        'ticket_resolved'            => ['audience' => 'مقدّم البلاغ', 'roles' => null],
        'suggestion_approved'        => ['audience' => 'مقدّم المقترح', 'roles' => null],
        'suggestion_implemented'     => ['audience' => 'مقدّم المقترح', 'roles' => null],
        'operations_ticket_assigned' => ['audience' => 'المكلَّف بتذكرة العمليات', 'roles' => null],
        'ticket_assigned'            => ['audience' => 'المكلَّف بتذكرة الدعم الفني', 'roles' => null],
        // ── Applications (an applicant is a visitor with no account, or a fixed address) ──
        'volunteer_confirmation'              => ['audience' => 'مقدّم طلب التطوع (زائر بلا حساب)', 'user_configurable' => false],
        'volunteer_admin_notification'        => ['audience' => 'المشرفون وفريق الموارد البشرية وأقسام الطلب وقادتها', 'roles' => ['admin', 'super_admin', 'executive_admin', 'hr_manager', 'support_agent', 'marketing_manager', 'department_manager', 'finance_manager', 'quality_manager'], 'leader' => true],
        'workshop_request_confirmation'       => ['audience' => 'مقدّم طلب البرنامج (زائر بلا حساب)', 'user_configurable' => false],
        'workshop_request_submitted'          => ['audience' => 'عنوان فريق التصميم الثابت في الإعدادات', 'user_configurable' => false],
        'workshop_request_admin_notification' => ['audience' => 'المشرفون الإداريون', 'roles' => ['admin', 'super_admin']],
        'ambassador_confirmation'             => ['audience' => 'مقدّم طلب السفراء (زائر بلا حساب)', 'user_configurable' => false],
        'ambassador_admin_notification'       => ['audience' => 'المشرفون ومسؤولو الموارد البشرية', 'roles' => ['admin', 'super_admin', 'hr_manager']],
        'ambassador_status_changed'           => ['audience' => 'مقدّم طلب السفراء (زائر بلا حساب)', 'user_configurable' => false],
        'form_submission_confirmation'        => ['audience' => 'من أجاب عن النموذج (مسجّل أو زائر)', 'roles' => null],
        // ── Certificates ─────────────────────────────────────────────────────────
        'certificate_ready'          => ['audience' => 'صاحب الشهادة', 'roles' => null],
        'certificate_rejected'       => ['audience' => 'صاحب الشهادة', 'roles' => null],
        'certificate_revoked'        => ['audience' => 'صاحب الشهادة', 'roles' => null],
        'certificate_needs_approval' => ['audience' => 'المشرفون ومديرو الإدارات والبرامج', 'roles' => ['admin', 'super_admin', 'department_manager', 'programs_manager']],
        'certificate_pdf_failed'     => ['audience' => 'المشرفون التقنيون', 'roles' => ['admin', 'super_admin', 'tech_admin']],
        // ── Oral assessment (the booked student and the instructor) ──────────────
        'oral_assessment_booking_confirmed'    => ['audience' => 'الطالب الحاجز والمدرب', 'roles' => null],
        'oral_assessment_meeting_link_updated' => ['audience' => 'الطالب الحاجز', 'roles' => null],
        'oral_assessment_instructor_message'   => ['audience' => 'الطالب الحاجز', 'roles' => null],
    ];

    /** @return array<int, string> every email type the platform can send (the admin list) */
    public static function keys(): array
    {
        return array_keys(EmailSettingsController::LABELS);
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, EmailSettingsController::LABELS);
    }

    public static function isMandatory(string $key): bool
    {
        return in_array($key, self::MANDATORY, true);
    }

    /** May a registered person set their own preference for this type? (Mandatory, visitor-only and one-time types: no.) */
    public static function isUserConfigurable(string $key): bool
    {
        if (! self::exists($key) || self::isMandatory($key)) {
            return false;
        }

        return self::profile($key)['user_configurable'] ?? true;
    }

    /** The Arabic «من يستقبله فعليًا» line the administrator sees. */
    public static function audienceLabel(string $key): string
    {
        return self::profile($key)['audience'] ?? 'المستلم المرتبط بالحدث';
    }

    /**
     * Can this person receive this type at all? (Step 1 of the decision: eligible by role / permission / targeting rule.)
     * Types nobody can choose for (mandatory, visitor-only) are not «eligible» for a preference, but a registered recipient of a
     * mandatory type is never blocked here: see EmailDeliveryPolicy.
     */
    public static function isEligible(User $user, string $key): bool
    {
        if (! self::isUserConfigurable($key)) {
            return false;
        }

        $profile = self::profile($key);
        $roles = $profile['roles'] ?? null;
        if ($roles === null) {
            return true;
        }

        return $user->hasRole(...$roles)
            || (($profile['leader'] ?? false) && Department::query()->where('leader_id', $user->id)->exists())
            || (($profile['instructor_profile'] ?? false) && Instructor::query()->where('user_id', $user->id)->exists());
    }

    /** Does the type name an explicit audience (staff fan-out) that a recipient must belong to? */
    public static function hasRestrictedAudience(string $key): bool
    {
        return (self::profile($key)['roles'] ?? null) !== null;
    }

    /** @return array<string, mixed> */
    private static function profile(string $key): array
    {
        return self::PROFILES[$key] ?? [];
    }

    /**
     * Types a person can set a preference for, in the admin list's order: those they are eligible for, each once however many
     * roles qualify them.
     *
     * @return array<int, string>
     */
    public static function eligibleKeysFor(User $user): array
    {
        return array_values(array_filter(self::keys(), fn (string $key) => self::isEligible($user, $key)));
    }
}
