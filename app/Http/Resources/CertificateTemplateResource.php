<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Core
            'id'              => $this->id,
            'name'            => $this->name,
            'type'            => $this->type,
            'language'        => $this->language,
            'code_prefix'     => $this->code_prefix,
            'is_active'       => (bool) $this->is_active,
            'is_default'      => (bool) $this->is_default,

            // Designer mode
            'designer_mode'   => (bool) $this->designer_mode,
            'blade_path'      => $this->blade_path,

            // Branding
            'org_name_en'     => $this->org_name_en,
            'org_name_ar'     => $this->org_name_ar,
            'org2_name_en'    => $this->org2_name_en,
            'org2_name_ar'    => $this->org2_name_ar,
            'logo_primary'    => $this->logo_primary
                ? \Illuminate\Support\Facades\Storage::url($this->logo_primary)
                : null,
            'logo_secondary'  => $this->logo_secondary
                ? \Illuminate\Support\Facades\Storage::url($this->logo_secondary)
                : null,
            'learning_path_name_ar' => $this->learning_path_name_ar,
            'learning_path_name_en' => $this->learning_path_name_en,

            // Typography
            'font_arabic'     => $this->font_arabic,
            'font_english'    => $this->font_english,

            // Colors
            'color_primary'   => $this->color_primary,
            'color_secondary' => $this->color_secondary,
            'color_accent'    => $this->color_accent,
            'color_text'      => $this->color_text,

            // Toggles
            'show_admin_signature'   => (bool) ($this->show_admin_signature   ?? true),
            'show_program_signature' => (bool) ($this->show_program_signature ?? true),
            'show_official_stamp'    => (bool) ($this->show_official_stamp    ?? true),

            // Signature data
            'admin_sig_name_en'   => $this->admin_sig_name_en,
            'admin_sig_name_ar'   => $this->admin_sig_name_ar,
            'admin_sig_title_en'  => $this->admin_sig_title_en,
            'admin_sig_title_ar'  => $this->admin_sig_title_ar,
            'program_sig_name_ar' => $this->program_sig_name_ar,
            'program_sig_title_ar'=> $this->program_sig_title_ar,

            // Layout
            'paper_size'     => $this->paper_size,
            'orientation'    => $this->orientation,
            'pdf_quality'    => $this->pdf_quality,
            'margin_top'     => $this->margin_top,
            'margin_bottom'  => $this->margin_bottom,
            'margin_left'    => $this->margin_left,
            'margin_right'   => $this->margin_right,
            'bg_style'       => $this->bg_style,
            'border_style'   => $this->border_style,

            // Legacy HTML fields (kept for backward compat)
            'html_template'  => $this->html_template,
            'css_template'   => $this->css_template,
            'config_json'    => $this->config_json,

            'certificates_count' => $this->when(isset($this->certificates_count), $this->certificates_count),

            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy
                ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]
                : null),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy
                ? ['id' => $this->updatedBy->id, 'name' => $this->updatedBy->name]
                : null),
        ];
    }
}
