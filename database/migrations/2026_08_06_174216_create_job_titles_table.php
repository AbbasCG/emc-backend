<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical department -> job-title mapping, replacing a hardcoded
 * frontend-only list. The Volunteer HR profile form and its backend
 * validation both read from this table so a submitted job title can be
 * verified to actually belong to the selected department.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name_ar');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['department_id', 'name_ar']);
            $table->index(['department_id', 'is_active']);
        });

        $mapping = [
            'الإدارة العليا' => ['مدير تنفيذي', 'مساعد إداري', 'منسق إداري', 'مستشار'],
            'البرامج والمسارات' => ['مدير برامج', 'منسق برامج', 'مسؤول مسارات تعليمية', 'مسؤول دورات', 'مشرف محتوى تعليمي'],
            'التشغيل والعمليات' => ['مدير عمليات', 'منسق عمليات', 'مسؤول متابعة', 'مسؤول تنظيم فعاليات'],
            'التسويق والإعلام' => ['مدير تسويق', 'مسؤول تسويق رقمي', 'مصمم جرافيك', 'صانع محتوى', 'كاتب محتوى', 'مسؤول وسائل التواصل', 'مصور', 'محرر فيديو'],
            'الشراكات والعلاقات' => ['مدير شراكات', 'منسق شراكات', 'مسؤول علاقات عامة', 'مسؤول تواصل مؤسسي'],
            'المالية' => ['مدير مالي', 'محاسب', 'مساعد محاسب', 'مسؤول مدفوعات', 'مسؤول تقارير مالية'],
            'التقنية' => ['مدير تقني', 'مطور واجهات أمامية', 'مطور Backend', 'مطور Full Stack', 'مصمم UI/UX', 'مسؤول أنظمة', 'مسؤول دعم تقني', 'مختبر برمجيات'],
            'الموارد البشرية' => ['مدير موارد بشرية', 'مسؤول موارد بشرية', 'مسؤول توظيف', 'مسؤول متطوعين', 'منسق تدريب وتطوير'],
            'المجتمع والصحة' => ['منسق مجتمعي', 'مسؤول مبادرات', 'مسؤول توعية', 'مسؤول دعم مجتمعي'],
            'الجودة والحوكمة' => ['مدير جودة', 'مسؤول جودة', 'مسؤول حوكمة', 'مسؤول رقابة', 'مسؤول امتثال'],
        ];

        $unmapped = [];
        foreach ($mapping as $departmentNameAr => $titles) {
            $departmentId = \Illuminate\Support\Facades\DB::table('departments')->where('name_ar', $departmentNameAr)->value('id');
            if (! $departmentId) {
                $unmapped[] = $departmentNameAr;
                continue;
            }
            foreach ($titles as $i => $title) {
                \Illuminate\Support\Facades\DB::table('job_titles')->insertOrIgnore([
                    'department_id' => $departmentId,
                    'name_ar'       => $title,
                    'is_active'     => true,
                    'sort_order'    => $i,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }

        if ($unmapped) {
            Log::warning('job_titles seed: no matching department found for ' . implode(', ', $unmapped));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_titles');
    }
};
