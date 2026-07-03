<?php

namespace App\Services;

use App\Mail\CertificateNeedsApprovalMail;
use App\Models\Certificate;
use App\Models\CertificateApproval;
use App\Models\CertificateTemplate;
use App\Models\Course;
use App\Models\User;
use App\Services\EmailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CertificateGeneratorService
{
    public function __construct(
        private readonly CertificateNumberService $numberService,
    ) {}

    /**
     * Generate (or regenerate) a certificate for a user + course.
     * Returns the Certificate model (status = pending_approval).
     */
    public function generateForCourse(
        User                $user,
        Course              $course,
        CertificateTemplate $template,
        ?int                $workshopNumber = null,
        ?User               $issuedBy = null,
    ): Certificate {
        $course->loadMissing('learningPath');

        $code = $this->numberService->generate(
            prefix: $template->code_prefix ?? 'EMC',
            workshopNumber: $workshopNumber,
        );

        return DB::transaction(function () use ($user, $course, $template, $workshopNumber, $issuedBy, $code) {
            $certificate = Certificate::create([
                'user_id'                 => $user->id,
                'course_id'               => $course->id,
                'learning_path_id'        => $course->learningPath?->id ?? null,
                'certificate_template_id' => $template->id,
                'certificate_type'        => Certificate::TYPE_WORKSHOP_ATTENDANCE,
                'title'                   => $course->title ?? 'شهادة حضور',
                'certificate_code'        => $code,
                'verification_code'       => strtoupper(\Illuminate\Support\Str::random(12)),
                'status'                  => Certificate::STATUS_PENDING,
                'approval_status'         => Certificate::APPROVAL_PENDING,
                'workshop_number'         => $workshopNumber,
                'issued_by'               => $issuedBy?->id,
                'recipient_name'          => $user->name,
                'recipient_email'         => $user->email,
                'metadata'                => $this->buildMetadata($user, $course, $template, $workshopNumber),
            ]);

            CertificateApproval::create(['certificate_id' => $certificate->id, 'type' => CertificateApproval::TYPE_ADMINISTRATIVE, 'status' => CertificateApproval::STATUS_PENDING]);
            CertificateApproval::create(['certificate_id' => $certificate->id, 'type' => CertificateApproval::TYPE_PROGRAMS,       'status' => CertificateApproval::STATUS_PENDING]);

            $this->notifyApprovers($certificate);

            return $certificate;
        });
    }

    // ── Approver notifications ────────────────────────────────────────────────

    private function notifyApprovers(Certificate $certificate): void
    {
        $adminRoles   = ['super_admin', 'admin'];
        $programRoles = ['department_manager', 'programs_manager'];

        $admins = User::whereIn('role', $adminRoles)->where('is_active', true)->get(['id', 'name', 'email']);
        foreach ($admins as $admin) {
            if ($admin->email) {
                EmailService::dispatch(
                    'certificate_needs_approval',
                    $admin->email,
                    $admin->name,
                    $admin->id,
                    new CertificateNeedsApprovalMail($certificate->fresh(), $admin, 'الاعتماد الإداري'),
                    'طلب اعتماد شهادة جديد — ' . config('app.name'),
                );
            }
        }

        $managers = User::whereIn('role', $programRoles)->where('is_active', true)->get(['id', 'name', 'email']);
        foreach ($managers as $manager) {
            if ($manager->email) {
                EmailService::dispatch(
                    'certificate_needs_approval',
                    $manager->email,
                    $manager->name,
                    $manager->id,
                    new CertificateNeedsApprovalMail($certificate->fresh(), $manager, 'اعتماد البرامج'),
                    'طلب اعتماد شهادة جديد — ' . config('app.name'),
                );
            }
        }
    }

    // ── Template data ─────────────────────────────────────────────────────────

    /**
     * Build the data array passed to the Blade certificate template.
     * Public output shape is stable — the Blade template depends on these keys.
     */
    public function buildTemplateData(Certificate $certificate): array
    {
        $certificate->loadMissing(['user', 'course.learningPath', 'template']);

        $participant = $this->buildParticipantData($certificate);
        $course      = $this->buildCourseData($certificate);
        $badge       = $this->buildBadgeData($certificate, $course);
        $dates       = $this->buildDateData($certificate);
        $desc        = $this->buildDescriptionData($certificate, $badge, $dates);

        return array_merge(
            $participant,
            $badge,
            $course,
            $dates,
            $desc,
            $this->buildCertificateNumberData($certificate),
        );
    }

    // ── Template config ───────────────────────────────────────────────────────

    /**
     * Build the config array for the Blade certificate template from a DB template row.
     */
    public function buildTemplateConfig(CertificateTemplate $template, bool $forPdf = false): array
    {
        return array_merge([
            'for_pdf'                => $forPdf,
            'blade_path'             => $template->blade_path          ?? 'certificates.templates.default',
            'org_name_en'            => $template->org_name_en         ?? 'Educational Mastar Central',
            'org_name_ar'            => $template->org_name_ar         ?? 'مركز ماستر التعليمي',
            'org2_name_en'           => $template->org2_name_en,
            'org2_name_ar'           => $template->org2_name_ar,
            'logo_primary'           => $template->logo_primary        ? Storage::url($template->logo_primary)        : null,
            'logo_secondary'         => $template->logo_secondary      ? Storage::url($template->logo_secondary)      : null,
            'font_arabic'            => $template->font_arabic         ?? 'Cairo',
            'font_english'           => $template->font_english        ?? 'Poppins',
            'color_primary'          => $template->color_primary       ?? '#0D47A1',
            'color_secondary'        => $template->color_secondary     ?? '#F57C00',
            'color_accent'           => $template->color_accent        ?? '#29B6F6',
            'color_text'             => $template->color_text          ?? '#1A1A1A',
            'show_admin_signature'   => $template->show_admin_signature   ?? true,
            'show_program_signature' => $template->show_program_signature ?? true,
            'show_official_stamp'    => $template->show_official_stamp    ?? true,
            'admin_sig_image'        => $template->admin_sig_image     ? Storage::url($template->admin_sig_image)     : null,
            'admin_sig_name_en'      => $template->admin_sig_name_en   ?? 'Tareq Tuaayman',
            'admin_sig_name_ar'      => $template->admin_sig_name_ar   ?? 'طارق طعيمان',
            'admin_sig_title_en'     => $template->admin_sig_title_en  ?? 'Founder & General Manager – EMC',
            'admin_sig_title_ar'     => $template->admin_sig_title_ar,
            'program_sig_image'      => $template->program_sig_image   ? Storage::url($template->program_sig_image)  : null,
            'program_sig_name_en'    => $template->program_sig_name_en,
            'program_sig_name_ar'    => $template->program_sig_name_ar ?? 'إدارة البرامج',
            'program_sig_title_en'   => $template->program_sig_title_en,
            'program_sig_title_ar'   => $template->program_sig_title_ar ?? 'مركز ماستر التعليمي – EMC',
            'official_stamp_image'   => $template->official_stamp_image ? Storage::url($template->official_stamp_image) : null,
            'paper_size'             => $template->paper_size          ?? 'A4',
            'orientation'            => $template->orientation         ?? 'landscape',
            'pdf_quality'            => $template->pdf_quality         ?? 'high',
            'margin_top'             => $template->margin_top          ?? 8,
            'margin_bottom'          => $template->margin_bottom       ?? 8,
            'margin_left'            => $template->margin_left         ?? 12,
            'margin_right'           => $template->margin_right        ?? 12,
            'bg_style'               => $template->bg_style            ?? 'default',
            'border_style'           => $template->border_style        ?? 'default',
            'learning_path_name_ar'  => $template->learning_path_name_ar,
            'learning_path_name_en'  => $template->learning_path_name_en,
        ], $template->config_json ?? []);
    }

    // ── Private data builders ─────────────────────────────────────────────────

    private function buildParticipantData(Certificate $certificate): array
    {
        return [
            'participant_name' => $certificate->recipient_name
                ?? $certificate->user?->name
                ?? 'اسم المشارك',
        ];
    }

    private function buildCourseData(Certificate $certificate): array
    {
        $course = $certificate->course;

        return [
            'course_title_en' => $course?->title    ?? 'Course Title',
            'course_title_ar' => $course?->title_ar ?? $course?->title ?? 'عنوان الدورة',
        ];
    }

    private function buildBadgeData(Certificate $certificate, array $courseData): array
    {
        $course   = $certificate->course;
        $template = $certificate->template;
        $lp       = $course?->learningPath;

        $workshopNum = $certificate->workshop_number ?? 1;
        $courseYear  = ($course?->start_date ? Carbon::parse($course->start_date) : Carbon::now())->format('Y');

        $campNameAr = $template?->learning_path_name_ar ?? $lp?->title_ar ?? $lp?->title ?? 'المعسكر الصيفي';
        $campNameEn = $template?->learning_path_name_en ?? $lp?->title    ?? 'Summer Camp';

        $workshopLabelAr   = CertificateNumberService::arabicWorkshopLabel($workshopNum);
        $workshopOrdinalAr = CertificateNumberService::arabicOrdinal($workshopNum);
        $workshopOrdinalEn = CertificateNumberService::englishOrdinal($workshopNum);

        return [
            'camp_badge_en'       => "{$campNameEn} · Workshop {$workshopNum}",
            'camp_badge_ar'       => "{$campNameAr} · {$workshopLabelAr}",
            'presenter_en'        => 'THIS CERTIFICATE IS PRESENTED TO',
            'presenter_ar'        => "تشهد إدارة {$campNameAr} {$courseYear} بأن",
            'workshop_ordinal_en' => $workshopOrdinalEn,
            'workshop_ordinal_ar' => $workshopOrdinalAr,
            'workshop_number_ar'  => $workshopLabelAr,
            // expose for description builder
            '_camp_name_ar'       => $campNameAr,
            '_camp_name_en'       => $campNameEn,
            '_course_year'        => $courseYear,
            '_workshop_num'       => $workshopNum,
        ];
    }

    private function buildDateData(Certificate $certificate): array
    {
        $course        = $certificate->course;
        $startDate     = $course?->start_date ? Carbon::parse($course->start_date) : Carbon::now();
        $location      = CertificateNumberService::locationLabel($course?->location_type ?? 'online');
        $trainingHours = $course?->training_hours ?? 2;

        return [
            'date_en'       => CertificateNumberService::formatDateEn($startDate),
            'date_ar'       => CertificateNumberService::formatDateAr($startDate),
            'hours_en'      => "{$trainingHours} Training " . ($trainingHours === 1 ? 'Hour' : 'Hours') . " · {$location['en']}",
            'hours_ar'      => CertificateNumberService::trainingHoursAr($trainingHours) . " · {$location['ar']}",
            // expose for description builder
            '_start_date'     => $startDate,
            '_training_hours' => $trainingHours,
        ];
    }

    private function buildDescriptionData(Certificate $certificate, array $badgeData, array $dateData): array
    {
        $template = $certificate->template;

        $campNameEn    = $badgeData['_camp_name_en'];
        $campNameAr    = $badgeData['_camp_name_ar'];
        $courseYear    = $badgeData['_course_year'];
        $startDate     = $dateData['_start_date'];
        $totalWorkshops = 8;
        $totalHours    = 16;

        $orgEn = $template?->org_name_en ?? 'Educational Mastar Central';
        $orgAr = $template?->org_name_ar ?? 'مركز ماستر التعليمي';

        $descEn = sprintf(
            'This certificate is awarded as part of the <strong>EMC Intensive Summer Program for Artificial Intelligence %s</strong>, '
            . 'organized by %s over one full month (%s – %s), comprising %d applied workshops totaling %d training hours, '
            . 'guiding participants from the foundations of AI to its practical application in study, work, and projects.',
            $courseYear,
            $orgEn,
            $startDate->format('j F Y'),
            $startDate->copy()->addMonth()->format('j F Y'),
            $totalWorkshops,
            $totalHours,
        );

        $descAr = sprintf(
            'يُمنح هذه الشهادة ضمن المعسكر الصيفي المكثف لتعلم الذكاء الاصطناعي %s، الذي نظّمه %s على مدى شهر كامل '
            . '(%s – %s)، عبر %s ورش تطبيقية بإجمالي %s ساعة تدريبية، نقلت المشاركين من أساسيات الذكاء الاصطناعي '
            . 'إلى تطبيقه العملي في الدراسة والعمل والمشاريع.',
            CertificateNumberService::toArabicNumerals($courseYear),
            $orgAr,
            CertificateNumberService::formatDateAr($startDate),
            CertificateNumberService::formatDateAr($startDate->copy()->addMonth()),
            CertificateNumberService::toArabicNumerals((string) $totalWorkshops),
            CertificateNumberService::toArabicNumerals((string) $totalHours),
        );

        return [
            'description_en' => $descEn,
            'description_ar' => $descAr,
        ];
    }

    private function buildCertificateNumberData(Certificate $certificate): array
    {
        return [
            'certificate_number' => $certificate->certificate_code ?? 'EMC-XXXX',
        ];
    }

    private function buildMetadata(User $user, Course $course, CertificateTemplate $template, ?int $workshopNumber): array
    {
        return [
            'user_name'       => $user->name,
            'user_email'      => $user->email,
            'course_title'    => $course->title,
            'course_title_ar' => $course->title_ar ?? $course->title,
            'workshop_number' => $workshopNumber,
            'template_name'   => $template->name,
            'generated_at'    => now()->toISOString(),
        ];
    }
}
