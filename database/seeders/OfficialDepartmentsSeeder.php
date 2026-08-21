<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * الإدارات الـ13 المعتمدة لـ EMC — المصدر الوحيد للمسميات الرسمية.
 * الزرع بالـslug فيُحدَّث الاسم دون كسر أي ارتباطات قائمة.
 * أي واجهة تعرض أسماء الإدارات يجب أن تطابق هذه القائمة حرفيًا.
 */
class OfficialDepartmentsSeeder extends Seeder
{
    /** [slug => [الاسم الرسمي، الاسم الإنجليزي، اللون، الأيقونة، الترتيب]] */
    public const DEPARTMENTS = [
        'executive-management' => ['الإدارة العليا',                                'Executive Management',              '#1a1a2e', 'crown',           1],
        'strategy-planning'    => ['إدارة الاستراتيجية والتخطيط المؤسسي',          'Strategy & Institutional Planning', '#14213d', 'compass',         2],
        'programs-tracks'      => ['إدارة البرامج والمسارات',                      'Programs & Tracks',                 '#16213e', 'academic-cap',    3],
        'operations'           => ['إدارة العمليات والتشغيل',                      'Operations',                        '#0f3460', 'cog',             4],
        'finance'              => ['الإدارة المالية',                              'Finance',                           '#2d6a4f', 'currency-dollar', 5],
        'community-health'     => ['إدارة الصحة النفسية والوعي',                   'Mental Wellbeing & Awareness',      '#b5838d', 'heart',           6],
        'technology'           => ['الإدارة التقنية والفنية',                      'Technology & Technical',            '#1b4332', 'chip',            7],
        'quality-governance'   => ['إدارة الجودة والحوكمة',                        'Quality & Governance',              '#457b9d', 'shield-check',    8],
        'partnerships'         => ['إدارة الشراكات والعلاقات',                     'Partnerships & Relations',          '#533483', 'handshake',       9],
        'human-resources'      => ['إدارة الموارد البشرية والشؤون القانونية',      'HR & Legal Affairs',                '#6d6875', 'users',           10],
        'marketing-media'      => ['إدارة الإعلام والتسويق',                       'Media & Marketing',                 '#e94560', 'megaphone',       11],
        'ai-transformation'    => ['إدارة الذكاء الاصطناعي والتحول الرقمي',        'AI & Digital Transformation',       '#0E5A8A', 'cpu',             12],
        'advisors'             => ['إدارة الاستشارات والمستشارين',                 'Advisory & Consultants',            '#0077B6', 'user-check',      13],
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $slug => [$nameAr, $nameEn, $color, $icon, $sort]) {
            Department::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'       => $nameAr,
                    'name_ar'    => $nameAr,
                    'name_en'    => $nameEn,
                    'color'      => $color,
                    'icon'       => $icon,
                    'sort_order' => $sort,
                    'status'     => 'active',
                ],
            );
        }

        $this->command?->info('✓ الإدارات الـ13 الرسمية موحّدة.');
    }
}
