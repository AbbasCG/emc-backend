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
     * The ONE definition of the points of each task size. validatePointsForScope() judges by the RANGE (min..max, `max`
     * null = no cap); `options` is what a dropdown offers (every step of the range; for the uncapped strategic size a
     * fixed ladder). Validation stays range based on purpose: an in-range legacy value that is not an option (35 is, 45 is,
     * but a stored 43 or a strategic 320 would not be) is still valid, so the UI shows the options and always includes the
     * value already stored.
     */
    public const SCOPE_POINTS = [
        Task::SCOPE_SIMPLE => ['label' => 'البسيطة', 'min' => 25, 'max' => 50, 'step' => 5],
        Task::SCOPE_MEDIUM => ['label' => 'المتوسطة', 'min' => 75, 'max' => 150, 'step' => 5],
        Task::SCOPE_LARGE => ['label' => 'الكبيرة', 'min' => 200, 'max' => 300, 'step' => 10],
        Task::SCOPE_STRATEGIC => ['label' => 'الاستراتيجية', 'min' => 300, 'max' => null, 'options' => [300, 350, 400, 500, 600, 750, 1000]],
    ];

    /** @return array<string, array<int, int>> the options a dropdown offers, per size */
    public static function pointsOptions(): array
    {
        $out = [];
        foreach (self::SCOPE_POINTS as $scope => $def) {
            $out[$scope] = $def['options'] ?? range($def['min'], $def['max'], $def['step']);
        }

        return $out;
    }

    /** @return array<string, array{min: int, max: ?int}> */
    public static function scopeRanges(): array
    {
        return array_map(fn ($def) => ['min' => $def['min'], 'max' => $def['max']], self::SCOPE_POINTS);
    }

    /**
     * التحقق من مطابقة النقاط مع حجم المهمة
     */
    public static function validatePointsForScope(?string $scope, ?int $points): array
    {
        if ($points === null) {
            return ['valid' => true];
        }

        $def = self::SCOPE_POINTS[$scope] ?? null;
        if ($def === null) {
            return ['valid' => true];
        }

        if ($points < $def['min'] || ($def['max'] !== null && $points > $def['max'])) {
            // The wording is unchanged from the original per-size messages.
            return ['valid' => false, 'message' => $scope === Task::SCOPE_STRATEGIC
                ? 'نقاط المهمة الاستراتيجية أو عالية الأثر تبدأ من 300 نقطة فما فوق.'
                : 'نقاط المهمة '.$def['label'].' يجب أن تتراوح بين '.$def['min'].' و '.$def['max'].' نقطة.'];
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
            $firstCredit = true;
            if ($task->points && $task->points > 0) {
                // (يمنع VolunteerCreditService الازدواجية إذا كان قد تم منحه مسبقاً لهذه المهمة)
                $award = VolunteerCreditService::awardPoints(
                    (int) $task->assigned_to,
                    (int) $task->points,
                    'task',
                    'إنجاز مهمة: ' . $task->title,
                    'اعتماد نهائي للمهمة #' . $task->id . ' (حجم: ' . ($task->task_scope ?? 'بسيط') . ')',
                    $approver->id,
                    Task::class,
                    (int) $task->id,
                );
                // A null award means this task's points are already in the ledger: it was credited before, so its hours must not be added again.
                $firstCredit = $award !== null;
            }

            // 2. إضافة الساعات التراكمية في ملف المتطوع (مرة واحدة لكل إنجاز)
            if ($firstCredit && $task->estimated_hours && $task->estimated_hours > 0) {
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

