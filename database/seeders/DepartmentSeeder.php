<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Seeds the official EMC V3 department structure.
 * Uses updateOrCreate on `slug` so it is safe to re-run.
 */
class DepartmentSeeder extends Seeder
{
    private const DEPARTMENTS = [
        [
            'slug'        => 'executive-management',
            'name'        => 'الإدارة العليا',
            'name_ar'     => 'الإدارة العليا',
            'name_en'     => 'Executive Management',
            'description_ar' => 'القيادة العليا للمركز وصنع القرار الاستراتيجي.',
            'icon'        => 'crown',
            'sort_order'  => 1,
            'is_active'   => true,
        ],
        [
            'slug'        => 'programs-tracks',
            'name'        => 'إدارة البرامج والمسارات',
            'name_ar'     => 'إدارة البرامج والمسارات',
            'name_en'     => 'Programs & Tracks',
            'description_ar' => 'تطوير وإدارة البرامج الأكاديمية والمسارات التدريبية.',
            'icon'        => 'academic-cap',
            'sort_order'  => 2,
            'is_active'   => true,
        ],
        [
            'slug'        => 'operations',
            'name'        => 'إدارة التشغيل والعمليات',
            'name_ar'     => 'إدارة التشغيل والعمليات',
            'name_en'     => 'Operations',
            'description_ar' => 'إدارة العمليات اليومية وضمان سير العمل بكفاءة.',
            'icon'        => 'cog',
            'sort_order'  => 3,
            'is_active'   => true,
        ],
        [
            'slug'        => 'marketing-media',
            'name'        => 'إدارة التسويق والإعلام',
            'name_ar'     => 'إدارة التسويق والإعلام',
            'name_en'     => 'Marketing & Media',
            'description_ar' => 'التسويق والتواصل الإعلامي وبناء الهوية البصرية.',
            'icon'        => 'megaphone',
            'sort_order'  => 4,
            'is_active'   => true,
        ],
        [
            'slug'        => 'partnerships',
            'name'        => 'إدارة الشراكات والعلاقات العامة',
            'name_ar'     => 'إدارة الشراكات والعلاقات العامة',
            'name_en'     => 'Partnerships & Public Relations',
            'description_ar' => 'بناء الشراكات الاستراتيجية وإدارة العلاقات العامة.',
            'icon'        => 'handshake',
            'sort_order'  => 5,
            'is_active'   => true,
        ],
        [
            'slug'        => 'finance',
            'name'        => 'الإدارة المالية',
            'name_ar'     => 'الإدارة المالية',
            'name_en'     => 'Finance',
            'description_ar' => 'الإدارة المالية والمحاسبة وضمان الاستدامة المالية.',
            'icon'        => 'currency-dollar',
            'sort_order'  => 6,
            'is_active'   => true,
        ],
        [
            'slug'        => 'technology',
            'name'        => 'إدارة التقنية والدعم الفني',
            'name_ar'     => 'إدارة التقنية والدعم الفني',
            'name_en'     => 'Technology & Technical Support',
            'description_ar' => 'البنية التقنية وتطوير الأنظمة والدعم الفني.',
            'icon'        => 'chip',
            'sort_order'  => 7,
            'is_active'   => true,
        ],
        [
            'slug'        => 'human-resources',
            'name'        => 'إدارة الموارد البشرية',
            'name_ar'     => 'إدارة الموارد البشرية',
            'name_en'     => 'Human Resources',
            'description_ar' => 'إدارة الكوادر البشرية والمتطوعين وتطوير الفريق.',
            'icon'        => 'users',
            'sort_order'  => 8,
            'is_active'   => true,
        ],
        [
            'slug'        => 'community-health',
            'name'        => 'إدارة المجتمع والصحة النفسية والوعي',
            'name_ar'     => 'إدارة المجتمع والصحة النفسية والوعي',
            'name_en'     => 'Community, Wellbeing & Awareness',
            'description_ar' => 'برامج الصحة النفسية والوعي المجتمعي.',
            'icon'        => 'heart',
            'sort_order'  => 9,
            'is_active'   => true,
        ],
        [
            'slug'        => 'quality-governance',
            'name'        => 'إدارة الجودة والحوكمة',
            'name_ar'     => 'إدارة الجودة والحوكمة',
            'name_en'     => 'Quality & Governance',
            'description_ar' => 'ضمان الجودة والحوكمة المؤسسية والامتثال.',
            'icon'        => 'shield-check',
            'sort_order'  => 10,
            'is_active'   => true,
        ],
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $data) {
            Department::updateOrCreate(
                ['slug' => $data['slug']],
                $data,
            );
        }

        $this->command->info('✓ ' . count(self::DEPARTMENTS) . ' departments seeded.');
    }
}
