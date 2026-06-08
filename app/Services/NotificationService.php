<?php

namespace App\Services;

use App\Enums\RegistrationStatus;
use App\Models\Department;
use App\Models\PlatformNotification;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Carbon;

class NotificationService
{
    // ── Core send ────────────────────────────────────────────────────────────

    public static function send(
        int|array $userIds,
        string $type,
        string $title,
        string $message,
        array $extra = []
    ): void {
        $ids  = is_array($userIds) ? $userIds : [$userIds];
        $ids  = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            return;
        }

        $now  = now();
        $base = [
            'type'        => $type,
            'title'       => $title,
            'message'     => $message,
            'entity_type' => $extra['entity_type'] ?? null,
            'entity_id'   => $extra['entity_id']   ?? null,
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

        // Notify admins
        self::send(self::adminUserIds(), 'new_registration',
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

    public static function accountCreated(int $userId, string $userName): void
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
}
