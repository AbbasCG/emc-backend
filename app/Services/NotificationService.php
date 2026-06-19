<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Mail\AdminNewRegistrationMail;
use App\Mail\AssignmentGradedMail;
use App\Mail\AssignmentSubmittedMail;
use App\Mail\CertificateMail;
use App\Mail\CourseCompletionMail;
use App\Mail\CourseEnrollmentMail;
use App\Mail\LearningPathCompletionMail;
use App\Mail\LearningPathEnrollmentMail;
use App\Mail\WelcomeMail;
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
     */
    private const DEDUP_WINDOW_SECONDS = 10;

    // ── Core send ────────────────────────────────────────────────────────────

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

        $entityId  = $extra['entity_id'] ?? null;
        $windowStart = now()->subSeconds(self::DEDUP_WINDOW_SECONDS);

        // Remove user IDs that already received an identical notification in the
        // deduplication window. This is a safety net against double-dispatch bugs.
        if ($entityId !== null) {
            $alreadyNotified = PlatformNotification::whereIn('user_id', $ids)
                ->where('type', $type)
                ->where('entity_id', $entityId)
                ->where('created_at', '>=', $windowStart)
                ->pluck('user_id')
                ->all();

            if (! empty($alreadyNotified)) {
                $ids = array_values(array_diff($ids, $alreadyNotified));
            }
        }

        if (empty($ids)) {
            return;
        }

        $now  = now();
        $base = [
            'type'        => $type,
            'title'       => $title,
            'message'     => $message,
            'entity_type' => $extra['entity_type'] ?? null,
            'entity_id'   => $entityId,
            'action_url'  => $extra['action_url']  ?? null,
            'icon'        => $extra['icon']         ?? 'bell',
            'color'       => $extra['color']        ?? 'blue',
            'is_read'     => false,
            'created_by'  => $extra['created_by']  ?? null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $rows = array_map(fn ($uid) => array_merge($base, ['user_id' => $uid]), $ids);

        PlatformNotification::insert($rows);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

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
            ->whereIn('status', [RegistrationStatus::REGISTERED->value, RegistrationStatus::PENDING_PAYMENT->value, RegistrationStatus::PAYMENT_CONFIRMED->value])
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
                'action_url'  => '/profile',
                'icon'        => 'user',
                'color'       => 'blue',
            ]
        );

        $user = User::find($userId);
        if ($user?->email) {
            EmailService::dispatch(
                'welcome',
                $user->email,
                $user->name,
                $userId,
                new WelcomeMail($user->name, $user->email, $tempPassword),
                'مرحباً بك في منصة EMC'
            );
        }
    }

    // ── Volunteer request ────────────────────────────────────────────────────

    public static function volunteerRequestSubmitted(int $requestId, string $fullName, string $desiredDepartment): void
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
                'action_url'  => "/dashboard/super-admin/volunteer-requests/{$requestId}",
                'icon'        => 'hand-raised',
                'color'       => 'green',
            ]
        );
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
                'action_url'  => "/dashboard/super-admin/volunteer-requests/{$requestId}",
                'icon'        => 'user-plus',
                'color'       => 'blue',
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
                'action_url'  => '/dashboard/student/courses',
                'icon'        => 'check-circle',
                'color'       => 'green',
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
                'action_url'  => '/dashboard/student/courses',
                'icon'        => 'users',
                'color'       => 'green',
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
        self::send($studentUserId, 'certificate_issued',
            'تم إصدار شهادتك',
            "تهانينا! تم إصدار شهادة إتمام الدورة: {$courseTitle}. رمز التحقق: {$verificationCode}",
            [
                'entity_type' => 'certificate',
                'entity_id'   => $certificateId,
                'action_url'  => "/dashboard/student/certificates",
                'icon'        => 'badge-check',
                'color'       => 'yellow',
            ]
        );

        $student = User::find($studentUserId);
        if ($student?->email) {
            EmailService::dispatch(
                'certificate_issued',
                $student->email,
                $student->name,
                $studentUserId,
                new CertificateMail($student->name, $courseTitle, null, $verificationCode),
                "شهادتك جاهزة: {$courseTitle}"
            );
        }
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
                'action_url'  => "/dashboard/student/assignments",
                'icon'        => 'clipboard-list',
                'color'       => 'orange',
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
}
