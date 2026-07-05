<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Department;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Generates 35+ realistic Arabic courses for local/dev environments.
 *
 * SAFE: Never truncates. Only inserts missing slugs. Aborts in production.
 *
 * Usage:
 *   php artisan db:seed --class=CourseDemoSeeder
 *
 * Debug summary:
 *   php artisan tinker --execute="
 *     \$c = \App\Models\Course::count();
 *     \$pub = \App\Models\Course::where('status','published')->count();
 *     \$draft = \App\Models\Course::where('status','draft')->count();
 *     echo \"Total: \$c | Published: \$pub | Draft: \$draft\n\";
 *   "
 */
class CourseDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('CourseDemoSeeder is not allowed in production.');
            return;
        }

        $this->command->info('🌱 CourseDemoSeeder: seeding 35+ Arabic courses…');

        $firstInstructorId = Instructor::value('id');
        $firstDeptId       = Department::where('is_active', true)->value('id');

        $courses = $this->courseCatalog();

        $created = 0;
        foreach ($courses as $data) {
            $slug = $data['slug'] ?? Str::slug($data['title'], '-');
            if (Course::where('slug', $slug)->exists()) {
                continue;
            }
            Course::create([
                'title'          => $data['title'],
                'slug'           => $slug,
                'description'    => $data['description'] ?? "هذه الدورة تقدم محتوى تدريبياً متكاملاً في مجال {$data['title']}.",
                'type'           => $data['type'] ?? 'free',
                'price'          => $data['price'] ?? 0,
                'is_online'      => $data['is_online'] ?? true,
                'status'         => $data['status'] ?? 'published',
                'start_date'     => $data['start_date'] ?? now()->addDays(rand(5, 60))->toDateString(),
                'end_date'       => $data['end_date'] ?? now()->addDays(rand(61, 120))->toDateString(),
                'training_hours' => $data['training_hours'] ?? rand(6, 40),
                'duration'       => $data['duration'] ?? rand(2, 12) . ' أسابيع',
                'level'          => $data['level'] ?? collect(['مبتدئ', 'متوسط', 'متقدم'])->random(),
                'language'       => $data['language'] ?? 'العربية',
                'capacity'       => $data['capacity'] ?? rand(20, 80),
                'instructor_id'  => $data['instructor_id'] ?? $firstInstructorId,
                'department_id'  => $data['department_id'] ?? $firstDeptId,
                'location_type'  => $data['location_type'] ?? 'online',
            ]);
            $created++;
        }

        $total     = Course::count();
        $published = Course::where('status', 'published')->count();
        $draft     = Course::where('status', 'draft')->count();

        $this->command->info("✓ Created {$created} new courses.");
        $this->command->table(
            ['المؤشر', 'العدد'],
            [
                ['إجمالي الدورات',       $total],
                ['منشورة',               $published],
                ['مسودة',                $draft],
                ['تم إنشاؤها الآن',     $created],
            ]
        );
    }

    // ── Catalogue ────────────────────────────────────────────────────────────

    private function courseCatalog(): array
    {
        return [
            // ── تطوير الذات والقيادة ──────────────────────────────────────
            ['title' => 'مهارات القيادة الفعّالة',          'slug' => 'leadership-skills',          'type' => 'paid',  'price' => 299, 'status' => 'published', 'training_hours' => 16, 'level' => 'متوسط'],
            ['title' => 'إدارة الوقت وتنظيم الأولويات',     'slug' => 'time-management',            'type' => 'free',  'price' => 0,   'status' => 'published', 'training_hours' => 8,  'level' => 'مبتدئ'],
            ['title' => 'التفكير النقدي وحل المشكلات',       'slug' => 'critical-thinking',         'type' => 'paid',  'price' => 199, 'status' => 'published', 'training_hours' => 12, 'level' => 'متوسط'],
            ['title' => 'فن التفاوض والإقناع',               'slug' => 'negotiation-skills',        'type' => 'paid',  'price' => 249, 'status' => 'published', 'training_hours' => 10, 'level' => 'متقدم'],
            ['title' => 'بناء الثقة بالنفس والتحدث أمام الجمهور', 'slug' => 'public-speaking',    'type' => 'free',  'price' => 0,   'status' => 'published', 'training_hours' => 8,  'level' => 'مبتدئ'],

            // ── ريادة الأعمال ─────────────────────────────────────────────
            ['title' => 'ريادة الأعمال وبناء المشاريع الناشئة', 'slug' => 'entrepreneurship-101', 'type' => 'paid',  'price' => 399, 'status' => 'published', 'training_hours' => 24, 'level' => 'مبتدئ'],
            ['title' => 'التخطيط الاستراتيجي للمؤسسات',     'slug' => 'strategic-planning',        'type' => 'paid',  'price' => 349, 'status' => 'published', 'training_hours' => 20, 'level' => 'متقدم'],
            ['title' => 'التسويق الرقمي للمشاريع الصغيرة',   'slug' => 'digital-marketing-smb',    'type' => 'paid',  'price' => 279, 'status' => 'draft',    'training_hours' => 16, 'level' => 'مبتدئ'],
            ['title' => 'إدارة المشاريع بمنهجية Agile',      'slug' => 'agile-project-management', 'type' => 'paid',  'price' => 449, 'status' => 'published', 'training_hours' => 30, 'level' => 'متوسط'],
            ['title' => 'نماذج الأعمال وتصميم القيمة',        'slug' => 'business-model-canvas',    'type' => 'free',  'price' => 0,   'status' => 'published', 'training_hours' => 12, 'level' => 'مبتدئ'],

            // ── التقنية والبرمجة ──────────────────────────────────────────
            ['title' => 'أساسيات البرمجة بلغة Python',       'slug' => 'python-basics',            'type' => 'free',  'price' => 0,   'status' => 'published', 'training_hours' => 20, 'level' => 'مبتدئ'],
            ['title' => 'تطوير تطبيقات الويب بـ JavaScript', 'slug' => 'javascript-web-dev',       'type' => 'paid',  'price' => 499, 'status' => 'published', 'training_hours' => 40, 'level' => 'متوسط'],
            ['title' => 'الذكاء الاصطناعي وتعلم الآلة',      'slug' => 'ai-machine-learning',      'type' => 'paid',  'price' => 599, 'status' => 'published', 'training_hours' => 36, 'level' => 'متقدم'],
            ['title' => 'أمن المعلومات والحماية الرقمية',     'slug' => 'cybersecurity-fundamentals','type' => 'paid',  'price' => 449, 'status' => 'published', 'training_hours' => 28, 'level' => 'متوسط'],
            ['title' => 'تصميم قواعد البيانات',               'slug' => 'database-design',          'type' => 'paid',  'price' => 299, 'status' => 'draft',    'training_hours' => 20, 'level' => 'مبتدئ'],
            ['title' => 'إدارة المشاريع التقنية',              'slug' => 'technical-pm',             'type' => 'paid',  'price' => 349, 'status' => 'published', 'training_hours' => 18, 'level' => 'متوسط'],

            // ── المال والأعمال ────────────────────────────────────────────
            ['title' => 'المحاسبة المالية للمبتدئين',         'slug' => 'financial-accounting-basics', 'type' => 'free', 'price' => 0,  'status' => 'published', 'training_hours' => 14, 'level' => 'مبتدئ'],
            ['title' => 'إدارة الموارد البشرية الحديثة',       'slug' => 'hr-management-modern',     'type' => 'paid',  'price' => 329, 'status' => 'published', 'training_hours' => 22, 'level' => 'متوسط'],
            ['title' => 'العقود والمشتريات والتفاوض',          'slug' => 'procurement-contracts',    'type' => 'paid',  'price' => 299, 'status' => 'published', 'training_hours' => 16, 'level' => 'متوسط'],
            ['title' => 'التمويل وإدارة السيولة',               'slug' => 'finance-liquidity',        'type' => 'paid',  'price' => 399, 'status' => 'draft',    'training_hours' => 20, 'level' => 'متقدم'],

            // ── التطوير الاجتماعي والمجتمعي ───────────────────────────────
            ['title' => 'العمل التطوعي وبناء المجتمعات',      'slug' => 'volunteering-community',   'type' => 'free',  'price' => 0,   'status' => 'published', 'training_hours' => 6,  'level' => 'مبتدئ'],
            ['title' => 'إدارة المبادرات والمشاريع المجتمعية', 'slug' => 'community-projects',      'type' => 'free',  'price' => 0,   'status' => 'published', 'training_hours' => 10, 'level' => 'مبتدئ'],
            ['title' => 'المسؤولية الاجتماعية للشركات',        'slug' => 'csr-corporate',            'type' => 'paid',  'price' => 249, 'status' => 'published', 'training_hours' => 12, 'level' => 'متوسط'],

            // ── التصميم والإبداع ──────────────────────────────────────────
            ['title' => 'تصميم الجرافيك للمبتدئين',            'slug' => 'graphic-design-basics',   'type' => 'free',  'price' => 0,   'status' => 'published', 'training_hours' => 12, 'level' => 'مبتدئ'],
            ['title' => 'تجربة المستخدم وتصميم الواجهات UI/UX','slug' => 'ui-ux-design',            'type' => 'paid',  'price' => 479, 'status' => 'published', 'training_hours' => 30, 'level' => 'متوسط'],
            ['title' => 'الكتابة الإبداعية والمحتوى الرقمي',   'slug' => 'creative-writing-digital','type' => 'paid',  'price' => 199, 'status' => 'draft',    'training_hours' => 10, 'level' => 'مبتدئ'],
            ['title' => 'إنتاج الفيديو والمحتوى المرئي',        'slug' => 'video-production',        'type' => 'paid',  'price' => 399, 'status' => 'published', 'training_hours' => 20, 'level' => 'متوسط'],

            // ── اللغات ────────────────────────────────────────────────────
            ['title' => 'اللغة الإنجليزية للأعمال',           'slug' => 'business-english',         'type' => 'paid',  'price' => 279, 'status' => 'published', 'training_hours' => 24, 'level' => 'متوسط'],
            ['title' => 'مهارات الكتابة الأكاديمية بالعربية', 'slug' => 'arabic-academic-writing',  'type' => 'free',  'price' => 0,   'status' => 'published', 'training_hours' => 8,  'level' => 'مبتدئ'],
            ['title' => 'اللغة الفرنسية للمبتدئين',            'slug' => 'french-basics',            'type' => 'paid',  'price' => 229, 'status' => 'draft',    'training_hours' => 20, 'level' => 'مبتدئ'],

            // ── الصحة والسلامة ────────────────────────────────────────────
            ['title' => 'الصحة النفسية في بيئة العمل',         'slug' => 'mental-health-workplace',  'type' => 'free',  'price' => 0,   'status' => 'published', 'training_hours' => 6,  'level' => 'مبتدئ'],
            ['title' => 'السلامة المهنية وإدارة المخاطر',       'slug' => 'occupational-safety',     'type' => 'paid',  'price' => 249, 'status' => 'published', 'training_hours' => 14, 'level' => 'متوسط'],

            // ── تقنيات ناشئة ──────────────────────────────────────────────
            ['title' => 'مقدمة في تقنية البلوكتشين',           'slug' => 'blockchain-intro',         'type' => 'paid',  'price' => 349, 'status' => 'published', 'training_hours' => 12, 'level' => 'متوسط'],
            ['title' => 'الحوسبة السحابية وخدمات AWS',         'slug' => 'cloud-aws-intro',          'type' => 'paid',  'price' => 449, 'status' => 'published', 'training_hours' => 24, 'level' => 'متوسط'],
            ['title' => 'إنترنت الأشياء والأتمتة الذكية',       'slug' => 'iot-smart-automation',    'type' => 'paid',  'price' => 399, 'status' => 'draft',    'training_hours' => 18, 'level' => 'متقدم'],
            ['title' => 'تحليل البيانات باستخدام Excel',        'slug' => 'data-analysis-excel',     'type' => 'free',  'price' => 0,   'status' => 'published', 'training_hours' => 10, 'level' => 'مبتدئ'],
            ['title' => 'إدارة وسائل التواصل الاجتماعي للمؤسسات','slug' => 'social-media-management','type' => 'paid', 'price' => 299, 'status' => 'published', 'training_hours' => 14, 'level' => 'مبتدئ'],
        ];
    }
}
