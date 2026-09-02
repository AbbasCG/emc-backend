<?php

namespace Database\Seeders;

use App\Models\Track;
use Illuminate\Database\Seeder;

class TrackSeeder extends Seeder
{
    /**
     * دليل المسارات الرسمي EMC — المصدر المعتمد الوحيد للمسارات الرسمية.
     */
    public function run(): void
    {
        $tracks = [
            [
                'title'         => 'مسار مهندس الذكاء الاصطناعي',
                'slug'          => 'ai-engineer',
                'description'   => 'مدخل الذكاء الاصطناعي، Python، أساسيات البيانات، التعلم الآلي والعميق، وتطوير بيئات المشاريع الحقيقية.',
                'outcome_title' => 'Certified AI Engineer - EMC',
                'duration'      => '6 - 8 أشهر',
                'level'         => 'advanced',
                'status'        => 'published',
                'price'         => 899.00,
                'is_free'       => false,
            ],
            [
                'title'         => 'مسار عالم البيانات',
                'slug'          => 'data-scientist',
                'description'   => 'تحليل البيانات، الإحصاء، التصوير البياني، بناء النماذج، واستخراج الرؤى العملية لدعم القرار.',
                'outcome_title' => 'Certified Data Scientist - EMC',
                'duration'      => '6 - 8 أشهر',
                'level'         => 'advanced',
                'status'        => 'published',
                'price'         => 849.00,
                'is_free'       => false,
            ],
            [
                'title'         => 'مسار مهندس البيانات',
                'slug'          => 'data-engineer',
                'description'   => 'SQL وقواعد البيانات، خطوط البيانات، عمليات ETL، السحابة، وتجهيز البيانات للتحليل والذكاء الاصطناعي.',
                'outcome_title' => 'Certified Data Engineer - EMC',
                'duration'      => '6 - 8 أشهر',
                'level'         => 'intermediate',
                'status'        => 'published',
                'price'         => 799.00,
                'is_free'       => false,
            ],
            [
                'title'         => 'مسار محلل البيانات',
                'slug'          => 'data-analyst',
                'description'   => 'Excel، SQL، Power BI أو Tableau، إعداد التقارير، لوحات المعلومات، ومؤشرات الأداء.',
                'outcome_title' => 'Certified Data Analyst - EMC',
                'duration'      => '4 - 6 أشهر',
                'level'         => 'intermediate',
                'status'        => 'published',
                'price'         => 549.00,
                'is_free'       => false,
            ],
            [
                'title'         => 'مسار الذكاء الاصطناعي التوليدي',
                'slug'          => 'generative-ai',
                'description'   => 'هندسة الأوامر، أدوات الذكاء الاصطناعي، الإنتاجية، صناعة المحتوى، الأتمتة، ووكلاء الذكاء الاصطناعي.',
                'outcome_title' => 'Certified Generative AI Specialist - EMC',
                'duration'      => '4 - 6 أشهر',
                'level'         => 'all_levels',
                'status'        => 'published',
                'price'         => 599.00,
                'is_free'       => false,
            ],
            [
                'title'         => 'مسار الذكاء الاصطناعي في الأعمال',
                'slug'          => 'ai-for-business',
                'description'   => 'توظيف الذكاء الاصطناعي في التسويق والإدارة والعمليات وخدمة العملاء واتخاذ القرار والتحول الرقمي.',
                'outcome_title' => 'Certified AI Business Specialist - EMC',
                'duration'      => '4 - 6 أشهر',
                'level'         => 'all_levels',
                'status'        => 'published',
                'price'         => 649.00,
                'is_free'       => false,
            ],
            [
                'title'         => 'مسار ريادة الأعمال',
                'slug'          => 'entrepreneurship',
                'description'   => 'تحويل الأفكار إلى مشاريع، نموذج العمل، دراسة السوق، الهوية، التسويق، التمويل، والمنتج الأول.',
                'outcome_title' => 'Certified Entrepreneur - EMC',
                'duration'      => '4 - 6 أشهر',
                'level'         => 'beginner',
                'status'        => 'published',
                'price'         => 499.00,
                'is_free'       => false,
            ],
            [
                'title'         => 'مسار التقدم المهني',
                'slug'          => 'professional-advancement',
                'description'   => 'السيرة الذاتية، LinkedIn، المقابلات والتواصل المهني، البحث عن عمل، وبناء الهوية المهنية.',
                'outcome_title' => 'Professional Advancement Certificate - EMC',
                'duration'      => '8 - 12 أسبوعاً',
                'level'         => 'beginner',
                'status'        => 'published',
                'price'         => 249.00,
                'is_free'       => false,
            ],
            [
                'title'         => 'مسار التقدم الأكاديمي',
                'slug'          => 'academic-advancement',
                'description'   => 'اختيار التخصص، مهارات الدراسة، الكتابة الأكاديمية، البحث العلمي، والتقديم للجامعات والمنح.',
                'outcome_title' => 'Academic Advancement Certificate - EMC',
                'duration'      => '6 - 8 أشهر',
                'level'         => 'beginner',
                'status'        => 'published',
                'price'         => 399.00,
                'is_free'       => false,
            ],
        ];

        // Clean out any old/unapproved tracks first
        Track::query()->forceDelete();

        foreach ($tracks as $trackData) {
            Track::create($trackData);
        }
    }
}
