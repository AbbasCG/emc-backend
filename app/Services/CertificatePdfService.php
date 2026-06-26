<?php

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;

class CertificatePdfService
{
    /**
     * Render HTML certificate from template + certificate data.
     */
    public function renderHtml(Certificate $certificate): string
    {
        $certificate->loadMissing(['template', 'user', 'course', 'track', 'workshop', 'issuedBy']);

        if ($certificate->template && $certificate->template->html_template) {
            $html = $certificate->template->html_template;
        } else {
            $html = $this->defaultHtmlTemplate($certificate->certificate_type ?? 'custom');
        }

        $html = $this->replacePlaceholders($html, $certificate);

        return $html;
    }

    /**
     * Generate and store the certificate as HTML file. Returns the stored path.
     */
    public function generate(Certificate $certificate): string
    {
        $html = $this->renderHtml($certificate);

        $year = now()->year;
        $type = $certificate->certificate_type ?? 'general';
        $code = $certificate->certificate_code ?? $certificate->verification_code ?? $certificate->id;

        $relativePath = "certificates/{$year}/{$type}/{$code}.html";

        Storage::disk('local')->put("private/{$relativePath}", $html);

        $certificate->update(['pdf_path' => "private/{$relativePath}"]);

        return "private/{$relativePath}";
    }

    /**
     * Get verification URL for a certificate.
     */
    public function verificationUrl(Certificate $certificate): string
    {
        $code = $certificate->verification_code ?? '';
        return url('/api/certificates/verify/' . $code);
    }

    /**
     * Replace {{placeholders}} with real certificate data.
     */
    private function replacePlaceholders(string $html, Certificate $certificate): string
    {
        $user     = $certificate->user;
        $course   = $certificate->course;
        $track    = $certificate->track;
        $workshop = $certificate->workshop;
        $issuedBy = $certificate->issuedBy;

        $studentName  = $certificate->recipient_name
            ?? ($user ? $user->name : 'المتعلم/المتعلمة');

        $courseName = match (true) {
            $course   !== null => $course->title ?? '',
            $track    !== null => $track->name ?? '',
            $workshop !== null => $workshop->title ?? '',
            default            => $certificate->title ?? '',
        };

        $metadata    = $certificate->metadata ?? [];
        $hours       = $metadata['hours'] ?? '';
        $programName = $metadata['program_name'] ?? ($course?->title ?? '');

        $typeLabels = [
            'course_completion'   => 'إتمام الدورة',
            'workshop_attendance' => 'حضور ورشة عمل',
            'summer_camp'         => 'المخيم الصيفي',
            'learning_track'      => 'المسار التعليمي',
            'partner'             => 'الشراكة',
            'guest_speaker'       => 'المحاضر الضيف',
            'volunteer'           => 'التطوع',
            'internship'          => 'التدريب',
            'sponsor'             => 'الرعاية',
            'custom'              => 'تقدير',
        ];

        $bodyTexts = [
            'course_completion'   => "وذلك بإتمام جميع متطلبات الدورة بنجاح" . ($hours ? " ({$hours} ساعة)" : ''),
            'workshop_attendance' => "بحضور ورشة العمل وإتمام متطلباتها",
            'summer_camp'         => "بالمشاركة في برنامج المخيم الصيفي",
            'learning_track'      => "باجتياز المسار التعليمي بنجاح",
            'volunteer'           => "تقديرًا لجهوده/جهودها التطوعية المميزة",
            'internship'          => "باستكمال فترة التدريب بنجاح",
            'guest_speaker'       => "تقديرًا لمساهمته/مساهمتها كمحاضر ضيف",
            'partner'             => "تقديرًا للشراكة والتعاون المثمر",
            'sponsor'             => "تقديرًا لدعمه/دعمها وإسهامه/إسهامها",
            'custom'              => '',
        ];

        $placeholders = [
            '{{student_name}}'       => $studentName,
            '{{course_name}}'        => $courseName,
            '{{workshop_name}}'      => $workshop?->title ?? '',
            '{{track_name}}'         => $track?->name ?? '',
            '{{hours}}'              => (string) $hours,
            '{{completion_date}}'    => $certificate->issued_at
                ? $certificate->issued_at->format('Y/m/d')
                : now()->format('Y/m/d'),
            '{{issue_date}}'         => $certificate->issued_at
                ? $certificate->issued_at->format('Y/m/d')
                : now()->format('Y/m/d'),
            '{{certificate_code}}'   => $certificate->certificate_code ?? $certificate->verification_code ?? '',
            '{{verification_url}}'   => $this->verificationUrl($certificate),
            '{{instructor_name}}'    => $issuedBy?->name ?? ($metadata['instructor_name'] ?? ''),
            '{{program_name}}'       => $programName,
            '{{title}}'              => $certificate->title ?? 'شهادة إتمام',
            '{{cert_type_label}}'    => $typeLabels[$certificate->certificate_type ?? 'custom'] ?? 'تقدير',
            '{{body_text}}'          => $bodyTexts[$certificate->certificate_type ?? 'custom'] ?? '',
            '{{signature_1_name}}'   => $metadata['signature_1_name'] ?? 'مدير البرنامج',
            '{{signature_1_title}}'  => $metadata['signature_1_title'] ?? 'Program Director',
            '{{signature_2_name}}'   => $metadata['signature_2_name'] ?? 'الرئيس التنفيذي',
            '{{signature_2_title}}'  => $metadata['signature_2_title'] ?? 'CEO',
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $html);
    }

    /**
     * Default HTML template for a given certificate type.
     */
    private function defaultHtmlTemplate(string $type): string
    {
        $typeLabels = [
            'course_completion'   => 'إتمام الدورة',
            'workshop_attendance' => 'حضور ورشة عمل',
            'summer_camp'         => 'المخيم الصيفي',
            'learning_track'      => 'المسار التعليمي',
            'partner'             => 'الشراكة',
            'guest_speaker'       => 'المحاضر الضيف',
            'volunteer'           => 'التطوع',
            'internship'          => 'التدريب',
            'sponsor'             => 'الرعاية',
            'custom'              => 'تقدير',
        ];
        $label = $typeLabels[$type] ?? 'شهادة';

        return <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<style>
  @page { size: A4 landscape; margin: 0; }
  body {
    width: 297mm; height: 210mm; margin: 0; padding: 0;
    background: linear-gradient(135deg, #0d1b2a 0%, #1a3a5c 40%, #2691c2 100%);
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
    display: flex; align-items: center; justify-content: center;
  }
  .cert {
    width: 260mm; background: white;
    border-radius: 8px; padding: 15mm;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    text-align: center; direction: rtl;
    border: 3px solid #2691c2;
  }
  .logo-area { margin-bottom: 8mm; }
  .cert-type { font-size: 12pt; color: #2691c2; letter-spacing: 3px; text-transform: uppercase; margin-bottom: 4mm; }
  .cert-title { font-size: 28pt; font-weight: bold; color: #0d1b2a; margin-bottom: 4mm; }
  .cert-body { font-size: 13pt; color: #333; line-height: 1.8; margin: 6mm 0; }
  .student-name { font-size: 22pt; font-weight: bold; color: #2691c2; border-bottom: 2px solid #2691c2; display: inline-block; padding-bottom: 2mm; margin: 4mm 0; }
  .course-name { font-size: 16pt; font-weight: bold; color: #0d1b2a; }
  .cert-footer { display: flex; justify-content: space-between; margin-top: 10mm; padding-top: 5mm; border-top: 1px solid #ddd; }
  .sig-block { text-align: center; }
  .sig-line { border-top: 1px solid #333; width: 50mm; margin: 0 auto 2mm; }
  .sig-name { font-size: 10pt; font-weight: bold; color: #0d1b2a; }
  .sig-title { font-size: 9pt; color: #666; }
  .cert-meta { font-size: 9pt; color: #999; margin-top: 5mm; }
  .verification { font-size: 8pt; color: #2691c2; }
  .divider { width: 60mm; height: 3px; background: linear-gradient(90deg, #2691c2, #f4a320); margin: 3mm auto; border-radius: 2px; }
</style>
</head>
<body>
<div class="cert">
  <div class="logo-area">
    <div style="font-size:18pt; font-weight:bold; color:#0d1b2a;">مركز ماستر التعليمي</div>
    <div style="font-size:10pt; color:#666;">Master Educational Center — EMC</div>
  </div>
  <div class="divider"></div>
  <div class="cert-type">شهادة {$label}</div>
  <div class="cert-title">{{title}}</div>
  <div class="cert-body">
    <p>نشهد بأن المتعلم/المتعلمة</p>
    <div class="student-name">{{student_name}}</div>
    <p>قد أتم/أتمت بنجاح متطلبات</p>
    <div class="course-name">{{course_name}}</div>
    <p>{{body_text}}</p>
  </div>
  <div class="cert-footer">
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name">{{signature_1_name}}</div>
      <div class="sig-title">{{signature_1_title}}</div>
    </div>
    <div style="text-align:center">
      <div class="cert-meta">رقم الشهادة: {{certificate_code}}</div>
      <div class="cert-meta">تاريخ الإصدار: {{issue_date}}</div>
      <div class="verification">{{verification_url}}</div>
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name">{{signature_2_name}}</div>
      <div class="sig-title">{{signature_2_title}}</div>
    </div>
  </div>
</div>
</body>
</html>
HTML;
    }
}
