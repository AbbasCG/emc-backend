<?php

namespace App\Services;

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
            ->whereIn('status', ['Registered', 'Pending Payment', 'Payment Confirmed'])
            ->pluck('user_id')
            ->all();
    }

    private static function departmentManagerIds(?int $departmentId): array
    {
        if ($departmentId === null) {
            return [];
        }

        return User::where('role', 'department_manager')
            ->where('department_id', $departmentId)
            ->pluck('id')
            ->all();
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
}
