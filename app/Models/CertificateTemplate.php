<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificateTemplate extends Model
{
    protected $fillable = [
        'name', 'type', 'language', 'background_path',
        'html_template', 'css_template', 'config_json',
        'code_prefix', 'is_active', 'is_default', 'created_by', 'updated_by',
        // Designer mode
        'designer_mode', 'blade_path',
        'learning_path_name_ar', 'learning_path_name_en',
        // Branding
        'org_name_en', 'org_name_ar', 'org2_name_en', 'org2_name_ar',
        'logo_primary', 'logo_secondary',
        // Typography
        'font_arabic', 'font_english',
        // Colors
        'color_primary', 'color_secondary', 'color_accent', 'color_text',
        // Toggles
        'show_admin_signature', 'show_program_signature', 'show_official_stamp',
        // Admin signature
        'admin_sig_image', 'admin_sig_name_en', 'admin_sig_name_ar',
        'admin_sig_title_en', 'admin_sig_title_ar',
        // Programs signature
        'program_sig_image', 'program_sig_name_en', 'program_sig_name_ar',
        'program_sig_title_en', 'program_sig_title_ar',
        // Stamp
        'official_stamp_image',
        // Layout
        'paper_size', 'orientation', 'pdf_quality',
        'margin_top', 'margin_bottom', 'margin_left', 'margin_right',
        'bg_style', 'border_style',
    ];

    protected $casts = [
        'config_json'            => 'array',
        'is_active'              => 'boolean',
        'is_default'             => 'boolean',
        'designer_mode'          => 'boolean',
        'show_admin_signature'   => 'boolean',
        'show_program_signature' => 'boolean',
        'show_official_stamp'    => 'boolean',
        'margin_top'             => 'integer',
        'margin_bottom'          => 'integer',
        'margin_left'            => 'integer',
        'margin_right'           => 'integer',
    ];

    // ── Relations ──────────────────────────────────────────────────────────────

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'certificate_template_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDesigner($query)
    {
        return $query->where('designer_mode', true);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function getBladePath(): string
    {
        return $this->blade_path ?? 'certificates.templates.default';
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
  body { width: 297mm; height: 210mm; margin: 0; padding: 0;
    background: linear-gradient(135deg, #0d1b2a 0%, #1a3a5c 40%, #2691c2 100%);
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
    display: flex; align-items: center; justify-content: center; }
  .cert { width: 260mm; background: white; border-radius: 8px; padding: 15mm;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3); text-align: center; direction: rtl;
    border: 3px solid #2691c2; }
  .cert-type { font-size: 12pt; color: #2691c2; letter-spacing: 3px; text-transform: uppercase; margin-bottom: 4mm; }
  .cert-title { font-size: 28pt; font-weight: bold; color: #0d1b2a; margin-bottom: 4mm; }
  .student-name { font-size: 22pt; font-weight: bold; color: #2691c2; border-bottom: 2px solid #2691c2; display: inline-block; padding-bottom: 2mm; margin: 4mm 0; }
  .course-name { font-size: 16pt; font-weight: bold; color: #0d1b2a; }
  .cert-footer { display: flex; justify-content: space-between; margin-top: 10mm; padding-top: 5mm; border-top: 1px solid #ddd; }
  .sig-block { text-align: center; }
  .sig-line { border-top: 1px solid #333; width: 50mm; margin: 0 auto 2mm; }
  .sig-name { font-size: 10pt; font-weight: bold; }
  .cert-meta { font-size: 9pt; color: #999; margin-top: 5mm; }
</style>
</head>
<body>
<div class="cert">
  <div class="cert-type">شهادة {$label}</div>
  <div class="cert-title">{{title}}</div>
  <div style="font-size:13pt; color:#333; line-height:1.8; margin:6mm 0">
    <p>نشهد بأن</p>
    <div class="student-name">{{student_name}}</div>
    <p>قد أتم/أتمت بنجاح</p>
    <div class="course-name">{{course_name}}</div>
    <p>{{body_text}}</p>
  </div>
  <div class="cert-footer">
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name">{{signature_1_name}}</div>
    </div>
    <div style="text-align:center">
      <div class="cert-meta">{{certificate_code}}</div>
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name">{{signature_2_name}}</div>
    </div>
  </div>
</div>
</body>
</html>
HTML;
    }
}
