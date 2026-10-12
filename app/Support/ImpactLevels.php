<?php

namespace App\Support;

/**
 * سلم مستويات نقاط أثر EMC — مصدر واحد للمستويات.
 *
 * المستوى يُحتسب من شرطين معًا: المدة تعطي الأهلية، والنقاط تثبت الاستحقاق.
 * والاعتماد النوعي لمستوى «رائد أثر EMC» قرار إداري لا يُمنح آليًا، لذلك هذا
 * هو «المستوى المؤهَّل له» وليس اعتمادًا نهائيًا.
 */
final class ImpactLevels
{
    /** [id, الاسم، أشهر الحد الأدنى، نقاط الحد الأدنى، باقة سديم]. */
    public const LEVELS = [
        ['id' => 'volunteer',     'title' => 'متطوع EMC',     'months' => 0,  'points' => 0,     'sadeem' => 'Open Community'],
        ['id' => 'contributor',   'title' => 'متطوع مشارك',   'months' => 3,  'points' => 1500,  'sadeem' => 'Standard'],
        ['id' => 'active',        'title' => 'متطوع فاعل',    'months' => 6,  'points' => 3500,  'sadeem' => 'Premium'],
        ['id' => 'advanced',      'title' => 'متطوع متقدم',   'months' => 9,  'points' => 6000,  'sadeem' => 'Premium'],
        ['id' => 'senior',        'title' => 'متطوع أول',     'months' => 12, 'points' => 10000, 'sadeem' => 'VIP'],
        ['id' => 'impact_leader', 'title' => 'رائد أثر EMC', 'months' => 24, 'points' => 22000, 'sadeem' => 'VIP+'],
    ];

    /** @return array{id:string,title:string,months:int,points:int,sadeem:string} */
    public static function eligible(int $lifetimePoints, int $activeMonths): array
    {
        $eligible = self::LEVELS[0];
        foreach (self::LEVELS as $level) {
            if ($activeMonths >= $level['months'] && $lifetimePoints >= $level['points']) {
                $eligible = $level;
            }
        }

        return $eligible;
    }

    /** @return array{id:string,title:string,months:int,points:int,sadeem:string}|null */
    public static function next(array $eligible): ?array
    {
        foreach (self::LEVELS as $level) {
            if ($level['points'] > $eligible['points']) {
                return $level;
            }
        }

        return null;
    }
}
