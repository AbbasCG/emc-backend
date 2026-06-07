<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\TeamProfile;
use Illuminate\Database\Seeder;

/**
 * Seeds the official EMC V3 public team profiles.
 *
 * Each member is identified by department slug + role_key for idempotent re-seeding.
 * Safe to re-run: uses updateOrCreate on (department_id, role_key).
 */
class TeamMemberSeeder extends Seeder
{
    public function run(): void
    {
        $map = $this->buildDeptMap();
        $count = 0;

        foreach ($this->members() as $member) {
            $deptId = $map[$member['dept_slug']] ?? null;
            if (! $deptId) {
                $this->command->warn("Department [{$member['dept_slug']}] not found — skipping {$member['name_ar']}.");
                continue;
            }

            unset($member['dept_slug']);

            TeamProfile::updateOrCreate(
                [
                    'department_id' => $deptId,
                    'role_key'      => $member['role_key'],
                ],
                array_merge($member, ['department_id' => $deptId]),
            );

            $count++;
        }

        $this->command->info("✓ {$count} team profiles seeded.");
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function buildDeptMap(): array
    {
        return Department::query()->pluck('id', 'slug')->toArray();
    }

    private function members(): array
    {
        return [
            // 1. الإدارة العليا
            ['dept_slug' => 'executive-management', 'name_ar' => 'طارق جعبل طعيمان',   'position_ar' => 'المدير العام CEO',                     'role_key' => 'ceo',                           'is_executive' => true,  'is_leader' => false, 'sort_order' => 1],
            ['dept_slug' => 'executive-management', 'name_ar' => 'مازن الجعدي',          'position_ar' => 'نائب المدير العام',                    'role_key' => 'deputy_ceo',                    'is_executive' => true,  'is_leader' => false, 'sort_order' => 2],
            ['dept_slug' => 'executive-management', 'name_ar' => 'رابية الراوي',          'position_ar' => 'المديرة التنفيذية COO',                 'role_key' => 'coo',                           'is_executive' => true,  'is_leader' => false, 'sort_order' => 3],
            ['dept_slug' => 'executive-management', 'name_ar' => 'أروى أحمد',             'position_ar' => 'مديرة الاستراتيجية والمالية CSO & CFO', 'role_key' => 'cso_cfo',                       'is_executive' => true,  'is_leader' => false, 'sort_order' => 4],

            // 2. إدارة البرامج والمسارات
            ['dept_slug' => 'programs-tracks',      'name_ar' => 'كفاح',                  'position_ar' => 'مديرة تطوير البرامج',                  'role_key' => 'department_director',           'is_executive' => false, 'is_leader' => true,  'sort_order' => 1],
            ['dept_slug' => 'programs-tracks',      'name_ar' => 'فؤاد الطشي',             'position_ar' => 'مدير القسم الأكاديمي',                 'role_key' => 'academic_manager',              'is_executive' => false, 'is_leader' => false, 'sort_order' => 2],
            ['dept_slug' => 'programs-tracks',      'name_ar' => 'سفيان الأموي',           'position_ar' => 'مدير مسار الذكاء الاصطناعي',           'role_key' => 'ai_pathway_manager',            'is_executive' => false, 'is_leader' => false, 'sort_order' => 3],
            ['dept_slug' => 'programs-tracks',      'name_ar' => 'سالي',                   'position_ar' => 'رئيسة معهد اللغات',                    'role_key' => 'language_institute_lead',       'is_executive' => false, 'is_leader' => false, 'sort_order' => 4],
            ['dept_slug' => 'programs-tracks',      'name_ar' => 'محمد خالد',              'position_ar' => 'رئيس برنامج رواد',                     'role_key' => 'rowad_program_lead',            'is_executive' => false, 'is_leader' => false, 'sort_order' => 5],

            // 3. إدارة التشغيل والعمليات
            ['dept_slug' => 'operations',           'name_ar' => 'مروى الخلقي',           'position_ar' => 'مديرة التشغيل',                        'role_key' => 'operations_manager',            'is_executive' => false, 'is_leader' => true,  'sort_order' => 1],

            // 4. إدارة التسويق والإعلام
            ['dept_slug' => 'marketing-media',      'name_ar' => 'أمان زيد',              'position_ar' => 'مديرة التسويق والإعلام',               'role_key' => 'marketing_communications_director', 'is_executive' => false, 'is_leader' => true, 'sort_order' => 1],
            ['dept_slug' => 'marketing-media',      'name_ar' => 'اسمهان',                'position_ar' => 'مديرة قسم التسويق',                    'role_key' => 'marketing_manager',             'is_executive' => false, 'is_leader' => false, 'sort_order' => 2],
            ['dept_slug' => 'marketing-media',      'name_ar' => 'نجلاء الجهمي',          'position_ar' => 'مديرة الإعلام البصري',                 'role_key' => 'visual_media_manager',          'is_executive' => false, 'is_leader' => false, 'sort_order' => 3],
            ['dept_slug' => 'marketing-media',      'name_ar' => 'حسن فجر',               'position_ar' => 'إعلامي المركز',                        'role_key' => 'media_officer',                 'is_executive' => false, 'is_leader' => false, 'sort_order' => 4],

            // 5. إدارة الشراكات والعلاقات العامة
            ['dept_slug' => 'partnerships',         'name_ar' => 'نور',                   'position_ar' => 'رئيسة العلاقات العامة - هولندا',        'role_key' => 'pr_lead_netherlands',           'is_executive' => false, 'is_leader' => true,  'sort_order' => 1],
            ['dept_slug' => 'partnerships',         'name_ar' => 'عماد الشباطي',           'position_ar' => 'العلاقات الدولية - تركيا / العراق / الخليج', 'role_key' => 'international_relations', 'is_executive' => false, 'is_leader' => false, 'sort_order' => 2],
            ['dept_slug' => 'partnerships',         'name_ar' => 'أروى أحمد',             'position_ar' => 'العلاقات العامة - اليمن',               'role_key' => 'pr_yemen',                      'is_executive' => false, 'is_leader' => false, 'sort_order' => 3],

            // 6. الإدارة المالية
            ['dept_slug' => 'finance',              'name_ar' => 'أروى أحمد',             'position_ar' => 'مديرة المالية',                        'role_key' => 'finance_director',              'is_executive' => false, 'is_leader' => true,  'sort_order' => 1],
            ['dept_slug' => 'finance',              'name_ar' => 'عبدالقادر باعلوي',       'position_ar' => 'عضو الإدارة المالية',                  'role_key' => 'finance_member',                'is_executive' => false, 'is_leader' => false, 'sort_order' => 2],

            // 7. إدارة التقنية والدعم الفني
            ['dept_slug' => 'technology',           'name_ar' => 'مختار',                 'position_ar' => 'مدير إدارة التقنية',                   'role_key' => 'technology_director',           'is_executive' => false, 'is_leader' => true,  'sort_order' => 1],
            ['dept_slug' => 'technology',           'name_ar' => 'عباس باعلوي',           'position_ar' => 'رئيس قسم التقنية',                     'role_key' => 'technology_section_lead',       'is_executive' => false, 'is_leader' => false, 'sort_order' => 2],
            ['dept_slug' => 'technology',           'name_ar' => 'بشار الخولاني',          'position_ar' => 'الدعم الفني',                          'role_key' => 'technical_support',             'is_executive' => false, 'is_leader' => false, 'sort_order' => 3],

            // 8. إدارة الموارد البشرية
            ['dept_slug' => 'human-resources',      'name_ar' => 'أمل ياسين',             'position_ar' => 'مديرة الموارد البشرية',                'role_key' => 'hr_director',                   'is_executive' => false, 'is_leader' => true,  'sort_order' => 1],

            // 9. إدارة المجتمع والصحة النفسية والوعي
            ['dept_slug' => 'community-health',     'name_ar' => 'د. أماني المتوكل',      'position_ar' => 'مديرة القسم',                          'role_key' => 'wellbeing_director',            'is_executive' => false, 'is_leader' => true,  'sort_order' => 1],
            ['dept_slug' => 'community-health',     'name_ar' => 'د. رانيا توفيق',        'position_ar' => 'مختصة الوعي والتغذية',                 'role_key' => 'awareness_nutrition_specialist', 'is_executive' => false, 'is_leader' => false, 'sort_order' => 2],

            // 10. إدارة الجودة والحوكمة
            ['dept_slug' => 'quality-governance',   'name_ar' => 'رابية الراوي',          'position_ar' => 'مديرة الجودة والحوكمة',               'role_key' => 'governance_quality_director',   'is_executive' => false, 'is_leader' => true,  'sort_order' => 1],
        ];
    }
}
