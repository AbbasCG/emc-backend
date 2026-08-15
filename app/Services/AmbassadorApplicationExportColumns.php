<?php

namespace App\Services;

use App\Models\AmbassadorApplication;

/**
 * Canonical column registry for the ambassador-applications export center —
 * the single source of truth for both the frontend's column-picker options
 * and the actual row values written into CSV/XLSX. Every key here maps to a
 * REAL field on AmbassadorApplication (app/Models/AmbassadorApplication.php)
 * or a real relation — nothing is fabricated. The original UI spec asked for
 * a "Department/Position" group and three privacy-consent checkboxes; neither
 * exists anywhere on this model (confirmed by grepping the model, migration,
 * and fillable list), so they are intentionally omitted rather than invented.
 */
class AmbassadorApplicationExportColumns
{
    /**
     * @return array<string, array{label: string, group: string, resolver: callable(AmbassadorApplication): (string|int|float|bool|null)}>
     */
    public static function registry(): array
    {
        $bool = fn (?bool $v) => $v === null ? '' : ($v ? 'نعم' : 'لا');
        $date = fn (?\DateTimeInterface $v) => $v?->format('Y-m-d') ?? '';
        $dateTime = fn (?\DateTimeInterface $v) => $v?->format('Y-m-d H:i') ?? '';
        $list = fn (?array $v) => $v ? implode('، ', $v) : '';

        return [
            // ── Personal information ──
            'full_name'   => ['label' => 'الاسم الكامل', 'group' => 'personal', 'resolver' => fn ($a) => $a->full_name],
            'email'       => ['label' => 'البريد الإلكتروني', 'group' => 'personal', 'resolver' => fn ($a) => $a->email],
            'mobile_phone' => ['label' => 'رقم الهاتف', 'group' => 'personal', 'resolver' => fn ($a) => $a->mobile_phone],
            'date_of_birth' => ['label' => 'تاريخ الميلاد', 'group' => 'personal', 'resolver' => fn ($a) => $date($a->date_of_birth)],
            'gender'      => ['label' => 'الجنس', 'group' => 'personal', 'resolver' => fn ($a) => $a->gender],
            'nationality' => ['label' => 'الجنسية', 'group' => 'personal', 'resolver' => fn ($a) => $a->nationality],
            'country'     => ['label' => 'بلد الإقامة الحالي', 'group' => 'personal', 'resolver' => fn ($a) => $a->country],
            'city'        => ['label' => 'مدينة الإقامة الحالية', 'group' => 'personal', 'resolver' => fn ($a) => $a->city],

            // ── Academic information ──
            'university_name' => ['label' => 'الجامعة', 'group' => 'academic', 'resolver' => fn ($a) => $a->university_name],
            'university_type' => ['label' => 'نوع الجامعة', 'group' => 'academic', 'resolver' => fn ($a) => $a->university_type],
            'faculty'          => ['label' => 'الكلية', 'group' => 'academic', 'resolver' => fn ($a) => $a->faculty],
            'major'            => ['label' => 'التخصص', 'group' => 'academic', 'resolver' => fn ($a) => $a->major],
            'study_year'       => ['label' => 'السنة الدراسية', 'group' => 'academic', 'resolver' => fn ($a) => $a->study_year],
            'student_status'   => ['label' => 'الحالة الدراسية', 'group' => 'academic', 'resolver' => fn ($a) => $a->student_status],
            'expected_graduation_date' => ['label' => 'تاريخ التخرج المتوقع', 'group' => 'academic', 'resolver' => fn ($a) => $date($a->expected_graduation_date)],

            // ── Ambassador / workflow information (no department/position field exists on this model) ──
            'status'             => ['label' => 'حالة الطلب', 'group' => 'ambassador', 'resolver' => fn ($a) => $a->status],
            'submitted_at'       => ['label' => 'تاريخ التقديم', 'group' => 'ambassador', 'resolver' => fn ($a) => $dateTime($a->created_at)],
            'reviewed_at'        => ['label' => 'تاريخ المراجعة', 'group' => 'ambassador', 'resolver' => fn ($a) => $dateTime($a->reviewed_at)],
            'status_updated_at'  => ['label' => 'تاريخ آخر تحديث للحالة', 'group' => 'ambassador', 'resolver' => fn ($a) => $dateTime($a->status_updated_at)],
            'reviewed_by'        => ['label' => 'تمت المراجعة بواسطة', 'group' => 'ambassador', 'resolver' => fn ($a) => $a->reviewer?->name ?? ''],

            // ── Skills ──
            'skills'    => ['label' => 'المهارات', 'group' => 'skills', 'resolver' => fn ($a) => $list($a->skills)],
            'interests' => ['label' => 'الاهتمامات', 'group' => 'skills', 'resolver' => fn ($a) => $list($a->interests)],
            'has_event_organization_experience' => ['label' => 'خبرة تنظيم الفعاليات', 'group' => 'skills', 'resolver' => fn ($a) => $bool($a->has_event_organization_experience)],
            'has_volunteer_experience' => ['label' => 'خبرة تطوعية', 'group' => 'skills', 'resolver' => fn ($a) => $bool($a->has_volunteer_experience)],
            'has_teaching_experience'  => ['label' => 'خبرة تدريسية', 'group' => 'skills', 'resolver' => fn ($a) => $bool($a->has_teaching_experience)],
            'motivation_why'    => ['label' => 'الدافع للتقديم', 'group' => 'skills', 'resolver' => fn ($a) => $a->motivation_why],
            'contribution_what' => ['label' => 'المساهمة المتوقعة', 'group' => 'skills', 'resolver' => fn ($a) => $a->contribution_what],

            // ── Social media ──
            'social_linkedin' => ['label' => 'LinkedIn', 'group' => 'social', 'resolver' => fn ($a) => $a->social_linkedin],
            'website_url'     => ['label' => 'الموقع / معرض الأعمال', 'group' => 'social', 'resolver' => fn ($a) => $a->website_url],
            'social_instagram' => ['label' => 'Instagram', 'group' => 'social', 'resolver' => fn ($a) => $a->social_instagram],
            'social_facebook'  => ['label' => 'Facebook', 'group' => 'social', 'resolver' => fn ($a) => $a->social_facebook],
            'social_tiktok'    => ['label' => 'TikTok', 'group' => 'social', 'resolver' => fn ($a) => $a->social_tiktok],
            'social_github'    => ['label' => 'GitHub', 'group' => 'social', 'resolver' => fn ($a) => $a->social_github],
            'followers_count'  => ['label' => 'عدد المتابعين', 'group' => 'social', 'resolver' => fn ($a) => $a->followers_count],

            // ── Files ──
            'cv_uploaded'    => ['label' => 'تم رفع السيرة الذاتية', 'group' => 'files', 'resolver' => fn ($a) => $bool((bool) $a->cv_path)],
            'photo_uploaded' => ['label' => 'تم رفع الصورة الشخصية', 'group' => 'files', 'resolver' => fn ($a) => $bool((bool) $a->photo_path)],

            // ── System information ──
            'created_at' => ['label' => 'تاريخ الإنشاء', 'group' => 'system', 'resolver' => fn ($a) => $dateTime($a->created_at)],
            'updated_at' => ['label' => 'تاريخ آخر تعديل', 'group' => 'system', 'resolver' => fn ($a) => $dateTime($a->updated_at)],
            'admin_notes' => ['label' => 'ملاحظات داخلية', 'group' => 'system', 'resolver' => fn ($a) => $a->admin_notes],
        ];
    }

    public static function groups(): array
    {
        return [
            'personal'   => 'المعلومات الشخصية',
            'academic'   => 'المعلومات الأكاديمية',
            'ambassador' => 'معلومات السفير',
            'skills'     => 'المهارات',
            'social'     => 'التواصل الاجتماعي',
            'files'      => 'الملفات',
            'system'     => 'معلومات النظام',
        ];
    }

    public static function defaultColumns(): array
    {
        return ['full_name', 'email', 'mobile_phone', 'country', 'city', 'university_name', 'major', 'status', 'submitted_at'];
    }

    public static function resolveRow(AmbassadorApplication $app, array $columnKeys): array
    {
        $registry = self::registry();
        $row = [];
        foreach ($columnKeys as $key) {
            if (! isset($registry[$key])) continue;
            $row[$key] = ($registry[$key]['resolver'])($app);
        }

        return $row;
    }
}
