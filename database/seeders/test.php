<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketAttachment;
use App\Models\User;

/**
 * Demo data for the EMC Tickets system.
 *
 * IMPORTANT: this seeder does NOT create departments. `departments` is
 * owned exclusively by OfficialDepartmentsSeeder (the single source of
 * truth for the 13/14 official EMC departments) — run that seeder first.
 * Tickets/units below are attached to the EXISTING canonical department
 * rows, resolved by their official slug, never created as duplicates
 * under a different slug.
 */
class TicketSeeder extends Seeder
{
    /** [internal code => canonical OfficialDepartmentsSeeder slug] */
    private const DEPARTMENT_SLUGS = [
        'EXEC'         => 'executive-management',
        'PROG'         => 'programs-tracks',
        'PR_PARTNER'   => 'partnerships',
        'FINANCE'      => 'finance',
        'TECH'         => 'technology',
        'COMMUNITY'    => 'community-health',
        'OPS'          => 'operations',
        'QUALITY'      => 'quality-governance',
        'AI_TRANSFORM' => 'ai-transformation',
        'MARKETING'    => 'marketing-media',
        'HR'           => 'human-resources',
        'AMBASSADORS'  => 'digital-ambassadors',
    ];

    public function run(): void
    {
        $deptMap = [];
        $missing = [];

        foreach (self::DEPARTMENT_SLUGS as $code => $slug) {
            $dept = Department::where('slug', $slug)->first();
            if ($dept) {
                $deptMap[$code] = $dept;
            } else {
                $missing[] = "{$code} → {$slug}";
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                "TicketSeeder: the following canonical departments were not found — run OfficialDepartmentsSeeder first. Missing: "
                . implode(', ', $missing)
            );
        }

        // Seed 8 Units under the canonical Technology department
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

        // Demo actor — reuse an existing user, never invent a new one silently.
        $user = User::first();
        if (! $user) {
            throw new \RuntimeException('TicketSeeder: no users exist yet — run DemoUserSeeder/DatabaseSeeder first.');
        }

        // Demo tickets across the lifecycle — same shape as before, just anchored to canonical departments.
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

        TicketAttachment::updateOrCreate(
            ['ticket_id' => $t4->id, 'attachment_context' => 'RESOLUTION_PROOF'],
            [
                'file_url' => 'tickets/demo/proof_certificate_sample.png',
                'file_name' => 'proof_certificate_sample.png',
                'file_type' => 'IMAGE',
                'uploaded_by' => $user->id,
            ]
        );
    }
}
