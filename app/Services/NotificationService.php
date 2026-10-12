<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Mail\AccountCreatedMail;
use App\Mail\AdminNewRegistrationMail;
use App\Mail\AssignmentGradedMail;
use App\Mail\AssignmentSubmittedMail;
use App\Mail\CourseCompletionMail;
use App\Mail\CourseEnrollmentMail;
use App\Mail\LearningPathCompletionMail;
use App\Mail\LearningPathEnrollmentMail;
use App\Mail\VolunteerApplicationAdminMail;
use App\Mail\WelcomeMail;
use App\Models\VolunteerRequest;
use App\Models\ClassGroupStudent;
use App\Models\CourseMaterial;
use App\Models\Department;
use App\Models\PlatformNotification;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Carbon;

class NotificationService
{
    /**
     * Deduplication window in seconds.
     *
     * If a notification with the same (type, entity_id, user_id) was created
     * within this many seconds, the new one is silently skipped.
     * Prevents double-sends caused by mis-configured double event registration.
     *
     * Referenced by SendPlatformNotificationJob (the actual dedup check lives
     * there) — this is the single source of truth for the window value.
     * Previously declared but unused: the job hardcoded its own literal `10`.
     */
    public const DEDUP_WINDOW_SECONDS = 10;

    /** The channel key NotificationPreference rows use for in-app PlatformNotification delivery. */
    public const IN_APP_CHANNEL = 'in_app';

    // ── Core send ────────────────────────────────────────────────────────────

    /**
     * @param  array  $extra  Recognized keys include (all optional): entity_type, entity_id,
     *   action_url, meta_url, icon, color, created_by, and idempotency_key. `idempotency_key`
     *   is an explicit opt-in deterministic dedup key (Step 11 of the canonical-architecture
     *   pass) — when supplied, SendPlatformNotificationJob dedups by (user_id, type,
     *   idempotency_key) instead of the legacy time-window check, so the same semantic event
     *   can never double-insert regardless of timing, while a distinct key for a legitimate
     *   repeat event (e.g. a later assignment resubmission) still creates a new row. Callers
     *   that omit it keep the original, unchanged time-window+lock dedup behavior.
     */
    public static function send(
        int|array $userIds,
        string $type,
        string $title,
        string $message,
        array $extra = []
    ): void {
        $ids = is_array($userIds) ? $userIds : [$userIds];
        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            return;
        }

        // Off the request thread — see SendPlatformNotificationJob for the
        // actual dedup + insert logic (moved there verbatim). afterCommit()
        // ensures the job never runs before a surrounding DB::transaction()
        // (many callers, including the new content-lifecycle notifications,
        // call send() from inside one) — without it, a non-sync queue worker
        // could query for a row the transaction hasn't committed yet. With
        // QUEUE_CONNECTION=sync (tests, and until a real queue worker is
        // configured) Laravel still runs afterCommit dispatches synchronously
        // once any active transaction commits, so existing callers observe
        // the same PlatformNotification rows right after send() returns —
        // zero behavior change for the 36+ existing call sites.
        \App\Jobs\SendPlatformNotificationJob::dispatch($ids, $type, $title, $message, $extra)->afterCommit();
    }

    // ── Private helpers ──────────────────────────────────────────────────────
    // (meta_url sanitization now lives in SendPlatformNotificationJob, next to
    // the insert it protects.)

    private static function adminUserIds(): array
    {
        return User::whereIn('role', ['admin', 'super_admin', 'executive_admin'])
            ->pluck('id')
            ->all();
    }

    private static function enrolledStudentUserIds(int $courseId): array
    {
        return Registration::where('course_id', $courseId)
            ->whereNotNull('user_id')
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->pluck('user_id')
            ->all();
    }

    private static function departmentManagerIds(?int $departmentId): array
    {
        if ($departmentId === null) {
            return [];
        }

        // users table has no department_id — use the department's leader_id instead
        $leaderId = \App\Models\Department::where('id', $departmentId)->value('leader_id');

        return $leaderId ? [$leaderId] : [];
    }

    // ── Notification scenarios ───────────────────────────────────────────────

    public static function courseCreated(int $courseId, string $courseTitle, ?int $departmentId, int $createdByUserId): void
    {
        $recipients = array_unique(array_merge(
            self::adminUserIds(),
            self::departmentManagerIds($departmentId),
        ));

        self::send($recipients, 'course_created',
            'دورة جديدة أُضيفت',
            "تم إضافة الدورة: {$courseTitle}",
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('course_created', $courseId),
                'action_url'  => "/admin/courses/{$courseId}",
                'icon'        => 'book-open',
                'color'       => 'green',
                'created_by'  => $createdByUserId,
            ]
        );
    }

    public static function instructorAssigned(int $instructorUserId, int $courseId, string $courseTitle, int $assignedByUserId): void
    {
        self::send($instructorUserId, 'instructor_assigned',
            'تم تعيينك كمدرب',
            "تم تعيينك مدرباً للدورة: {$courseTitle}",
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                // Recipient (instructorUserId) is already isolated by the
                // dedup query's own user_id filter — not embedded in the key.
                'idempotency_key' => NotificationIdempotencyKey::forEntity('instructor_assigned', $courseId),
                'action_url'  => "/instructor/courses/{$courseId}",
                'icon'        => 'user-check',
                'color'       => 'blue',
                'created_by'  => $assignedByUserId,
            ]
        );
    }

    public static function workshopInstructorAssigned(int $instructorUserId, int $workshopId, string $workshopTitle, int $assignedByUserId): void
    {
        self::send($instructorUserId, 'workshop_instructor_assigned',
            'تم تعيينك في ورشة عمل',
            "تم تعيينك مدرباً لورشة العمل: {$workshopTitle}",
            [
                'entity_type' => 'workshop',
                'entity_id'   => $workshopId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('workshop_instructor_assigned', $workshopId),
                'action_url'  => "/instructor/workshops/{$workshopId}",
                'icon'        => 'clipboard-list',
                'color'       => 'indigo',
                'created_by'  => $assignedByUserId,
            ]
        );
    }

    public static function courseDateUpdated(int $courseId, string $courseTitle, ?string $newDate, int $instructorUserId, ?int $departmentId = null): void
    {
        $dateLabel  = $newDate ?? 'سيتم تحديدها لاحقاً';
        $recipients = array_unique(array_merge(
            [$instructorUserId],
            self::enrolledStudentUserIds($courseId),
        ));

        self::send($recipients, 'course_date_updated',
            'تحديث موعد الدورة',
            "تم تحديث موعد الدورة \"{$courseTitle}\" إلى: {$dateLabel}",
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                // Class C — a genuinely new date is a new, real event; a
                // retry of the same update dedups via the normalized date.
                'idempotency_key' => NotificationIdempotencyKey::forScheduleChange('course_date_updated', $courseId, $newDate),
                'action_url'  => "/courses/{$courseId}",
                'icon'        => 'calendar',
                'color'       => 'yellow',
            ]
        );
    }

    public static function registrationOpened(int $courseId, string $courseTitle, ?int $departmentId = null): void
    {
        $recipients = self::adminUserIds();

        self::send($recipients, 'registration_opened',
            'فُتح التسجيل',
            "فُتح التسجيل في الدورة: {$courseTitle}",
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('registration_opened', $courseId),
                'action_url'  => "/admin/courses/{$courseId}",
                'icon'        => 'lock-open',
                'color'       => 'green',
            ]
        );
    }

    public static function studentRegistered(int $studentUserId, int $courseId, string $courseTitle, int $registrationId): void
    {
        // Notify the student
        self::send($studentUserId, 'student_registered',
            'تم تسجيلك في الدورة',
            "تم تسجيلك بنجاح في الدورة: {$courseTitle}",
            [
                'entity_type' => 'registration',
                'entity_id'   => $registrationId,
                'action_url'  => "/student/registrations/{$registrationId}",
                'icon'        => 'check-circle',
                'color'       => 'green',
            ]
        );

        $student = User::find($studentUserId);
        if ($student?->email) {
            EmailService::dispatch(
                'course_enrollment',
                $student->email,
                $student->name,
                $studentUserId,
                new CourseEnrollmentMail($student->name, $courseTitle, ''),
                "تم تسجيلك في الدورة: {$courseTitle}"
            );
        }

        // Notify admins
        $adminIds = self::adminUserIds();
        self::send($adminIds, 'new_registration',
            'تسجيل جديد',
            "طالب جديد سجّل في الدورة: {$courseTitle}",
            [
                'entity_type' => 'registration',
                'entity_id'   => $registrationId,
                'action_url'  => "/admin/registrations/{$registrationId}",
                'icon'        => 'user-plus',
                'color'       => 'blue',
            ]
        );

        $adminUsers = User::whereIn('id', $adminIds)->whereNotNull('email')->get();
        foreach ($adminUsers as $admin) {
            EmailService::dispatch(
                'admin_new_registration',
                $admin->email,
                $admin->name,
                $admin->id,
                new AdminNewRegistrationMail(
                    $admin->name,
                    $student?->name ?? '',
                    $student?->email ?? '',
                    $courseTitle,
                    $registrationId
                ),
                "تسجيل جديد: {$courseTitle}"
            );
        }
    }

    public static function registrationConfirmed(int $studentUserId, int $courseId, string $courseTitle, int $registrationId): void
    {
        self::send($studentUserId, 'registration_confirmed',
            'تأكيد التسجيل',
            "تم تأكيد تسجيلك في الدورة: {$courseTitle}",
            [
                'entity_type' => 'registration',
                'entity_id'   => $registrationId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('registration_confirmed', $registrationId),
                'action_url'  => "/student/registrations/{$registrationId}",
                'icon'        => 'badge-check',
                'color'       => 'green',
            ]
        );
    }

    public static function workshopRequestSubmitted(int $requestId, string $programName, string $requesterName): void
    {
        self::send(self::adminUserIds(), 'workshop_request_submitted',
            'طلب برنامج تدريبي جديد',
            "قدّم {$requesterName} طلباً لبرنامج: {$programName}",
            [
                'entity_type' => 'workshop_request',
                'entity_id'   => $requestId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('workshop_request_submitted', $requestId),
                'action_url'  => "/admin/workshop-requests/{$requestId}",
                'icon'        => 'inbox',
                'color'       => 'purple',
            ]
        );
    }

    public static function workshopRequestMovedToStep(
        int $requestId,
        string $programName,
        string $requesterName,
        int $stepNumber,
        string $departmentKey,
        string $departmentLabel
    ): void {
        $recipients = User::where('department', $departmentKey)
            ->pluck('id')
            ->all();

        if (empty($recipients)) {
            $roles = self::rolesForWorkflowDepartment($departmentKey);
            if (!empty($roles)) {
                $recipients = User::whereIn('role', $roles)->pluck('id')->all();
            }
        }

        if (empty($recipients)) {
            return;
        }

        self::send($recipients, 'workshop_request_step',
            'طلب برنامج تدريبي يحتاج مراجعتك',
            "طلب \"{$programName}\" من {$requesterName} وصل للمرحلة {$stepNumber}: {$departmentLabel}",
            [
                'entity_type' => 'workshop_request',
                'entity_id'   => $requestId,
                // Class C — the same request legitimately moves through
                // several steps; each step number is a distinct real event.
                'idempotency_key' => NotificationIdempotencyKey::forEntityWithComponent('workshop_request_step', $requestId, $stepNumber),
                'action_url'  => "/dashboard/admin/workshop-requests/{$requestId}",
                'icon'        => 'clipboard-check',
                'color'       => 'blue',
            ]
        );
    }

    public static function workshopRequestRejected(
        int $requestId,
        string $programName,
        string $requesterName,
        int $atStep
    ): void {
        self::send(self::adminUserIds(), 'workshop_request_rejected',
            'رُفض طلب برنامج تدريبي',
            "تم رفض طلب \"{$programName}\" من {$requesterName} عند المرحلة {$atStep}",
            [
                'entity_type' => 'workshop_request',
                'entity_id'   => $requestId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('workshop_request_rejected', $requestId),
                'action_url'  => "/dashboard/admin/workshop-requests/{$requestId}",
                'icon'        => 'x-circle',
                'color'       => 'red',
            ]
        );
    }

    public static function courseCancelled(int $courseId, string $courseTitle): void
    {
        $recipients = array_unique(array_merge(
            self::adminUserIds(),
            self::enrolledStudentUserIds($courseId),
        ));

        self::send($recipients, 'course_cancelled',
            'إلغاء الدورة',
            "تم إلغاء الدورة: {$courseTitle}",
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('course_cancelled', $courseId),
                'action_url'  => "/courses/{$courseId}",
                'icon'        => 'x-circle',
                'color'       => 'red',
            ]
        );
    }

    public static function courseCompleted(int $courseId, string $courseTitle): void
    {
        $recipients = array_unique(array_merge(
            self::adminUserIds(),
            self::enrolledStudentUserIds($courseId),
        ));

        self::send($recipients, 'course_completed',
            'اكتملت الدورة',
            "اكتملت الدورة: {$courseTitle}",
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('course_completed', $courseId),
                'action_url'  => "/courses/{$courseId}",
                'icon'        => 'academic-cap',
                'color'       => 'green',
            ]
        );
    }

    public static function accountCreated(int $userId, string $userName, ?string $tempPassword = null): void
    {
        self::send($userId, 'account_created',
            'مرحباً بك في المنصة',
            "أهلاً {$userName}، تم إنشاء حسابك بنجاح.",
            [
                'entity_type' => 'user',
                'entity_id'   => $userId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('account_created', $userId),
                'action_url'  => '/profile',
                'icon'        => 'user',
                'color'       => 'blue',
            ]
        );

        $user = User::find($userId);
        if ($user?->email) {
            if ($tempPassword) {
                // Admin-created account with temporary password
                EmailService::dispatch(
                    'account_created',
                    $user->email,
                    $user->name,
                    $userId,
                    new AccountCreatedMail($user->name, $user->email, $tempPassword),
                    'مرحباً بك في منصة EMC',
                );
            } else {
                // Self-registered or no temp password
                EmailService::dispatch(
                    'welcome',
                    $user->email,
                    $user->name,
                    $userId,
                    new WelcomeMail($user->name, $user->email, null),
                    'مرحباً بك في منصة EMC',
                );
            }
        }
    }

    // ── Volunteer request ────────────────────────────────────────────────────

    public static function volunteerRequestSubmitted(int $requestId, string $fullName, string $desiredDepartment, ?VolunteerRequest $volunteerRequest = null): void
    {
        // Base: super_admin + admin + executive_admin + hr_manager always notified
        $baseIds = User::whereIn('role', ['super_admin', 'admin', 'executive_admin', 'hr_manager'])
            ->pluck('id')
            ->all();

        // Department-specific additional roles based on the free-text department name
        $extraRoles = self::rolesForVolunteerDepartment($desiredDepartment);
        $extraIds   = $extraRoles
            ? User::whereIn('role', $extraRoles)->pluck('id')->all()
            : [];

        // If the department exists in the DB and has a designated leader, include them
        $leaderIds = self::volunteerDepartmentLeaderIds($desiredDepartment);

        $recipients = array_values(array_unique(array_merge($baseIds, $extraIds, $leaderIds)));

        self::send(
            $recipients,
            'volunteer_request',
            'طلب تطوع جديد',
            "تم استلام طلب تطوع جديد من {$fullName} لقسم {$desiredDepartment}.",
            [
                'entity_type' => 'volunteer_request',
                'entity_id'   => $requestId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('volunteer_request_submitted', $requestId),
                'action_url'  => "/dashboard/super-admin/volunteer-requests/{$requestId}",
                'icon'        => 'hand-raised',
                'color'       => 'green',
            ]
        );

        // Email notification to all admin recipients who have an email address
        if ($volunteerRequest) {
            $adminUsers = User::whereIn('id', $recipients)->whereNotNull('email')->get(['id', 'name', 'email']);
            foreach ($adminUsers as $admin) {
                EmailService::dispatch(
                    'volunteer_admin_notification',
                    $admin->email,
                    $admin->name,
                    $admin->id,
                    VolunteerApplicationAdminMail::fromRequest($volunteerRequest, $admin->name, $requestId),
                    "طلب تطوع جديد — {$fullName}",
                );
            }
        }
    }

    // ── Volunteer → member conversion ────────────────────────────────────────

    public static function volunteerConvertedToMember(
        int $requestId,
        int $memberId,
        string $fullName,
        string $departmentName,
        ?int $departmentLeaderId = null
    ): void {
        $baseIds = User::whereIn('role', ['super_admin', 'admin', 'hr_manager'])
            ->pluck('id')
            ->all();

        $leaderIds = $departmentLeaderId ? [$departmentLeaderId] : [];

        $recipients = array_values(array_unique(array_merge($baseIds, $leaderIds)));

        self::send(
            $recipients,
            'member_converted',
            'تم إضافة عضو جديد',
            "تم تحويل طلب التطوع الخاص بـ {$fullName} إلى عضو في قسم {$departmentName}.",
            [
                'entity_type' => 'team_profile',
                'entity_id'   => $memberId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('volunteer_converted', $requestId),
                'action_url'  => "/dashboard/super-admin/volunteer-requests/{$requestId}",
                'icon'        => 'user-plus',
                'color'       => 'blue',
            ]
        );
    }

    // ── Volunteer HR profile notifications ───────────────────────────────────

    public static function volunteerHrProfileSubmitted(int $profileId, string $fullName): void
    {
        $recipients = User::whereIn('role', ['super_admin', 'admin', 'hr_manager'])->pluck('id')->all();

        self::send(
            $recipients,
            'volunteer_hr_profile_submitted',
            'طلب انضمام متطوع جديد',
            "قام {$fullName} بإرسال بيانات ملفه التعريفي كمتطوع.",
            [
                'entity_type'      => 'volunteer_hr_profile',
                'entity_id'        => $profileId,
                'idempotency_key'  => NotificationIdempotencyKey::forEntity('volunteer_hr_profile_submitted', $profileId),
                'action_url'       => "/dashboard/hr/volunteers/{$profileId}",
                'icon'             => 'user-plus',
                'color'            => 'blue',
            ]
        );
    }

    public static function volunteerHrProfileApproved(int $profileId, string $fullName, ?int $volunteerUserId): void
    {
        if (! $volunteerUserId) {
            return;
        }

        self::send(
            [$volunteerUserId],
            'volunteer_hr_profile_approved',
            'تم قبول ملفك التعريفي',
            'قامت إدارة الموارد البشرية بقبول ملفك التعريفي كمتطوع وإضافتك إلى الفريق.',
            [
                'entity_type'     => 'volunteer_hr_profile',
                'entity_id'       => $profileId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('volunteer_hr_profile_approved', $profileId),
                'icon'            => 'check-circle',
                'color'           => 'green',
            ]
        );
    }

    public static function volunteerHrProfileRejected(int $profileId, string $fullName, ?int $volunteerUserId, string $reason): void
    {
        if (! $volunteerUserId) {
            return;
        }

        self::send(
            [$volunteerUserId],
            'volunteer_hr_profile_rejected',
            'تم رفض ملفك التعريفي',
            "قامت إدارة الموارد البشرية برفض ملفك التعريفي. السبب: {$reason}",
            [
                'entity_type'     => 'volunteer_hr_profile',
                'entity_id'       => $profileId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('volunteer_hr_profile_rejected', $profileId),
                'icon'            => 'x-circle',
                'color'           => 'red',
            ]
        );
    }

    // ── Placement test notifications ─────────────────────────────────────────

    /** Notify enrolled students that an instructor or schedule was assigned to their course. */
    public static function courseScheduleUpdated(int $courseId, string $courseTitle): void
    {
        $recipients = self::enrolledStudentUserIds($courseId);

        if (empty($recipients)) {
            return;
        }

        self::send($recipients, 'course_schedule_updated',
            'تم تحديث بيانات الدورة',
            "تم تعيين موعد أو مدرب لدورتك: {$courseTitle}",
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                // Class C in principle (a later re-assignment to a
                // different instructor is a real, distinct event), but the
                // method receives no distinguishing value beyond courseId
                // (its sole caller, InstructorAssignedListener, does not
                // pass one) — keyed on courseId alone. Known, accepted
                // limitation: two instructor re-assignments for the same
                // course within the 10s-equivalent window would dedup;
                // extremely rare in practice and not worth a signature
                // change for this pass.
                'idempotency_key' => NotificationIdempotencyKey::forEntity('course_schedule_updated', $courseId),
                'action_url'  => '/dashboard/student/courses',
                'icon'        => 'calendar',
                'color'       => 'blue',
            ]
        );
    }

    /** Notify the course instructor that a student has completed the written placement test. */
    public static function placementTestCompleted(
        int $instructorUserId,
        int $courseId,
        string $courseTitle,
        string $studentName,
        int $score,
        int $totalQuestions,
        string $estimatedLevel
    ): void {
        self::send($instructorUserId, 'placement_test_completed',
            'اختبار تحديد مستوى مكتمل',
            "أكمل الطالب {$studentName} اختبار تحديد المستوى بنتيجة {$score}/{$totalQuestions} وينتظر المقابلة الشفوية.",
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                // No attempt/student id is passed to this method (only the
                // display name) — best available deterministic identity is
                // course + student name. Known limitation: two students
                // sharing an identical display name in the same course
                // would collide; judged acceptable rather than widening
                // this method's signature for this pass.
                'idempotency_key' => NotificationIdempotencyKey::fromComponents('placement_test_completed', [$courseId, $studentName]),
                'action_url'  => "/dashboard/instructor/courses/{$courseId}/placement-students",
                'icon'        => 'academic-cap',
                'color'       => 'purple',
            ]
        );
    }

    /** Notify a student that their oral assessment slot has been booked. */
    public static function oralAssessmentBooked(int $studentUserId, string $courseTitle, string $slotDatetime): void
    {
        self::send($studentUserId, 'oral_assessment_booked',
            'تم حجز موعد المقابلة الشفهية',
            "تم حجز موعد مقابلتك الشفهية لدورة \"{$courseTitle}\" في: {$slotDatetime}",
            [
                'entity_type' => 'course',
                'action_url'  => '/dashboard/student/courses',
                'icon'        => 'microphone',
                'color'       => 'green',
            ]
        );
    }

    /** Notify a student that their oral assessment has been completed and a final level assigned. */
    public static function oralAssessmentCompleted(int $studentUserId, string $courseTitle, string $finalLevel): void
    {
        self::send($studentUserId, 'oral_assessment_completed',
            'تم إكمال تقييمك الشفهي',
            "تم إكمال تقييمك الشفهي لدورة \"{$courseTitle}\" ومستواك المحدد هو: {$finalLevel}",
            [
                'entity_type' => 'course',
                // No entity id is passed to this method — course title is
                // the only stable-enough distinguishing value available; a
                // student re-taking an oral assessment for the exact same
                // course title would dedup (in this platform's flow, one
                // oral assessment per course is the expected occurrence).
                'idempotency_key' => NotificationIdempotencyKey::fromComponents('oral_assessment_completed', [$courseTitle]),
                'action_url'  => '/dashboard/student/courses',
                'icon'        => 'check-circle',
                'color'       => 'green',
            ]
        );
    }

    /** Notify a student that their oral assessment booking status changed (cancelled, no-show, etc). */
    public static function oralAssessmentStatusChanged(
        int $studentUserId,
        string $courseTitle,
        string $statusLabel,
        ?string $reason = null,
    ): void {
        $message = "تم تحديث حالة موعد المقابلة الشفهية لدورة \"{$courseTitle}\" إلى: {$statusLabel}";
        if ($reason) {
            $message .= " — {$reason}";
        }
        self::send($studentUserId, 'oral_assessment_status_changed',
            'تحديث حالة موعد المقابلة الشفهية',
            $message,
            [
                'entity_type' => 'course',
                // Class C — a genuinely new status is a new event; a retry
                // of the same status change dedups.
                'idempotency_key' => NotificationIdempotencyKey::fromComponents('oral_assessment_status_changed', [$courseTitle, $statusLabel]),
                'action_url'  => '/dashboard/student/courses',
                'icon'        => 'microphone',
                'color'       => 'amber',
            ]
        );
    }

    /** Notify a student that their oral assessment has been rescheduled. */
    public static function oralAssessmentRescheduled(
        int $studentUserId,
        string $courseTitle,
        string $newSlotDatetime,
    ): void {
        self::send($studentUserId, 'oral_assessment_rescheduled',
            'تم تعديل موعد المقابلة الشفهية',
            "تم نقل موعد مقابلتك الشفهية لدورة \"{$courseTitle}\" إلى: {$newSlotDatetime}",
            [
                'entity_type' => 'course',
                // Class C — a genuinely new slot is a new event; a retry of
                // the same reschedule to the same slot dedups.
                'idempotency_key' => NotificationIdempotencyKey::fromComponents('oral_assessment_rescheduled', [$courseTitle, $newSlotDatetime]),
                'action_url'  => '/dashboard/student/courses',
                'icon'        => 'calendar',
                'color'       => 'blue',
            ]
        );
    }

    /** Deliver a free-text message from the instructor about a specific booking.
     *  $meetingUrl, when present, is exposed to the student as a clickable
     *  "open meeting" action — never just left as unlinked text in the body. */
    public static function oralAssessmentInstructorMessage(
        int $studentUserId,
        int $bookingId,
        string $instructorName,
        string $body,
        ?string $meetingUrl = null,
    ): void {
        self::send($studentUserId, 'oral_assessment_message',
            "رسالة من المدرب {$instructorName}",
            $body,
            [
                'entity_type' => 'oral_assessment_booking',
                'entity_id'   => $bookingId,
                'action_url'  => '/dashboard/student/courses',
                'meta_url'    => $meetingUrl,
                'icon'        => 'chat-bubble-left-right',
                'color'       => 'blue',
            ]
        );
    }

    /** Notify a student that they have been assigned to a class group. */
    public static function classGroupAssigned(
        int $studentUserId,
        string $classGroupName,
        string $courseTitle,
        ?string $instructorName,
        ?string $scheduleDay,
        ?string $scheduleTime,
        ?string $locationType
    ): void {
        $locationLabel = match ($locationType) {
            'in_person' => 'حضوري',
            'hybrid'    => 'هجين',
            default     => 'أونلاين',
        };

        $details = array_filter([$scheduleDay, $scheduleTime ? substr($scheduleTime, 0, 5) : null]);
        $scheduleStr = ! empty($details) ? implode(' - ', $details) : null;

        $message = "تم إلحاقك بالفصل: {$classGroupName}";
        if ($instructorName) {
            $message .= " | المدرب: {$instructorName}";
        }
        if ($scheduleStr) {
            $message .= " | الموعد: {$scheduleStr}";
        }
        $message .= " | {$locationLabel}";

        self::send($studentUserId, 'class_group_assigned',
            'تم تعيينك في فصل دراسي',
            $message,
            [
                'entity_type' => 'class_group',
                // No class_group_id is passed to this method — class name +
                // course title is the best available deterministic
                // identity. Known limitation: two identically-named class
                // groups in the same course would collide; not expected in
                // practice (class names are chosen to be distinct).
                'idempotency_key' => NotificationIdempotencyKey::fromComponents('class_group_assigned', [$classGroupName, $courseTitle]),
                'action_url'  => '/dashboard/student/courses',
                'icon'        => 'users',
                'color'       => 'green',
            ]
        );
    }

    /** Notify the instructor that a new student was automatically assigned to their class group. */
    public static function classGroupStudentAssignedInstructor(
        int $instructorUserId,
        string $studentName,
        string $classGroupName,
        string $courseTitle,
    ): void {
        self::send($instructorUserId, 'class_group_student_assigned',
            'طالب جديد في فصلك',
            "تم إلحاق الطالب {$studentName} تلقائيًا بفصلك \"{$classGroupName}\" في دورة \"{$courseTitle}\".",
            [
                'entity_type' => 'class_group',
                'idempotency_key' => NotificationIdempotencyKey::fromComponents('class_group_student_assigned', [$classGroupName, $studentName]),
                'action_url'  => '/dashboard/instructor/classes',
                'icon'        => 'users',
                'color'       => 'blue',
            ]
        );
    }

    // ── LMS learning notifications ────────────────────────────────────────────

    /**
     * Notify a student that their assignment submission has been graded.
     * Triggered by: PUT /api/instructor/submissions/{submission}/review
     */
    public static function assignmentGraded(
        int $studentUserId,
        int $courseId,
        string $courseTitle,
        string $assignmentTitle,
        ?float $score,
        ?float $maxScore,
        ?string $feedback,
        int $submissionId
    ): void {
        $scoreLabel = ($score !== null)
            ? ($maxScore !== null ? "{$score}/{$maxScore}" : (string) $score)
            : null;

        $message = "تم تقييم واجبك \"{$assignmentTitle}\" في الدورة: {$courseTitle}";
        if ($scoreLabel) {
            $message .= " — الدرجة: {$scoreLabel}";
        }

        self::send($studentUserId, 'assignment_graded',
            'تم تقييم واجبك',
            $message,
            [
                'entity_type' => 'assignment_submission',
                'entity_id'   => $submissionId,
                // Class C (occurrence-specific) — the same submission can
                // legitimately be re-graded (a resubmission cycle, or an
                // instructor correcting a score), and each distinct score
                // is a real, separate notification-worthy event; keying on
                // score alone (not a review/version id, which the schema
                // does not currently track) is an approximation — two
                // re-grades that happen to land on the identical score
                // would still dedup. Documented, not silently claimed as a
                // perfect key.
                'idempotency_key' => "assignment_graded:{$submissionId}:{$scoreLabel}",
                'action_url'  => "/dashboard/student/assignments/{$submissionId}",
                'icon'        => 'academic-cap',
                'color'       => 'green',
            ]
        );

        $student = User::find($studentUserId);
        if ($student?->email) {
            EmailService::dispatch(
                'assignment_graded',
                $student->email,
                $student->name,
                $studentUserId,
                new AssignmentGradedMail($student->name, $courseTitle, $assignmentTitle, $score, $maxScore, $feedback),
                "تم تقييم واجبك: {$assignmentTitle}"
            );
        }
    }

    /**
     * Notify a student that their certificate has been issued.
     * Triggered by: CertificateService::issue()
     */
    public static function certificateIssued(
        int $studentUserId,
        int $certificateId,
        string $courseTitle,
        string $verificationCode
    ): void {
        // In-app notification only — the email is sent separately via EmailService
        // when the PDF is confirmed ready (CertificatePublishService or SendCertificateReadyNotification).
        self::send($studentUserId, 'certificate_issued',
            'تم إصدار شهادتك',
            "تهانينا! تم إصدار شهادة إتمام الدورة: {$courseTitle}.",
            [
                'entity_type' => 'certificate',
                'entity_id'   => $certificateId,
                // Class A (deterministically idempotent) — a given certificate
                // is issued once; the key is stable per-certificate forever.
                'idempotency_key' => "certificate_issued:{$certificateId}",
                'action_url'  => "/dashboard/student/certificates",
                'icon'        => 'badge-check',
                'color'       => 'yellow',
            ]
        );
    }

    /**
     * Notify a user that a report they requested has finished generating.
     * Triggered by: GenerateReportJob::handle() — the canonical replacement
     * for that job's prior direct SendNotificationJob call (Ticket 8
     * canonical-architecture pass; report_ready now writes to
     * platform_notifications like every other scenario, not the disjoint
     * built-in `notifications` table).
     */
    public static function reportReady(int $userId, int $reportId): void
    {
        self::send($userId, 'report_ready',
            'تقريرك جاهز',
            "تم إنشاء التقرير رقم #{$reportId} بنجاح.",
            [
                'entity_type' => 'report',
                'entity_id'   => $reportId,
                // Class A — a given report finishes generating once.
                'idempotency_key' => "report_ready:{$reportId}",
                'action_url'  => '/dashboard/reports',
                'icon'        => 'file-text',
                'color'       => 'blue',
            ]
        );
    }

    /**
     * Notify all enrolled students in a course that a new assignment was created.
     * Triggered by: POST /api/instructor/courses/{course}/assignments
     */
    public static function assignmentCreatedForStudents(
        int $courseId,
        string $courseTitle,
        string $assignmentTitle,
        ?string $deadline,
        int $courseAssignmentId
    ): void {
        $recipients = self::enrolledStudentUserIds($courseId);
        if (empty($recipients)) {
            return;
        }

        $message = "تم إضافة واجب جديد \"{$assignmentTitle}\" في الدورة: {$courseTitle}";
        if ($deadline) {
            $message .= " — الموعد النهائي: {$deadline}";
        }

        self::send($recipients, 'assignment_created',
            'واجب جديد في دورتك',
            $message,
            [
                'entity_type' => 'course_assignment',
                'entity_id'   => $courseAssignmentId,
                // Class A — a given course assignment is created once.
                'idempotency_key' => "assignment_created:{$courseAssignmentId}",
                'action_url'  => "/dashboard/student/assignments",
                'icon'        => 'clipboard-list',
                'color'       => 'orange',
            ]
        );
    }

    /**
     * Notify enrolled students that an assignment's deadline changed.
     * Triggered by: PATCH /api/admin/lms/assignments/{courseAssignment}
     * (Ticket 5 — distinct from courseDateUpdated, which is about the
     * course's own date, not a specific assignment's deadline.)
     */
    public static function assignmentDeadlineChanged(
        int $courseId,
        string $courseTitle,
        string $assignmentTitle,
        ?string $newDeadline,
        int $courseAssignmentId
    ): void {
        $recipients = self::enrolledStudentUserIds($courseId);
        if (empty($recipients)) {
            return;
        }

        $dateLabel = $newDeadline ?? 'سيتم تحديده لاحقاً';

        self::send($recipients, 'assignment_deadline_changed',
            'تغيّر موعد تسليم واجب',
            "تم تحديث الموعد النهائي لواجب \"{$assignmentTitle}\" في الدورة: {$courseTitle} إلى: {$dateLabel}",
            [
                'entity_type' => 'course_assignment',
                'entity_id'   => $courseAssignmentId,
                // Class C — a genuinely new deadline is a new event; a
                // retry of the same deadline change dedups.
                'idempotency_key' => NotificationIdempotencyKey::forScheduleChange('assignment_deadline_changed', $courseAssignmentId, $newDeadline),
                'action_url'  => "/dashboard/student/assignments",
                'icon'        => 'clipboard-list',
                'color'       => 'yellow',
            ]
        );
    }

    /**
     * Notify the instructor that a student has submitted an assignment.
     * Triggered by: POST /api/student/assignments/{assignment}/submit
     */
    public static function studentSubmittedAssignment(
        int $instructorUserId,
        string $studentName,
        string $courseTitle,
        string $assignmentTitle,
        int $submissionId
    ): void {
        self::send($instructorUserId, 'assignment_submitted',
            'تسليم واجب جديد',
            "قدّم الطالب {$studentName} واجب \"{$assignmentTitle}\" في الدورة: {$courseTitle}",
            [
                'entity_type' => 'assignment_submission',
                'entity_id'   => $submissionId,
                'action_url'  => "/dashboard/instructor/submissions/{$submissionId}",
                'icon'        => 'document-check',
                'color'       => 'blue',
            ]
        );

        $instructor = User::find($instructorUserId);
        if ($instructor?->email) {
            $reviewUrl = config('app.frontend_url', config('app.url')) . "/dashboard/instructor/submissions/{$submissionId}";
            EmailService::dispatch(
                'assignment_submitted',
                $instructor->email,
                $instructor->name,
                $instructorUserId,
                new AssignmentSubmittedMail($instructor->name, $studentName, $courseTitle, $assignmentTitle, $reviewUrl),
                "تسليم واجب جديد: {$assignmentTitle}"
            );
        }
    }

    /**
     * Notify the instructor that a student resubmitted after a revision request.
     */
    public static function studentResubmittedAssignment(
        int $instructorUserId,
        string $studentName,
        string $courseTitle,
        string $assignmentTitle,
        int $submissionId
    ): void {
        self::send($instructorUserId, 'assignment_resubmitted',
            'تمت إعادة تسليم الواجب',
            "قام الطالب \"{$studentName}\" بإعادة تسليم واجب \"{$assignmentTitle}\" في الدورة: {$courseTitle}",
            [
                'entity_type' => 'assignment_submission',
                'entity_id'   => $submissionId,
                'action_url'  => "/dashboard/instructor/submissions/{$submissionId}",
                'icon'        => 'document-check',
                'color'       => 'green',
            ]
        );
    }

    /**
     * Notify the student that the instructor has requested a new submission.
     */
    public static function requestedResubmission(
        int $studentUserId,
        string $courseTitle,
        string $assignmentTitle,
        ?string $feedback,
        int $submissionId
    ): void {
        $body = "يرجى إعادة تسليم واجب \"{$assignmentTitle}\" في دورة \"{$courseTitle}\".";
        if ($feedback) {
            $body .= " ملاحظة المدرب: {$feedback}";
        }

        self::send($studentUserId, 'resubmission_requested',
            'طلب إعادة تسليم الواجب',
            $body,
            [
                'entity_type' => 'assignment_submission',
                'entity_id'   => $submissionId,
                'action_url'  => '/dashboard/student/assignments',
                'icon'        => 'exclamation-circle',
                'color'       => 'amber',
            ]
        );
    }

    /**
     * Notify all enrolled students that a session was created or updated.
     * Triggered by: POST|PUT /api/instructor/courses/{course}/sessions
     */
    public static function sessionScheduled(
        int $courseId,
        string $courseTitle,
        string $sessionTitle,
        ?string $startsAt,
        bool $isUpdate = false
    ): void {
        $recipients = self::enrolledStudentUserIds($courseId);
        if (empty($recipients)) {
            return;
        }

        $verb    = $isUpdate ? 'تم تحديث' : 'تمت إضافة';
        $message = "{$verb} جلسة \"{$sessionTitle}\" في الدورة: {$courseTitle}";
        if ($startsAt) {
            try {
                $dt = \Carbon\Carbon::parse($startsAt)->setTimezone('Europe/Amsterdam');
                $message .= ' — الموعد: ' . self::formatArabicDatetime($dt);
            } catch (\Throwable) {
                // omit datetime rather than embed a raw ISO string
            }
        }

        self::send($recipients, 'session_scheduled',
            $isUpdate ? 'تحديث موعد جلسة' : 'جلسة جديدة في دورتك',
            $message,
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                // Class C — a course can legitimately schedule many sessions,
                // and an update to the same session's time is itself a
                // separate notification-worthy event; keying on the actual
                // new start time (plus create-vs-update) means a genuinely
                // new session or a genuine reschedule always gets its own
                // key, while a duplicate dispatch of the exact same
                // create/update event still dedups.
                'idempotency_key' => "session_scheduled:{$courseId}:" . ($startsAt ?? 'unscheduled') . ':' . ($isUpdate ? 'update' : 'create'),
                'action_url'  => "/dashboard/student/sessions",
                'icon'        => 'video-camera',
                'color'       => 'indigo',
            ]
        );
    }

    /**
     * Notify a student of their recorded attendance status for a session.
     * Triggered by: PUT /api/instructor/attendance/{session}
     */
    public static function attendanceMarked(
        int $studentUserId,
        string $courseTitle,
        string $sessionTitle,
        string $status,
        int $courseId
    ): void {
        $statusLabel = match ($status) {
            'present' => 'حاضر ✓',
            'absent'  => 'غائب',
            'late'    => 'متأخر',
            'excused' => 'غياب بعذر',
            default   => $status,
        };

        $color = match ($status) {
            'present' => 'green',
            'absent'  => 'red',
            'late'    => 'yellow',
            'excused' => 'blue',
            default   => 'gray',
        };

        self::send($studentUserId, 'attendance_marked',
            'تم تسجيل حضورك',
            "تم تسجيل حضورك لجلسة \"{$sessionTitle}\" في الدورة: {$courseTitle} — الحالة: {$statusLabel}",
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                'action_url'  => "/dashboard/student/attendance",
                'icon'        => 'clipboard-check',
                'color'       => $color,
            ]
        );
    }

    /**
     * Ticket 6: notify a student after N consecutive absences in a row.
     * Triggered by: SessionAttendanceController::update() when a save
     * results in the student's most recent attendance rows all being absent.
     */
    public static function repeatedAbsence(int $studentUserId, string $courseTitle, int $consecutiveAbsences, int $courseId): void
    {
        self::send($studentUserId, 'attendance_repeated_absence',
            'غياب متكرر',
            "لديك {$consecutiveAbsences} غيابات متتالية في الدورة: {$courseTitle}. يرجى التواصل مع المدرّب.",
            [
                'entity_type' => 'course', 'entity_id' => $courseId,
                'action_url'  => '/dashboard/student/attendance',
                'icon' => 'exclamation-triangle', 'color' => 'red',
            ]
        );
    }

    /**
     * Ticket 6: notify a student whose attendance percentage has crossed
     * back up to/above the warning threshold after previously being below
     * it. Callers must pass the percentage from BEFORE this save and AFTER
     * this save so this method can detect the actual transition — it never
     * fires just because attendance is currently fine (that would spam a
     * student who was never at risk).
     */
    public static function attendanceRecovered(int $studentUserId, string $courseTitle, int $newPercentage, int $courseId): void
    {
        self::send($studentUserId, 'attendance_recovered',
            'تحسّنت نسبة حضورك',
            "عادت نسبة حضورك في الدورة \"{$courseTitle}\" إلى {$newPercentage}%، وهي الآن ضمن الحد المطلوب.",
            [
                'entity_type' => 'course', 'entity_id' => $courseId,
                'action_url'  => '/dashboard/student/attendance',
                'icon' => 'check-circle', 'color' => 'green',
            ]
        );
    }

    /**
     * Ticket 6: notify the instructor once a session's attendance has been
     * finalized (locked) — never fires again for the same session/lock.
     * Triggered by: SessionAttendanceController::update() only on the FIRST
     * save that creates the AttendanceLock, not on subsequent admin edits.
     */
    public static function classAttendanceCompleted(int $instructorUserId, string $courseTitle, string $sessionTitle, int $presentCount, int $totalCount, int $courseId): void
    {
        self::send($instructorUserId, 'class_attendance_completed',
            'تم إنهاء تسجيل الحضور',
            "تم إنهاء تسجيل الحضور لجلسة \"{$sessionTitle}\" في الدورة: {$courseTitle} — {$presentCount}/{$totalCount} حاضر.",
            [
                'entity_type' => 'course', 'entity_id' => $courseId,
                'action_url'  => '/dashboard/instructor/attendance',
                'icon' => 'clipboard-check', 'color' => 'blue',
            ]
        );
    }

    /**
     * Ticket 6: notify a student whose overall attendance percentage has
     * dropped below the platform's warning threshold.
     * Triggered by: SessionAttendanceController::update().
     */
    public static function lowAttendanceWarning(int $studentUserId, string $courseTitle, int $attendancePercentage, int $courseId): void
    {
        self::send($studentUserId, 'attendance_low_warning',
            'تحذير: نسبة حضور منخفضة',
            "نسبة حضورك في الدورة \"{$courseTitle}\" هي {$attendancePercentage}%، وهي أقل من الحد الأدنى المطلوب.",
            [
                'entity_type' => 'course', 'entity_id' => $courseId,
                'action_url'  => '/dashboard/student/attendance',
                'icon' => 'exclamation-circle', 'color' => 'orange',
            ]
        );
    }

    /**
     * Notify every active member of a class that a new announcement was
     * published. $studentUserIds must already be filtered to
     * ClassGroupStudent::ACTIVE_STATUSES members of the announcement's own
     * class_group_id — this method does not re-derive eligibility.
     */
    public static function classAnnouncementPublished(
        array $studentUserIds,
        string $courseTitle,
        string $className,
        string $announcementTitle,
        int $classGroupId,
        int $announcementId
    ): void {
        self::send($studentUserIds, 'class_announcement_published',
            $announcementTitle,
            "إعلان جديد في صف \"{$className}\" — الدورة: {$courseTitle}",
            [
                'entity_type' => 'class_announcement',
                'entity_id'   => $announcementId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('class_announcement_published', $announcementId),
                'action_url'  => "/dashboard/student/classes/{$classGroupId}/announcements",
                'icon'        => 'megaphone',
                'color'       => 'blue',
            ]
        );
    }

    /** Notify the instructor that a student has booked an oral assessment slot. */
    public static function oralAssessmentBookedInstructor(
        int $instructorUserId,
        string $studentName,
        string $courseTitle,
        string $startsAt
    ): void {
        self::send($instructorUserId, 'oral_assessment_booked',
            'تم حجز مقابلة شفوية',
            "حجز الطالب {$studentName} مقابلة شفوية لدورة {$courseTitle} بتاريخ {$startsAt}.",
            [
                'entity_type' => 'course',
                'action_url'  => '/dashboard/instructor/oral-assessments',
                'icon'        => 'calendar',
                'color'       => 'blue',
            ]
        );
    }

    /**
     * Notify a student that the instructor added, changed, or removed the
     * meeting link for their already-booked oral assessment. Keyed so a
     * distinct notification is kept per (booking, link value) — a genuine
     * link change is a new, real event, not a retry of the same one.
     */
    public static function oralAssessmentMeetingLinkUpdated(
        int $studentUserId,
        string $courseTitle,
        int $bookingId,
        ?string $meetingLink,
    ): void {
        $message = $meetingLink
            ? "تم تحديث رابط اجتماع مقابلتك الشفهية لدورة \"{$courseTitle}\"."
            : "تمت إزالة رابط اجتماع مقابلتك الشفهية لدورة \"{$courseTitle}\" مؤقتًا. سيتم إضافته قريبًا.";

        self::send($studentUserId, 'oral_assessment_meeting_link_updated',
            'تحديث رابط الاجتماع',
            $message,
            [
                'entity_type' => 'oral_assessment_booking',
                'entity_id'   => $bookingId,
                'idempotency_key' => NotificationIdempotencyKey::forEntityWithComponent(
                    'oral_assessment_meeting_link_updated',
                    $bookingId,
                    $meetingLink ?? 'removed',
                ),
                'action_url' => '/dashboard/student/courses',
                'icon'       => 'link',
                'color'      => 'blue',
            ]
        );
    }

    // Maps a workflow department key to platform roles for notification fallback.
    private static function rolesForWorkflowDepartment(string $departmentKey): array
    {
        return match ($departmentKey) {
            'quality_governance' => ['quality_manager'],
            'programs_tracks'    => ['department_manager', 'programs_manager'],
            'operations'         => ['operations_manager'],
            'finance'            => ['finance_manager'],
            'media_marketing'    => ['marketing_manager'],
            'executive_admin'    => ['executive_admin'],
            default              => [],
        };
    }

    // Maps a free-text desired_department string to platform roles that should be notified.
    private static function rolesForVolunteerDepartment(string $department): array
    {
        $d = mb_strtolower($department);

        if (str_contains($d, 'موارد بشري') || str_contains($d, 'hr')) {
            return ['hr_manager'];
        }
        if (str_contains($d, 'تقني') || str_contains($d, 'دعم فني')) {
            return ['support_agent'];
        }
        if (str_contains($d, 'تسويق') || str_contains($d, 'إعلام')) {
            return ['marketing_manager'];
        }
        if (str_contains($d, 'شراك') || str_contains($d, 'علاقات')) {
            return ['department_manager'];
        }
        if (str_contains($d, 'برامج') || str_contains($d, 'مسارات')) {
            return ['department_manager'];
        }
        if (str_contains($d, 'مالي') || str_contains($d, 'finance')) {
            return ['finance_manager'];
        }
        if (str_contains($d, 'جودة') || str_contains($d, 'حوكمة')) {
            return ['quality_manager'];
        }

        // Unknown department — base recipients (super_admin/admin/hr) already cover it
        return [];
    }

    // Finds the leader of the matching department row, if one is set.
    private static function volunteerDepartmentLeaderIds(string $departmentName): array
    {
        $dept = Department::where(function ($q) use ($departmentName) {
            $q->where('name_ar', 'like', "%{$departmentName}%")
              ->orWhere('name',    'like', "%{$departmentName}%");
        })
        ->whereNotNull('leader_id')
        ->first();

        return $dept?->leader_id ? [$dept->leader_id] : [];
    }

    // ── New email-only notification methods ──────────────────────────────────

    public static function learningPathEnrolled(
        int    $studentUserId,
        string $pathTitle,
        int    $coursesCount,
        int    $pathId,
        ?string $estimatedDuration = null
    ): void {
        self::send($studentUserId, 'learning_path_enrolled',
            'انضممت إلى مسار تعليمي',
            "تم تسجيلك في المسار التعليمي: {$pathTitle}",
            [
                'entity_type' => 'learning_path',
                'entity_id'   => $pathId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('learning_path_enrolled', $pathId),
                'action_url'  => "/dashboard/student/learning-paths/{$pathId}",
                'icon'        => 'map',
                'color'       => 'green',
            ]
        );

        $student = User::find($studentUserId);
        if ($student?->email) {
            $pathUrl = config('app.frontend_url', config('app.url')) . "/dashboard/student/learning-paths/{$pathId}";
            EmailService::dispatch(
                'learning_path_enrollment',
                $student->email,
                $student->name,
                $studentUserId,
                new LearningPathEnrollmentMail($student->name, $pathTitle, $coursesCount, $estimatedDuration, $pathUrl),
                "انضممت إلى مسار: {$pathTitle}"
            );
        }
    }

    public static function learningPathCompleted(
        int    $studentUserId,
        string $pathTitle,
        int    $coursesCompleted,
        int    $pathId,
        ?string $certificateUrl = null
    ): void {
        self::send($studentUserId, 'learning_path_completed',
            'أكملت المسار التعليمي',
            "تهانينا! أكملت المسار التعليمي: {$pathTitle}",
            [
                'entity_type' => 'learning_path',
                'entity_id'   => $pathId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('learning_path_completed', $pathId),
                'action_url'  => "/dashboard/student/certificates",
                'icon'        => 'trophy',
                'color'       => 'yellow',
            ]
        );

        $student = User::find($studentUserId);
        if ($student?->email) {
            EmailService::dispatch(
                'learning_path_completion',
                $student->email,
                $student->name,
                $studentUserId,
                new LearningPathCompletionMail($student->name, $pathTitle, $coursesCompleted, $certificateUrl),
                "تهانينا! أكملت المسار: {$pathTitle}"
            );
        }
    }

    public static function courseStudentCompleted(
        int    $studentUserId,
        string $courseTitle,
        string $completionDate,
        int    $courseId,
        ?string $certificateUrl = null
    ): void {
        self::send($studentUserId, 'course_completed_student',
            'أتممت الدورة بنجاح',
            "تهانينا! أتممت الدورة: {$courseTitle}",
            [
                'entity_type' => 'course',
                'entity_id'   => $courseId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('course_student_completed', $courseId),
                'action_url'  => "/dashboard/student/certificates",
                'icon'        => 'academic-cap',
                'color'       => 'green',
            ]
        );

        $student = User::find($studentUserId);
        if ($student?->email) {
            EmailService::dispatch(
                'course_completion',
                $student->email,
                $student->name,
                $studentUserId,
                new CourseCompletionMail($student->name, $courseTitle, $completionDate, $certificateUrl),
                "تهانينا! أتممت الدورة: {$courseTitle}"
            );
        }
    }

    /**
     * Format a Carbon datetime as a human-readable Arabic string in Amsterdam local time.
     * e.g. "18 يونيو 2026، 23:00"
     */
    private static function formatArabicDatetime(\Carbon\Carbon $dt): string
    {
        $months = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس',    4 => 'أبريل',
            5 => 'مايو',  6 => 'يونيو',  7 => 'يوليو',   8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];
        return "{$dt->day} {$months[$dt->month]} {$dt->year}، {$dt->format('H:i')}";
    }

    // ── Ambassador applications ───────────────────────────────────────────────

    public static function ambassadorApplicationSubmitted(int $applicationId, string $fullName, string $university): void
    {
        $recipients = User::whereIn('role', ['super_admin', 'admin', 'hr_manager'])
            ->pluck('id')
            ->all();

        self::send(
            $recipients,
            'ambassador_application',
            'طلب سفير تحول رقمي جديد',
            "تقدّم {$fullName} من {$university}",
            [
                'entity_type' => 'ambassador_application',
                'entity_id'   => $applicationId,
                'idempotency_key' => NotificationIdempotencyKey::forEntity('ambassador_application_submitted', $applicationId),
                'action_url'  => "/dashboard/super-admin/ambassador-applications/{$applicationId}",
                'icon'        => 'star',
                'color'       => 'blue',
            ]
        );

        // Email notification to each admin/HR
        $admins = User::whereIn('role', ['super_admin', 'admin', 'hr_manager'])->get();
        $app    = \App\Models\AmbassadorApplication::find($applicationId);

        if ($app) {
            foreach ($admins as $admin) {
                \App\Services\EmailService::dispatch(
                    'ambassador_admin_notification',
                    $admin->email,
                    $admin->name,
                    $admin->id,
                    \App\Mail\AmbassadorApplicationAdminMail::fromApplication($app, $admin->name),
                    'طلب سفير تحول رقمي جديد — ' . $fullName,
                );
            }
        }
    }

    public static function ambassadorStatusChanged(int $applicationId, string $fullName, string $oldStatus, string $newStatus): void
    {
        // No platform notification to the applicant (they may not have an account).
        // Email is dispatched separately in the controller.
        // Notify admins of the status change.
        $recipients = User::whereIn('role', ['super_admin', 'admin', 'hr_manager'])
            ->pluck('id')
            ->all();

        $statusLabels = [
            'under_review'        => 'قيد المراجعة',
            'interview_scheduled' => 'تمت جدولة مقابلة',
            'approved'            => 'تمت الموافقة',
            'rejected'            => 'مرفوض',
            'waitlisted'          => 'على قائمة الانتظار',
            'cancelled'           => 'ملغى',
        ];

        $label = $statusLabels[$newStatus] ?? $newStatus;

        self::send(
            $recipients,
            'ambassador_status_changed',
            "تغيّرت حالة الطلب",
            "{$fullName} — {$label}",
            [
                'entity_type' => 'ambassador_application',
                'entity_id'   => $applicationId,
                // Class C — a genuinely new status is a new event; a retry
                // of the same transition dedups.
                'idempotency_key' => NotificationIdempotencyKey::forStatusTransition('ambassador_status_changed', $applicationId, $newStatus),
                'action_url'  => "/dashboard/super-admin/ambassador-applications/{$applicationId}",
                'icon'        => 'refresh-cw',
                'color'       => 'orange',
            ]
        );
    }

    // ── Ticket 7: content lifecycle ─────────────────────────────────────────
    // Course-global content (modules/lessons) notifies every enrolled student
    // via the existing enrolledStudentUserIds() bulk resolver. Class-scoped
    // materials notify only active members of that class (ClassGroupStudent,
    // the same ACTIVE_STATUSES filter used everywhere else in this session).
    // Callers are responsible for transition detection (old status != active,
    // new status == active) — these methods only send, they never decide.

    public static function moduleActivated(int $courseId, string $courseTitle, string $moduleTitle, int $moduleId): void
    {
        $recipients = self::enrolledStudentUserIds($courseId);
        if (empty($recipients)) {
            return;
        }

        self::send($recipients, 'module_activated',
            'وحدة جديدة متاحة',
            "أصبحت الوحدة \"{$moduleTitle}\" متاحة الآن في دورة {$courseTitle}",
            [
                'entity_type' => 'course_module',
                'entity_id'   => $moduleId,
                // Class A for its first activation. Deactivate-then-later-
                // reactivate is an explicit product decision this pass does
                // not make on its own: the key below means only the FIRST
                // activation notifies a given recipient — a later
                // reactivation of the same module is treated as the same
                // occurrence and stays deduplicated, not re-notified. If a
                // future product decision wants reactivation to notify
                // again, the key needs a real activation-version/timestamp
                // component, which the schema does not currently track.
                'idempotency_key' => NotificationIdempotencyKey::forEntity('module_activated', $moduleId),
                'action_url'  => "/dashboard/student/courses/{$courseId}/modules",
                'icon'        => 'layers',
                'color'       => 'blue',
            ]
        );
    }

    public static function lessonActivated(int $courseId, string $courseTitle, string $lessonTitle, int $lessonId): void
    {
        $recipients = self::enrolledStudentUserIds($courseId);
        if (empty($recipients)) {
            return;
        }

        self::send($recipients, 'lesson_activated',
            'درس جديد متاح',
            "أصبح الدرس \"{$lessonTitle}\" متاحًا الآن في دورة {$courseTitle}",
            [
                'entity_type' => 'lesson',
                'entity_id'   => $lessonId,
                // Same explicit only-first-activation decision as
                // moduleActivated() above — see its comment.
                'idempotency_key' => NotificationIdempotencyKey::forEntity('lesson_activated', $lessonId),
                'action_url'  => "/dashboard/student/lessons/{$lessonId}",
                'icon'        => 'book-open',
                'color'       => 'blue',
            ]
        );
    }

    public static function lessonContentUpdated(int $courseId, string $courseTitle, string $lessonTitle, int $lessonId): void
    {
        $recipients = self::enrolledStudentUserIds($courseId);
        if (empty($recipients)) {
            return;
        }

        self::send($recipients, 'lesson_content_updated',
            'تحديث على درس',
            "تم تحديث محتوى الدرس \"{$lessonTitle}\" في دورة {$courseTitle}",
            [
                'entity_type' => 'lesson',
                'entity_id'   => $lessonId,
                'action_url'  => "/dashboard/student/lessons/{$lessonId}",
                'icon'        => 'refresh-cw',
                'color'       => 'orange',
            ]
        );
    }

    public static function materialVisible(CourseMaterial $material): void
    {
        if ($material->class_group_id) {
            $recipients = ClassGroupStudent::where('class_group_id', $material->class_group_id)
                ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
                ->pluck('user_id')
                ->all();
        } else {
            $recipients = self::enrolledStudentUserIds((int) $material->course_id);
        }

        if (empty($recipients)) {
            return;
        }

        self::send($recipients, 'material_visible',
            'مادة تعليمية جديدة',
            "تمت إضافة مادة جديدة: \"{$material->title}\"",
            [
                'entity_type' => 'course_material',
                'entity_id'   => $material->id,
                // Class A — a given material becoming visible is a single event.
                'idempotency_key' => "material_visible:{$material->id}",
                'action_url'  => "/dashboard/student/materials",
                'icon'        => 'file-text',
                'color'       => 'blue',
            ]
        );
    }

    // ── Ticket status: the person who submitted it hears back ────────────────
    //
    // Reports and suggestions are one model (App\Models\Ticket,
    // ticket_category OLD_ISSUE / NEW_SUGGESTION). TicketStatusObserver decides
    // WHEN these fire (a real status transition, after commit); they only decide
    // WHAT is said. The recipient is always the original submitter. Bodies carry
    // the title and the new status only — never internal notes, resolution
    // summaries or who handled it.

    /** A report (OLD_ISSUE) became RESOLVED. */
    public static function ticketResolved(int $submitterUserId, int $ticketId, string $ticketTitle): void
    {
        $title = self::shortTitle($ticketTitle);

        self::send($submitterUserId, 'ticket_resolved',
            'تم حل البلاغ',
            "تمت معالجة البلاغ \"{$title}\" وتغيير حالته إلى \"تم الحل\". يمكنك عرض التفاصيل من خلال الرابط.",
            [
                'entity_type'     => 'ticket',
                'entity_id'       => $ticketId,
                // Class A — a ticket is resolved once; the key is stable per ticket.
                'idempotency_key' => NotificationIdempotencyKey::forEntity('ticket_resolved', $ticketId),
                'action_url'      => "/dashboard/tickets/{$ticketId}",
                'icon'            => 'check-circle',
                'color'           => 'green',
            ]
        );

        self::sendTicketStatusEmail('ticket_resolved', $submitterUserId, $ticketId, $ticketTitle, 'تم حل البلاغ', 'تم الحل');
    }

    /** A suggestion (NEW_SUGGESTION) was approved by management. */
    public static function suggestionApproved(int $submitterUserId, int $ticketId, string $ticketTitle): void
    {
        $title = self::shortTitle($ticketTitle);

        self::send($submitterUserId, 'suggestion_approved',
            'تم اعتماد المقترح',
            "تم اعتماد المقترح \"{$title}\" من قبل الإدارة وتغيير حالته إلى \"معتمد\". يمكنك متابعة التفاصيل من خلال الرابط.",
            [
                'entity_type'     => 'ticket',
                'entity_id'       => $ticketId,
                // Class A — a suggestion is approved once.
                'idempotency_key' => NotificationIdempotencyKey::forEntity('suggestion_approved', $ticketId),
                'action_url'      => "/dashboard/tickets/{$ticketId}",
                'icon'            => 'thumb-up',
                'color'           => 'blue',
            ]
        );

        self::sendTicketStatusEmail('suggestion_approved', $submitterUserId, $ticketId, $ticketTitle, 'تم اعتماد المقترح', 'معتمد');
    }

    /** A suggestion (NEW_SUGGESTION) was completed / added to the system. */
    public static function suggestionImplemented(int $submitterUserId, int $ticketId, string $ticketTitle): void
    {
        $title = self::shortTitle($ticketTitle);

        self::send($submitterUserId, 'suggestion_implemented',
            'تمت إضافة المقترح إلى النظام',
            "تمت إضافة المقترح \"{$title}\" إلى النظام وتغيير حالته إلى \"تمت الإضافة إلى النظام\". شكرًا لمساهمتك في تطوير المنصة. يمكنك عرض التفاصيل من خلال الرابط.",
            [
                'entity_type'     => 'ticket',
                'entity_id'       => $ticketId,
                // Class A — a suggestion is implemented once.
                'idempotency_key' => NotificationIdempotencyKey::forEntity('suggestion_implemented', $ticketId),
                'action_url'      => "/dashboard/tickets/{$ticketId}",
                'icon'            => 'sparkles',
                'color'           => 'green',
            ]
        );

        self::sendTicketStatusEmail('suggestion_implemented', $submitterUserId, $ticketId, $ticketTitle, 'تمت إضافة المقترح إلى النظام', 'تمت الإضافة إلى النظام');
    }

    private static function shortTitle(string $title): string
    {
        return mb_strimwidth(trim($title), 0, 120, '…');
    }

    /**
     * The logged email that accompanies a ticket status notification.
     *
     * EmailService has no dedup of its own, so an email already logged for this
     * ticket and type is never sent again (including one recorded as skipped
     * because an admin turned the email setting off). Content is the title and
     * the new status only; the payload kept in the email log is ids + status.
     */
    private static function sendTicketStatusEmail(
        string $type,
        int $userId,
        int $ticketId,
        string $ticketTitle,
        string $headline,
        string $statusLabel,
    ): void {
        $user = User::find($userId);
        if (! $user?->email) {
            return;
        }

        $alreadyEmailed = \App\Models\NotificationLog::query()
            ->where('channel', 'email')
            ->where('type', $type)
            ->where('user_id', $userId)
            ->where('payload->ticket_id', $ticketId)
            ->exists();

        if ($alreadyEmailed) {
            return;
        }

        $title = self::shortTitle($ticketTitle);
        $url = rtrim((string) config('app.frontend_url', config('app.url')), '/')."/dashboard/tickets/{$ticketId}";

        EmailService::dispatch(
            $type,
            $user->email,
            $user->name,
            $userId,
            new \App\Mail\TicketStatusUpdateMail($user->name, $type, $title, $statusLabel, $headline, $url),
            "{$headline}: {$title}",
            ['ticket_id' => $ticketId, 'status' => $statusLabel],
        );
    }
}
