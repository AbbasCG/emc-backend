<?php

namespace App\Support;

/** Arabic labels of the `permissions.group` values (same wording as the role editor / permission catalog), «التذاكر» first. */
final class PermissionGroupLabels
{
    public const MAP = [
        'tickets'           => 'التذاكر',
        'users'             => 'المستخدمون',
        'education'         => 'التعليم والبرامج',
        'lms'               => 'نظام إدارة التعلم',
        'finance'           => 'المالية',
        'certificates'      => 'الشهادات',
        'operations'        => 'العمليات',
        'tasks'             => 'المهام والعمليات',
        'hr'                => 'الموارد البشرية',
        'partnerships'      => 'الشراكات',
        'marketing'         => 'التسويق والإعلام',
        'quality'           => 'الجودة والحوكمة',
        'support'           => 'الدعم الفني',
        'reports'           => 'التقارير والمؤشرات',
        'knowledge'         => 'قاعدة المعرفة',
        'ai'                => 'الذكاء الاصطناعي',
        'system'            => 'النظام والإعدادات',
        'tech'              => 'الإدارة التقنية',
        'executive'         => 'القيادة التنفيذية',
        'workshop_requests' => 'طلبات البرامج التدريبية',
        'members'           => 'الأعضاء',
        'departments_g'     => 'الإدارات',
        'courses_g'         => 'الدورات',
        'attendance_g'      => 'الحضور',
        'assignments_g'     => 'الواجبات',
    ];

    public static function label(string $group): string
    {
        return self::MAP[$group] ?? $group;
    }

    /** Sort rank: known groups in MAP order (tickets first), unknown ones after, alphabetically. */
    public static function rank(string $group): int
    {
        $i = array_search($group, array_keys(self::MAP), true);

        return $i === false ? 1000 : $i;
    }

    /** Lower-case + strip Arabic diacritics/tatweel and fold alef/ya/ta-marbuta variants, so a search matches loosely. */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $text) ?? $text;

        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    }
}
