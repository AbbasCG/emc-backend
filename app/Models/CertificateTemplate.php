<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateTemplate extends Model
{
    protected $fillable = [
        'name', 'type', 'language', 'background_path',
        'html_template', 'css_template', 'config_json',
        'code_prefix', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'config_json' => 'array',
        'is_active'   => 'boolean',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getDefaultHtml(string $type): string
    {
        $typeLabels = [
            'course_completion'    => 'إتمام الدورة',
            'workshop_attendance'  => 'حضور ورشة عمل',
            'summer_camp'          => 'المخيم الصيفي',
            'learning_track'       => 'المسار التعليمي',
            'partner'              => 'الشراكة',
            'guest_speaker'        => 'المحاضر الضيف',
            'volunteer'            => 'التطوع',
            'internship'           => 'التدريب',
            'sponsor'              => 'الرعاية',
            'custom'               => 'تقدير',
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
