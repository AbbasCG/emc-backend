<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Models\VolunteerProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskWorkflowService
{
    /**
     * التحقق من مطابقة النقاط مع حجم المهمة
     */
    public static function validatePointsForScope(?string $scope, ?int $points): array
    {
        if ($points === null) {
            return ['valid' => true];
        }

        switch ($scope) {
            case Task::SCOPE_SIMPLE:
                if ($points < 25 || $points > 50) {
                    return ['valid' => false, 'message' => 'نقاط المهمة البسيطة يجب أن تتراوح بين 25 و 50 نقطة.'];
                }
                break;

            case Task::SCOPE_MEDIUM:
                if ($points < 75 || $points > 150) {
                    return ['valid' => false, 'message' => 'نقاط المهمة المتوسطة يجب أن تتراوح بين 75 و 150 نقطة.'];
                }
                break;

            case Task::SCOPE_LARGE:
                if ($points < 200 || $points > 300) {
                    return ['valid' => false, 'message' => 'نقاط المهمة الكبيرة يجب أن تتراوح بين 200 و 300 نقطة.'];
                }
                break;

            case Task::SCOPE_STRATEGIC:
                if ($points < 300) {
                    return ['valid' => false, 'message' => 'نقاط المهمة الاستراتيجية أو عالية الأثر تبدأ من 300 نقطة فما فوق.'];
                }
                break;
        }

        return ['valid' => true];
    }

    /**
     * إضافة النقاط وساعات التطوع التراكمية للعضو المستلم بعد الاعتماد النهائي
     */
    public static function creditVolunteerHoursAndPoints(Task $task, User $approver): void
    {
        if (! $task->assigned_to) {
            return;
        }

        DB::transaction(function () use ($task, $approver) {
            // 1. إضافة نقاط الأثر إلى ليدجر النقاط
            if ($task->points && $task->points > 0) {
                // (يمنع VolunteerCreditService الازدواجية إذا كان قد تم منحه مسبقاً لهذه المهمة)
                VolunteerCreditService::awardPoints(
                    (int) $task->assigned_to,
                    (int) $task->points,
                    'task',
                    'إنجاز مهمة: ' . $task->title,
                    'اعتماد نهائي للمهمة #' . $task->id . ' (حجم: ' . ($task->task_scope ?? 'بسيط') . ')',
                    $approver->id,
                    Task::class,
                    (int) $task->id,
                );
            }

            // 2. إضافة الساعات التراكمية في ملف المتطوع
            if ($task->estimated_hours && $task->estimated_hours > 0) {
                VolunteerCreditService::addHours((int) $task->assigned_to, $task->estimated_hours);
            }

            Log::info("Task #{$task->id} approved and credited: {$task->points} points, {$task->estimated_hours} hours to user #{$task->assigned_to}");
        });
    }

    /**
     * التحقق من أهلية العضو لاستلام مهمة (يمنع المنقطع أو المجاز أو المنسحب)
     */
    public static function canAssignTaskToUser(int $userId): array
    {
        $profile = VolunteerProfile::where('user_id', $userId)->first();
        if ($profile) {
            if ($profile->status === VolunteerProfile::STATUS_INACTIVE) {
                return ['allowed' => false, 'message' => 'لا يمكن إسناد مهام لعضو منقطع عن العمل التطوعي.'];
            }
            if ($profile->status === VolunteerProfile::STATUS_ON_LEAVE) {
                return ['allowed' => false, 'message' => 'لا يمكن إسناد مهام لعضو في فترة إجازة.'];
            }
            if ($profile->status === VolunteerProfile::STATUS_WITHDRAWN) {
                return ['allowed' => false, 'message' => 'لا يمكن إسناد مهام لعضو منسحب من الفريق.'];
            }
        }
        return ['allowed' => true];
    }
}

