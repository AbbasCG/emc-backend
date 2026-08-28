<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Support\Str;

class TicketSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed 12 Main Departments
        $departmentsData = [
            ['code' => 'EXEC', 'name_ar' => 'الإدارة العليا', 'name' => 'Executive Management', 'slug' => 'executive-management'],
            ['code' => 'PROG', 'name_ar' => 'إدارة البرامج والمسارات', 'name' => 'Programs & Tracks Department', 'slug' => 'programs-tracks'],
            ['code' => 'PR_PARTNER', 'name_ar' => 'إدارة الشراكات والعلاقات العامة', 'name' => 'Partnerships & PR Department', 'slug' => 'partnerships-pr'],
            ['code' => 'FINANCE', 'name_ar' => 'الإدارة المالية', 'name' => 'Finance Department', 'slug' => 'finance-department'],
            ['code' => 'TECH', 'name_ar' => 'إدارة التقنية والدعم الفني', 'name' => 'Technology & Tech Support Department', 'slug' => 'tech-support'],
            ['code' => 'COMMUNITY', 'name_ar' => 'إدارة المجتمع والصحة النفسية والوعي', 'name' => 'Community & Mental Health Department', 'slug' => 'community-mental-health'],
            ['code' => 'OPS', 'name_ar' => 'إدارة التشغيل والعمليات', 'name' => 'Operations Department', 'slug' => 'operations-department'],
            ['code' => 'QUALITY', 'name_ar' => 'إدارة الجودة والحوكمة', 'name' => 'Quality & Governance Department', 'slug' => 'quality-governance'],
            ['code' => 'AI_TRANSFORM', 'name_ar' => 'إدارة الذكاء الاصطناعي والتحول الرقمي', 'name' => 'AI & Digital Transformation Department', 'slug' => 'ai-digital-transformation'],
            ['code' => 'MARKETING', 'name_ar' => 'إدارة التسويق والإعلام', 'name' => 'Marketing & Media Department', 'slug' => 'marketing-media'],
            ['code' => 'HR', 'name_ar' => 'إدارة الموارد البشرية', 'name' => 'Human Resources Department', 'slug' => 'hr-department'],
            ['code' => 'AMBASSADORS', 'name_ar' => 'إدارة سفراء التحول الرقمي', 'name' => 'Digital Transformation Ambassadors', 'slug' => 'ambassadors-department'],
        ];

        $deptMap = [];

        foreach ($departmentsData as $idx => $d) {
            $dept = Department::updateOrCreate(
                ['slug' => $d['slug']],
                [
                    'name' => $d['name'],
                    'name_ar' => $d['name_ar'],
                    'name_en' => $d['name'],
                    'description_ar' => 'إدارة ' . $d['name_ar'] . ' المعتمدة بالهيكل التنظيمي للمركز',
                    'sort_order' => $idx + 1,
                    'is_active' => true,
                ]
            );
            $deptMap[$d['code']] = $dept;
        }

        // 2. Seed 8 Units under Technology & Tech Support Department
        $techDept = $deptMap['TECH'];

        $unitsData = [
            ['code' => 'AI_AUTO', 'name_ar' => 'وحدة الذكاء الاصطناعي والأتمتة', 'name_en' => 'AI & Automation Unit'],
            ['code' => 'QA_TEST', 'name_ar' => 'وحدة الاختبار والتحقق', 'name_en' => 'QA & Testing Unit'],
            ['code' => 'UI_UX', 'name_ar' => 'وحدة التصميم وتجربة المستخدم', 'name_en' => 'UI/UX Design Unit'],
            ['code' => 'SW_DEV', 'name_ar' => 'وحدة تطوير المنصات والبرمجيات', 'name_en' => 'Software Development Unit'],
            ['code' => 'DEVOPS_PMO', 'name_ar' => 'وحدة العمليات التقنية وإدارة المشاريع', 'name_en' => 'DevOps & Tech PMO Unit'],
            ['code' => 'DATA_SYS', 'name_ar' => 'وحدة إدارة البيانات والأنظمة', 'name_en' => 'Data & Systems Administration Unit'],
            ['code' => 'IT_HELPDESK', 'name_ar' => 'وحدة الدعم الفني والتقني', 'name_en' => 'Technical Support & IT Helpdesk Unit'],
            ['code' => 'CYBER_SEC', 'name_ar' => 'وحدة أمن المعلومات والامتثال', 'name_en' => 'Information & Cyber Security Unit'],
        ];

        $unitMap = [];
        foreach ($unitsData as $u) {
            $unit = DepartmentalUnit::updateOrCreate(
                ['code' => $u['code']],
                [
                    'department_id' => $techDept->id,
                    'name_ar' => $u['name_ar'],
                    'name_en' => $u['name_en'],
                    'description' => $u['name_ar'] . ' - التابعة للوحة تحكم إدارة التقنية',
                    'is_active' => true,
                ]
            );
            $unitMap[$u['code']] = $unit;
        }

        // Fetch or create a test user
        $user = User::first() ?? User::factory()->create([
            'name' => 'أحمد المهندس',
            'email' => 'admin@emc.org',
        ]);

        // 3. Seed Demo Tickets across various lifecycle states

        // Ticket 1: PENDING_APPROVAL
        $t1 = Ticket::updateOrCreate(
            ['ticket_number' => 'EMC-TCK-2026-001'],
            [
                'title' => 'بطء في استجابة شاشة تسجيل الدورات التدريبية',
                'description' => 'عند الدخول إلى صفحة الدفع وتأكيد التسجيل تستغرق العملية أكثر من 15 ثانية وتظهر رسالة مهلة اتخاذ القرار.',
                'ticket_category' => 'OLD_ISSUE',
                'priority' => 'HIGH',
                'department_id' => $techDept->id,
                'created_by_id' => $user->id,
                'created_by_name' => 'سارة الشمري',
                'created_by_email' => 'sara@example.com',
                'status' => 'PENDING_APPROVAL',
            ]
        );

        TicketActivityLog::create([
            'ticket_id' => $t1->id,
            'action' => 'CREATED',
            'previous_state' => null,
            'new_state' => 'PENDING_APPROVAL',
            'performed_by' => $user->id,
            'performed_by_name' => 'سارة الشمري',
            'details' => 'تم تقديم البلاغ بنجاح وتوجيهه لإدارة التقنية والدعم الفني',
            'logged_at' => now()->subHours(3),
        ]);

        // Ticket 2: ASSIGNED (With active SLA)
        $t2 = Ticket::updateOrCreate(
            ['ticket_number' => 'EMC-TCK-2026-002'],
            [
                'title' => 'إضافة خاصية المظهر الداكن (Dark Mode) في لوحة التحكم',
                'description' => 'مقترح تطويري لتوفير النمط الليلي لراحة المستخدمين أثناء العمل المتواصل بالليل.',
                'ticket_category' => 'NEW_SUGGESTION',
                'priority' => 'MEDIUM',
                'department_id' => $techDept->id,
                'unit_id' => $unitMap['UI_UX']->id,
                'created_by_id' => $user->id,
                'created_by_name' => 'خالد العتيبي',
                'approved_by_id' => $user->id,
                'assigned_to_id' => $user->id,
                'status' => 'ASSIGNED',
                'expected_resolution_time' => now()->addHours(48),
            ]
        );

        TicketActivityLog::create([
            'ticket_id' => $t2->id,
            'action' => 'APPROVED_AND_ASSIGNED',
            'previous_state' => 'PENDING_APPROVAL',
            'new_state' => 'ASSIGNED',
            'performed_by' => $user->id,
            'performed_by_name' => 'مدير النظام',
            'details' => 'تم اعتماد الطلب وتسكينه بوحدة UI/UX وتحديد SLA لمدة 48 ساعة',
            'logged_at' => now()->subHours(1),
        ]);

        // Ticket 3: IN_PROGRESS
        $t3 = Ticket::updateOrCreate(
            ['ticket_number' => 'EMC-TCK-2026-003'],
            [
                'title' => 'تعذر تصدير تقارير الجودة بصيغة PDF',
                'description' => 'يظهر خطأ 500 عند محاولة تنزيل تقرير الأداء الشهري من قسم الجودة والحوكمة.',
                'ticket_category' => 'OLD_ISSUE',
                'priority' => 'CRITICAL',
                'department_id' => $techDept->id,
                'unit_id' => $unitMap['SW_DEV']->id,
                'created_by_id' => $user->id,
                'created_by_name' => 'م. فيصل الغامدي',
                'approved_by_id' => $user->id,
                'assigned_to_id' => $user->id,
                'status' => 'IN_PROGRESS',
                'expected_resolution_time' => now()->addHours(6),
                'accepted_at' => now()->subMinutes(45),
            ]
        );

        // Ticket 4: RESOLVED
        $t4 = Ticket::updateOrCreate(
            ['ticket_number' => 'EMC-TCK-2026-004'],
            [
                'title' => 'تعديل شعار مركز EMC في ترويسة الشهادات الرقمية',
                'description' => 'المطلوب تحديث بدقة الشعار في الشهادات المطبوعة.',
                'ticket_category' => 'NEW_SUGGESTION',
                'priority' => 'LOW',
                'department_id' => $techDept->id,
                'unit_id' => $unitMap['UI_UX']->id,
                'created_by_id' => $user->id,
                'created_by_name' => 'نورة القحطاني',
                'approved_by_id' => $user->id,
                'assigned_to_id' => $user->id,
                'status' => 'RESOLVED',
                'expected_resolution_time' => now()->subHours(2),
                'accepted_at' => now()->subHours(12),
                'resolved_at' => now()->subHours(1),
                'resolution_summary' => 'تم استبدال ملف الشعار في قوالب الشهادات بشعار SVG عالي الدقة وتجربة الطباعة بنجاح.',
            ]
        );

        TicketAttachment::create([
            'ticket_id' => $t4->id,
            'file_url' => '/assets/demo_proof.png',
            'file_name' => 'proof_certificate_sample.png',
            'file_type' => 'IMAGE',
            'attachment_context' => 'RESOLUTION_PROOF',
            'uploaded_by' => $user->id,
        ]);
    }
}
