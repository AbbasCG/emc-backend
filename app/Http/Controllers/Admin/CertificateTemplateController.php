<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateTemplateResource;
use App\Models\CertificateTemplate;
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
            'html_template'   => 'required|string',
            'css_template'    => 'nullable|string',
            'config_json'     => 'nullable|array',
            'code_prefix'     => 'nullable|string|max:20',
            'is_active'       => 'nullable|boolean',
        ]);

        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $template = CertificateTemplate::create($data);

        return response()->json([
            'success' => true,
            'data'    => new CertificateTemplateResource($template),
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

        return response()->json([
            'success' => true,
            'data'    => new CertificateTemplateResource($template->fresh(['createdBy', 'updatedBy'])),
        ]);
    }

    public function destroy(CertificateTemplate $template): \Illuminate\Http\JsonResponse
    {
        $template->delete();

        return response()->json(['success' => true, 'message' => 'Template deleted.']);
    }

    public function toggleActive(CertificateTemplate $template): \Illuminate\Http\JsonResponse
    {
        $template->update(['is_active' => ! $template->is_active]);

        return response()->json([
            'success'   => true,
            'message'   => $template->is_active ? 'Template activated.' : 'Template deactivated.',
            'is_active' => $template->is_active,
        ]);
    }

    public function preview(CertificateTemplate $template): \Illuminate\Http\Response
    {
        // Build a sample certificate to preview
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
