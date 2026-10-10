<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accountsData = [
            // ── 1. الأصول (Assets - debit) ──────────────────────────────────
            ['code' => '1',    'name_ar' => 'الأصول',                          'name_en' => 'Assets',                   'type' => 'debit',  'parent_code' => null, 'is_selectable' => false],
            ['code' => '11',   'name_ar' => 'الأصول المتداولة',                 'name_en' => 'Current Assets',           'type' => 'debit',  'parent_code' => '1',  'is_selectable' => false],
            ['code' => '1101', 'name_ar' => 'البنوك (حسابات بنكية رسمية)',       'name_en' => 'Banks',                    'type' => 'debit',  'parent_code' => '11', 'is_selectable' => true],
            ['code' => '1102', 'name_ar' => 'المحافظ الإلكترونية والنقدية',       'name_en' => 'Wallets & Cash',           'type' => 'debit',  'parent_code' => '11', 'is_selectable' => true],
            ['code' => '12',   'name_ar' => 'الأصول الثابتة',                   'name_en' => 'Fixed Assets',             'type' => 'debit',  'parent_code' => '1',  'is_selectable' => false],
            ['code' => '1201', 'name_ar' => 'أجهزة ومعدات تقنية',               'name_en' => 'IT Equipment',             'type' => 'debit',  'parent_code' => '12', 'is_selectable' => true],
            ['code' => '1202', 'name_ar' => 'أثاث وتجهيزات مقرات',               'name_en' => 'Furniture',                'type' => 'debit',  'parent_code' => '12', 'is_selectable' => true],
            ['code' => '13',   'name_ar' => 'الأصول غير الملموسة',              'name_en' => 'Intangible Assets',       'type' => 'debit',  'parent_code' => '1',  'is_selectable' => false],
            ['code' => '1301', 'name_ar' => 'مسارات وتطوير الدورات',            'name_en' => 'Courses Development',      'type' => 'debit',  'parent_code' => '13', 'is_selectable' => true],
            ['code' => '1302', 'name_ar' => 'البرمجيات والمنصات الملكية',         'name_en' => 'Software & Platforms',     'type' => 'debit',  'parent_code' => '13', 'is_selectable' => true],

            // ── 2. الالتزامات وحقوق الملكية (Liabilities & Equity - credit) ──
            ['code' => '2',    'name_ar' => 'الالتزامات وحقوق الملكية',          'name_en' => 'Liabilities & Equity',     'type' => 'credit', 'parent_code' => null, 'is_selectable' => false],
            ['code' => '21',   'name_ar' => 'الالتزامات المتداولة',             'name_en' => 'Current Liabilities',      'type' => 'credit', 'parent_code' => '2',  'is_selectable' => false],
            ['code' => '2101', 'name_ar' => 'الدائنون (الموردون)',               'name_en' => 'Payables',                 'type' => 'credit', 'parent_code' => '21', 'is_selectable' => true],
            ['code' => '2102', 'name_ar' => 'مستحقات المدربين والخبراء',         'name_en' => 'Trainers Payables',        'type' => 'credit', 'parent_code' => '21', 'is_selectable' => true],
            ['code' => '22',   'name_ar' => 'حقوق الملكية والاحتياطيات',          'name_en' => 'Equity & Reserves',        'type' => 'credit', 'parent_code' => '2',  'is_selectable' => false],
            ['code' => '2201', 'name_ar' => 'رأس المال',                        'name_en' => 'Capital',                  'type' => 'credit', 'parent_code' => '22', 'is_selectable' => true],
            ['code' => '2202', 'name_ar' => 'الاحتياطي العام',                   'name_en' => 'General Reserve',          'type' => 'credit', 'parent_code' => '22', 'is_selectable' => true],
            ['code' => '2203', 'name_ar' => 'الأرباح / الفائض المدور',          'name_en' => 'Retained Earnings',        'type' => 'credit', 'parent_code' => '22', 'is_selectable' => true],

            // ── 3. المصروفات (Expenses - debit) ──────────────────────────────
            ['code' => '3',    'name_ar' => 'المصروفات',                         'name_en' => 'Expenses',                 'type' => 'debit',  'parent_code' => null, 'is_selectable' => false],
            ['code' => '31',   'name_ar' => 'مرتبات وأجور',                     'name_en' => 'Salaries & Wages',         'type' => 'debit',  'parent_code' => '3',  'is_selectable' => false],
            ['code' => '3101', 'name_ar' => 'رواتب الكادر الإداري والتقني',       'name_en' => 'Staff Salaries',           'type' => 'debit',  'parent_code' => '31', 'is_selectable' => true],
            ['code' => '3102', 'name_ar' => 'مكافآت ومستحقات المتطوعين',         'name_en' => 'Volunteers Rewards',       'type' => 'debit',  'parent_code' => '31', 'is_selectable' => true],
            ['code' => '32',   'name_ar' => 'مصاريف التقنية والاشتراكات',         'name_en' => 'Tech & Subscriptions',     'type' => 'debit',  'parent_code' => '3',  'is_selectable' => false],
            ['code' => '3201', 'name_ar' => 'اشتراكات سحابية وسيرفرات',          'name_en' => 'Cloud & Servers',          'type' => 'debit',  'parent_code' => '32', 'is_selectable' => true],
            ['code' => '3202', 'name_ar' => 'اشتراكات أدوات الذكاء الاصطناعي',     'name_en' => 'AI Tools Subscriptions',   'type' => 'debit',  'parent_code' => '32', 'is_selectable' => true],
            ['code' => '33',   'name_ar' => 'مصاريف تشغيلية',                    'name_en' => 'Operational Expenses',     'type' => 'debit',  'parent_code' => '3',  'is_selectable' => false],
            ['code' => '3301', 'name_ar' => 'بدل مواصلات واتصالات',             'name_en' => 'Transportation & Telecom', 'type' => 'debit',  'parent_code' => '33', 'is_selectable' => true],
            ['code' => '3302', 'name_ar' => 'اشتراكات الإنترنت والخدمات',         'name_en' => 'Internet & Services',      'type' => 'debit',  'parent_code' => '33', 'is_selectable' => true],
            ['code' => '3303', 'name_ar' => 'مصاريف ضيافة وفعاليات',             'name_en' => 'Hospitality & Events',     'type' => 'debit',  'parent_code' => '33', 'is_selectable' => true],

            // ── 4. الإيرادات (Revenues - credit) ──────────────────────────────
            ['code' => '4',    'name_ar' => 'الإيرادات',                         'name_en' => 'Revenues',                 'type' => 'credit', 'parent_code' => null, 'is_selectable' => false],
            ['code' => '41',   'name_ar' => 'إيرادات النشاط التعليمي',           'name_en' => 'Educational Revenues',     'type' => 'credit', 'parent_code' => '4',  'is_selectable' => false],
            ['code' => '4101', 'name_ar' => 'إيرادات الدورات التدريبية',        'name_en' => 'Courses Revenue',          'type' => 'credit', 'parent_code' => '41', 'is_selectable' => true],
            ['code' => '4102', 'name_ar' => 'إيرادات الورش والمعسكرات',           'name_en' => 'Workshops Revenue',        'type' => 'credit', 'parent_code' => '41', 'is_selectable' => true],
            ['code' => '42',   'name_ar' => 'التمويل والرعاية',                  'name_en' => 'Funding & Sponsorships',   'type' => 'credit', 'parent_code' => '4',  'is_selectable' => false],
            ['code' => '4201', 'name_ar' => 'إيرادات الداعمين',                 'name_en' => 'Donors Revenue',           'type' => 'credit', 'parent_code' => '42', 'is_selectable' => true],
            ['code' => '4202', 'name_ar' => 'إيرادات الرعاة',                   'name_en' => 'Sponsorships Revenue',     'type' => 'credit', 'parent_code' => '42', 'is_selectable' => true],
            ['code' => '43',   'name_ar' => 'إيرادات أخرى',                     'name_en' => 'Other Revenues',           'type' => 'credit', 'parent_code' => '4',  'is_selectable' => false],
            ['code' => '4301', 'name_ar' => 'مبيعات الكتب والمواد التعليمية',    'name_en' => 'Books & Materials Sales',  'type' => 'credit', 'parent_code' => '43', 'is_selectable' => true],
        ];

        // Seed sequentially so parents exist before children
        foreach ($accountsData as $acc) {
            $parentId = null;
            if ($acc['parent_code']) {
                $parentAcc = Account::where('code', $acc['parent_code'])->first();
                $parentId = $parentAcc?->id;
            }

            Account::updateOrCreate(
                ['code' => $acc['code']],
                [
                    'name_ar'       => $acc['name_ar'],
                    'name_en'       => $acc['name_en'],
                    'type'          => $acc['type'],
                    'parent_id'     => $parentId,
                    'is_selectable' => $acc['is_selectable'],
                ]
            );
        }
    }
}
