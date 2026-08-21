<?php

namespace App\Services;

use App\Models\VolunteerPointAward;

/**
 * بوابة منح نقاط أثر EMC من الأنظمة الآلية (تقارير الاجتماعات/الأسبوعية).
 *
 * القواعد المفعّلة (قابلة للضبط من هذه الثوابت وحدها):
 * - حضور اجتماع رسمي: +50 نقطة، بسقف 200 شهريًا من فئة الاجتماعات (سياسة §12).
 * - تميز التقييم العشري: متوسط ≥ 9 يمنح +50، ومتوسط ≥ 7 يمنح +25 (فئة الجودة).
 * - المصدر يُوثَّق دائمًا (source_type/source_id) ولا يُحتسب مرتين: إعادة حفظ
 *   نفس المصدر تُبدل منحه السابقة بدل مراكمتها.
 */
class ImpactPoints
{
    public const MEETING_ATTENDANCE_POINTS = 50;
    public const MEETING_MONTHLY_CAP       = 200;
    public const EXCELLENCE_THRESHOLD      = 9.0;
    public const EXCELLENCE_POINTS         = 50;
    public const GOOD_THRESHOLD            = 7.0;
    public const GOOD_POINTS               = 25;

    /** مجموع نقاط فئةٍ ما لمستخدمٍ خلال الشهر الحالي — لتطبيق السقف الشهري. */
    public static function monthlyCategoryTotal(int $userId, string $category): int
    {
        return (int) VolunteerPointAward::query()
            ->where('user_id', $userId)
            ->where('category', $category)
            ->whereBetween('awarded_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('points');
    }

    /** يمسح منح مصدرٍ بعينه — يسبق إعادة المنح عند إعادة حفظ التقرير نفسه. */
    public static function revokeSource(string $sourceType, int $sourceId): void
    {
        VolunteerPointAward::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->delete();
    }

    public static function award(
        int $userId,
        string $category,
        int $points,
        string $reason,
        ?int $awardedBy,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): void {
        if ($points === 0) {
            return;
        }

        VolunteerPointAward::create([
            'user_id'     => $userId,
            'category'    => $category,
            'points'      => $points,
            'reason'      => $reason,
            'awarded_by'  => $awardedBy,
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'awarded_at'  => now(),
        ]);
    }

    /** حضور اجتماع: +50 مع احترام سقف الشهر (§12). يعيد النقاط الممنوحة فعلًا. */
    public static function awardMeetingAttendance(
        int $userId,
        string $meetingTitle,
        ?int $awardedBy,
        string $sourceType,
        int $sourceId,
    ): int {
        $remaining = self::MEETING_MONTHLY_CAP - self::monthlyCategoryTotal($userId, 'meetings');
        $points    = max(0, min(self::MEETING_ATTENDANCE_POINTS, $remaining));

        self::award(
            $userId,
            'meetings',
            $points,
            "حضور اجتماع: {$meetingTitle}",
            $awardedBy,
            $sourceType,
            $sourceId,
        );

        return $points;
    }

    /** مكافأة التقييم العشري: متوسط ≥9 → +50، ≥7 → +25، دونها لا مكافأة. */
    public static function awardEvaluationBonus(
        int $userId,
        float $avgScore,
        string $context,
        ?int $awardedBy,
        string $sourceType,
        int $sourceId,
    ): int {
        $points = 0;
        if ($avgScore >= self::EXCELLENCE_THRESHOLD) {
            $points = self::EXCELLENCE_POINTS;
        } elseif ($avgScore >= self::GOOD_THRESHOLD) {
            $points = self::GOOD_POINTS;
        }

        if ($points > 0) {
            self::award(
                $userId,
                'quality_bonus',
                $points,
                "تقييم أداء {$avgScore}/10 — {$context}",
                $awardedBy,
                $sourceType,
                $sourceId,
            );
        }

        return $points;
    }
}
