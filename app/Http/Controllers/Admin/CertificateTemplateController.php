<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateTemplateResource;
use App\Models\CertificateTemplate;
use App\Services\ActivityLogger;
use App\Services\Certificate\CertificateRenderService;
use App\Services\CertificatePdfService;
use App\Support\CertificateTypes;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CertificateTemplateController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        if ($request->filled('type')) {
            $request->validate([
                'type' => ['required', 'string', Rule::in(CertificateTypes::ALL)],
            ]);
        }

        $templates = CertificateTemplate::with(['createdBy', 'updatedBy'])
            ->withCount('certificates')
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->language, fn ($q) => $q->where('language', $request->language))
            ->when(isset($request->is_active), fn ($q) => $q->where('is_active', (bool) $request->is_active))
            ->latest()
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data'    => CertificateTemplateResource::collection($templates)->response()->getData(true),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'type'            => ['required', 'string', Rule::in(CertificateTypes::ALL)],
            'language'        => 'required|in:arabic,english,bilingual',
            'background_path' => 'nullable|string',
            'html_template'   => 'nullable|string',   // optional — designer mode uses Blade
            'css_template'    => 'nullable|string',
            'config_json'     => 'nullable|array',
            'code_prefix'     => 'nullable|string|max:20',
            'is_active'       => 'nullable|boolean',
            // Designer mode defaults
            'designer_mode'   => 'nullable|boolean',
            'blade_path'      => 'nullable|string|max:200',
        ]);

        $data['created_by']   = $request->user()->id;
        $data['updated_by']   = $request->user()->id;
        $data['html_template'] = $data['html_template'] ?? '';
        // New templates default to designer mode
        if (! isset($data['designer_mode'])) {
            $data['designer_mode'] = true;
            $data['blade_path']    = 'certificates.templates.default';
        }
        // Copy defaults from the active default template
        $defaults = CertificateTemplate::where('is_default', true)->first();
        if ($defaults) {
            foreach ([
                'org_name_en', 'org_name_ar', 'font_arabic', 'font_english',
                'color_primary', 'color_secondary', 'color_accent', 'color_text',
                'admin_sig_name_en', 'admin_sig_name_ar', 'admin_sig_title_en', 'admin_sig_title_ar',
                'program_sig_name_ar', 'program_sig_title_ar',
                'margin_top', 'margin_bottom', 'margin_left', 'margin_right',
                'bg_style', 'border_style', 'paper_size', 'orientation', 'pdf_quality',
                'show_admin_signature', 'show_program_signature', 'show_official_stamp',
            ] as $field) {
                if (! isset($data[$field]) && $defaults->$field !== null) {
                    $data[$field] = $defaults->$field;
                }
            }
        }

        $template = CertificateTemplate::create($data);

        return response()->json([
            'success' => true,
            'data'    => new CertificateTemplateResource($template),
        ], 201);
    }

    /**
     * PUT /certificates/templates/{template}/set-default
     * Mark this template as the system default.
     */
    public function setDefault(CertificateTemplate $template): \Illuminate\Http\JsonResponse
    {
        // Unset any existing default
        CertificateTemplate::where('is_default', true)->update(['is_default' => false]);
        $template->update(['is_default' => true, 'is_active' => true]);

        CertificateRenderService::flushTemplateCache($template->id);

        ActivityLogger::log('certificate.template_set_default', null, [], request(), null, [
            'template_id'   => $template->id,
            'template_name' => $template->name,
        ]);

        return response()->json([
            'success' => true,
            'data'    => new CertificateTemplateResource($template->fresh()),
            'message' => 'تم تعيين القالب كافتراضي.',
        ]);
    }

    /**
     * POST /certificates/templates/{template}/duplicate
     * Clone a template.
     */
    public function duplicate(Request $request, CertificateTemplate $template): \Illuminate\Http\JsonResponse
    {
        $clone = $template->replicate();
        $clone->name       = $template->name . ' (نسخة)';
        $clone->is_default = false;
        $clone->is_active  = false;
        $clone->created_by = $request->user()->id;
        $clone->updated_by = $request->user()->id;
        $clone->save();

        return response()->json([
            'success' => true,
            'data'    => new CertificateTemplateResource($clone),
            'message' => 'تم نسخ القالب.',
        ], 201);
    }

    public function show(CertificateTemplate $template): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new CertificateTemplateResource($template->load(['createdBy', 'updatedBy'])),
        ]);
    }

    public function update(Request $request, CertificateTemplate $template): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'type'            => ['sometimes', 'string', Rule::in(CertificateTypes::ALL)],
            'language'        => 'sometimes|in:arabic,english,bilingual',
            'background_path' => 'nullable|string',
            'html_template'   => 'sometimes|string',
            'css_template'    => 'nullable|string',
            'config_json'     => 'nullable|array',
            'code_prefix'     => 'nullable|string|max:20',
            'is_active'       => 'nullable|boolean',
        ]);

        $data['updated_by'] = $request->user()->id;

        $template->update($data);

        CertificateRenderService::flushTemplateCache($template->id);

        ActivityLogger::log('certificate.template_updated', null, [], $request, null, [
            'template_id'   => $template->id,
            'template_name' => $template->name,
        ]);

        return response()->json([
            'success' => true,
            'data'    => new CertificateTemplateResource($template->fresh(['createdBy', 'updatedBy'])),
        ]);
    }

    public function destroy(CertificateTemplate $template): \Illuminate\Http\JsonResponse
    {
        $usageCount = $template->certificates()->count();

        if ($usageCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "لا يمكن حذف القالب لأنه مستخدم في {$usageCount} شهادة. يمكنك تعطيله بدلاً من ذلك.",
                'certificates_count' => $usageCount,
            ], 422);
        }

        ActivityLogger::log('certificate.template_deleted', $template, [], request(), null, [
            'template_name' => $template->name,
        ]);

        $template->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف القالب بنجاح.']);
    }

    public function toggleActive(CertificateTemplate $template): \Illuminate\Http\JsonResponse
    {
        $template->update(['is_active' => ! $template->is_active]);

        CertificateRenderService::flushTemplateCache($template->id);

        return response()->json([
            'success'   => true,
            'message'   => $template->is_active ? 'Template activated.' : 'Template deactivated.',
            'is_active' => $template->is_active,
        ]);
    }

    /**
     * GET /certificates/templates/default
     * Returns (or creates) the default designer-mode template.
     * Never returns NaN — always has a valid DB id.
     */
    public function getDefault(Request $request): \Illuminate\Http\JsonResponse
    {
        $template = CertificateTemplate::where('is_default', true)
            ->where('is_active', true)
            ->first()
            ?? CertificateTemplate::where('designer_mode', true)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

        if (! $template) {
            // Create a default one
            $template = CertificateTemplate::create([
                'name'             => 'قالب EMC الافتراضي – ثنائي اللغة',
                'is_default'       => true,
                'type'             => 'summer_camp',
                'language'         => 'bilingual',
                'code_prefix'      => 'EMC',
                'is_active'        => true,
                'html_template'    => '',
                'designer_mode'    => true,
                'blade_path'       => 'certificates.templates.default',
                'org_name_en'      => 'Educational Mastar Central',
                'org_name_ar'      => 'مركز ماستر التعليمي',
                'font_arabic'      => 'Cairo',
                'font_english'     => 'Poppins',
                'color_primary'    => '#0D2741',
                'color_secondary'  => '#F57C00',
                'color_accent'     => '#009DDB',
                'color_text'       => '#1A1A1A',
                'show_admin_signature'   => true,
                'show_program_signature' => true,
                'show_official_stamp'    => true,
                'admin_sig_name_en'      => 'Tareq Tuaayman',
                'admin_sig_name_ar'      => 'طارق طعيمان',
                'admin_sig_title_en'     => 'Founder & General Manager – EMC',
                'admin_sig_title_ar'     => 'مؤسس ومدير عام – EMC',
                'program_sig_name_ar'    => 'إدارة المعسكر الصيفي للذكاء الاصطناعي',
                'program_sig_title_ar'   => 'مركز ماستر التعليمي – EMC',
                'margin_top'             => 8,
                'margin_bottom'          => 8,
                'margin_left'            => 12,
                'margin_right'           => 12,
                'bg_style'               => 'default',
                'border_style'           => 'default',
                'paper_size'             => 'A4',
                'orientation'            => 'landscape',
                'pdf_quality'            => 'high',
                'created_by'             => $request->user()->id,
                'updated_by'             => $request->user()->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => new CertificateTemplateResource($template),
        ]);
    }

    /**
     * POST /certificates/templates/preview-designer
     * Live preview endpoint for the designer UI — accepts raw config + renders Blade template.
     */
    public function previewDesigner(\Illuminate\Http\Request $request): \Illuminate\Http\Response
    {
        $cfg = $request->input('cfg', []);

        // Merge with defaults
        $cfg = array_merge([
            'org_name_en'            => 'Educational Mastar Central',
            'org_name_ar'            => 'مركز ماستر التعليمي',
            'font_arabic'            => 'Cairo',
            'font_english'           => 'Poppins',
            'color_primary'          => '#0D47A1',
            'color_secondary'        => '#F57C00',
            'color_accent'           => '#29B6F6',
            'color_text'             => '#1A1A1A',
            'show_admin_signature'   => true,
            'show_program_signature' => true,
            'show_official_stamp'    => true,
            'admin_sig_name_en'      => 'Tareq Tuaayman',
            'admin_sig_name_ar'      => 'طارق طعيمان',
            'admin_sig_title_en'     => 'Founder & General Manager – EMC',
            'program_sig_name_ar'    => 'إدارة المعسكر الصيفي للذكاء الاصطناعي',
            'program_sig_title_ar'   => 'مركز ماستر التعليمي – EMC',
            'margin_top'             => 8,
            'margin_bottom'          => 8,
            'margin_left'            => 12,
            'margin_right'           => 12,
            'bg_style'               => 'default',
            'border_style'           => 'default',
            'blade_path'             => 'certificates.templates.default',
            'for_pdf'                => false,
            'learning_path_name_ar'  => $request->input('cfg.learning_path_name_ar', 'المعسكر الصيفي ٢٠٢٦'),
            'learning_path_name_en'  => $request->input('cfg.learning_path_name_en', 'AI Summer Camp 2026'),
        ], $cfg);

        // Sample preview data
        $lpNameAr = $cfg['learning_path_name_ar'] ?? 'المعسكر الصيفي ٢٠٢٦';
        $lpNameEn = $cfg['learning_path_name_en'] ?? 'AI Summer Camp 2026';

        $data = [
            'participant_name'    => '[ Participant Name / اسم المشارك ]',
            'camp_badge_en'       => "{$lpNameEn} · Workshop 1",
            'camp_badge_ar'       => "{$lpNameAr} · الورشة الأولى",
            'presenter_en'        => 'THIS CERTIFICATE IS PRESENTED TO',
            'presenter_ar'        => "تشهد إدارة {$lpNameAr} بأن",
            'workshop_ordinal_en' => 'first',
            'workshop_ordinal_ar' => 'الأولى',
            'workshop_number_ar'  => 'الورشة الأولى',
            'course_title_en'     => 'Introduction to Artificial Intelligence – The Complete Map',
            'course_title_ar'     => 'مُدخل إلى الذكاء الاصطناعي – الخريطة الكاملة',
            'date_en'             => 'Saturday, 20 June 2026',
            'date_ar'             => 'السبت ٢٠ يونيو ٢٠٢٦',
            'hours_en'            => '2 Training Hours · Online',
            'hours_ar'            => 'ساعتان تدريبيتان · عن بُعد',
            'description_en'      => 'This certificate is awarded as part of the <strong>EMC Intensive Summer Program for Artificial Intelligence 2026</strong>, organized by ' . ($cfg['org_name_en'] ?? 'Educational Mastar Central') . ' over one full month (20 June – 20 July 2026), comprising eight applied workshops totaling 16 training hours, guiding participants from the foundations of AI to its practical application in study, work, and projects.',
            'description_ar'      => 'يُمنح هذه الشهادة ضمن المعسكر الصيفي المكثف لتعلم الذكاء الاصطناعي ٢٠٢٦، الذي نظّمه ' . ($cfg['org_name_ar'] ?? 'مركز ماستر التعليمي') . ' على مدى شهر كامل (٢٠ يونيو – ٢٠ يوليو ٢٠٢٦)، عبر ثماني ورش تطبيقية بإجمالي ١٦ ساعة تدريبية، نقلت المشاركين من أساسيات الذكاء الاصطناعي إلى تطبيقه العملي في الدراسة والعمل والمشاريع.',
            'certificate_number'  => ($cfg['code_prefix'] ?? 'EMC') . '-WS1-' . now()->year . '-XXXX',
        ];

        $bladePath = $cfg['blade_path'] ?? 'certificates.templates.default';
        $html = view($bladePath, compact('cfg', 'data'))->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * PUT /certificates/templates/{template}/designer
     * Save designer settings to the template.
     */
    public function saveDesigner(\Illuminate\Http\Request $request, CertificateTemplate $template): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'designer_mode'          => 'sometimes|boolean',
            'blade_path'             => 'sometimes|string|max:200',
            'learning_path_name_ar'  => 'nullable|string|max:255',
            'learning_path_name_en'  => 'nullable|string|max:255',
            'org_name_en'            => 'nullable|string|max:255',
            'org_name_ar'            => 'nullable|string|max:255',
            'org2_name_en'           => 'nullable|string|max:255',
            'org2_name_ar'           => 'nullable|string|max:255',
            'logo_primary'           => 'nullable|string|max:500',
            'logo_secondary'         => 'nullable|string|max:500',
            'font_arabic'            => 'nullable|string|max:100',
            'font_english'           => 'nullable|string|max:100',
            'color_primary'          => 'nullable|string|max:20',
            'color_secondary'        => 'nullable|string|max:20',
            'color_accent'           => 'nullable|string|max:20',
            'color_text'             => 'nullable|string|max:20',
            'show_admin_signature'   => 'sometimes|boolean',
            'show_program_signature' => 'sometimes|boolean',
            'show_official_stamp'    => 'sometimes|boolean',
            'admin_sig_image'        => 'nullable|string|max:500',
            'admin_sig_name_en'      => 'nullable|string|max:255',
            'admin_sig_name_ar'      => 'nullable|string|max:255',
            'admin_sig_title_en'     => 'nullable|string|max:255',
            'admin_sig_title_ar'     => 'nullable|string|max:255',
            'program_sig_image'      => 'nullable|string|max:500',
            'program_sig_name_en'    => 'nullable|string|max:255',
            'program_sig_name_ar'    => 'nullable|string|max:255',
            'program_sig_title_en'   => 'nullable|string|max:255',
            'program_sig_title_ar'   => 'nullable|string|max:255',
            'official_stamp_image'   => 'nullable|string|max:500',
            'paper_size'             => 'nullable|string|max:10',
            'orientation'            => 'nullable|in:landscape,portrait',
            'pdf_quality'            => 'nullable|in:standard,high,print',
            'margin_top'             => 'nullable|integer|min:0|max:50',
            'margin_bottom'          => 'nullable|integer|min:0|max:50',
            'margin_left'            => 'nullable|integer|min:0|max:50',
            'margin_right'           => 'nullable|integer|min:0|max:50',
            'bg_style'               => 'nullable|in:default,minimal,ornate,none',
            'border_style'           => 'nullable|in:default,none',
            'name'                   => 'sometimes|string|max:255',
        ]);

        $data['updated_by'] = $request->user()->id;

        $template->update($data);

        CertificateRenderService::flushTemplateCache($template->id);

        return response()->json([
            'success' => true,
            'data'    => new CertificateTemplateResource($template->fresh()),
            'message' => 'تم حفظ القالب.',
        ]);
    }

    /**
     * POST /certificates/templates/{template}/upload-asset
     * Upload logo, signature, or stamp image.
     */
    public function uploadAsset(\Illuminate\Http\Request $request, CertificateTemplate $template): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'file'  => 'required|file|mimes:jpeg,jpg,png,svg,webp|max:2048',
            'field' => 'required|in:logo_primary,logo_secondary,admin_sig_image,program_sig_image,official_stamp_image',
        ]);

        $path = $request->file('file')->store(
            "certificates/templates/{$template->id}",
            'public'
        );

        $field = $request->input('field');
        $template->update([$field => $path]);

        CertificateRenderService::flushTemplateCache($template->id);

        return response()->json([
            'success' => true,
            'path'    => $path,
            'url'     => \Illuminate\Support\Facades\Storage::url($path),
        ]);
    }

    public function preview(CertificateTemplate $template): \Illuminate\Http\Response
    {
        if ($template->designer_mode) {
            // Render from Blade designer
            $cfg = app(\App\Services\CertificateGeneratorService::class)
                ->buildTemplateConfig($template, forPdf: false);

            $lpNameAr = $cfg['learning_path_name_ar'] ?? 'المعسكر الصيفي ٢٠٢٦';
            $lpNameEn = $cfg['learning_path_name_en'] ?? 'AI Summer Camp 2026';

            $data = [
                'participant_name'    => '[ Participant Name / اسم المشارك ]',
                'camp_badge_en'       => "{$lpNameEn} · Workshop 1",
                'camp_badge_ar'       => "{$lpNameAr} · الورشة الأولى",
                'presenter_en'        => 'THIS CERTIFICATE IS PRESENTED TO',
                'presenter_ar'        => "تشهد إدارة {$lpNameAr} بأن",
                'workshop_ordinal_en' => 'first',
                'workshop_ordinal_ar' => 'الأولى',
                'workshop_number_ar'  => 'الورشة الأولى',
                'course_title_en'     => 'Introduction to Artificial Intelligence – The Complete Map',
                'course_title_ar'     => 'مُدخل إلى الذكاء الاصطناعي – الخريطة الكاملة',
                'date_en'             => 'Saturday, 20 June 2026',
                'date_ar'             => 'السبت ٢٠ يونيو ٢٠٢٦',
                'hours_en'            => '2 Training Hours · Online',
                'hours_ar'            => 'ساعتان تدريبيتان · عن بُعد',
                'description_en'      => 'This certificate is awarded as part of the <strong>EMC Intensive Summer Program</strong>, guiding participants from the foundations of AI to its practical application.',
                'description_ar'      => 'يُمنح هذه الشهادة ضمن برنامج المعسكر الصيفي المكثف، الذي نقل المشاركين من أساسيات الذكاء الاصطناعي إلى تطبيقه العملي.',
                'certificate_number'  => ($template->code_prefix ?? 'EMC') . '-WS1-' . now()->year . '-XXXX',
            ];

            $html = view($template->getBladePath(), compact('cfg', 'data'))->render();
            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        // Legacy: replace placeholders in html_template
        $sampleData = [
            'student_name'      => 'محمد أحمد العلي',
            'course_name'       => 'مقدمة في البرمجة',
            'certificate_code'  => $template->code_prefix . '-' . date('Y') . '-00001',
            'issue_date'        => now()->format('Y/m/d'),
            'title'             => 'شهادة إتمام دورة',
            'cert_type_label'   => 'إتمام الدورة',
            'body_text'         => 'وذلك بإتمام جميع متطلبات الدورة بنجاح',
            'signature_1_name'  => 'مدير البرنامج',
            'signature_1_title' => 'Program Director',
            'signature_2_name'  => 'الرئيس التنفيذي',
            'signature_2_title' => 'CEO',
            'verification_url'  => url('/certificates/verify/SAMPLE-CODE'),
        ];

        $html = $template->html_template;
        foreach ($sampleData as $key => $value) {
            $html = str_replace('{{'.$key.'}}', $value, $html);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
