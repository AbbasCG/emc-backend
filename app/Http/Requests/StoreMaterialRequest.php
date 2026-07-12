<?php

namespace App\Http\Requests;

use App\Enums\MaterialType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\CourseMaterial::class) ?? false;
    }

    public function rules(): array
    {
        $type    = MaterialType::tryFrom($this->input('type', 'other')) ?? MaterialType::Other;
        $urlOnly = $type->isUrlOnly();
        $maxKb   = $type->maxSizeKb();
        $mimes   = implode(',', $type->allowedExtensions()) ?: 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,mp4,mp3,zip';

        return [
            'course_id'        => ['nullable', 'exists:courses,id'],
            'module_id'        => ['nullable', 'exists:course_modules,id'],
            'session_id'       => ['nullable', 'exists:lms_sessions,id'],
            'course_session_id'=> ['nullable', 'exists:course_sessions,id'],
            'title'            => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string', 'max:5000'],
            'type'             => ['required', 'string', Rule::in(MaterialType::values())],
            'sort_order'       => ['nullable', 'integer', 'min:0'],
            'is_visible'       => ['nullable', 'boolean'],

            'external_url' => $urlOnly
                ? ['required', 'url', 'max:2000']
                : ['nullable', 'url', 'max:2000'],

            'file' => $urlOnly
                ? ['nullable']
                : [
                    'required',
                    'file',
                    "max:{$maxKb}",
                    "mimes:{$mimes}",
                ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'  => 'يجب رفع ملف لهذا النوع من المواد.',
            'file.max'       => 'حجم الملف يتجاوز الحد المسموح به.',
            'file.mimes'     => 'نوع الملف غير مدعوم لهذا النوع من المواد.',
            'external_url.required' => 'الرابط مطلوب لهذا النوع من المواد.',
            'external_url.url'      => 'الرابط المدخل غير صالح.',
            'type.in'        => 'نوع المادة غير مدعوم.',
            'title.required' => 'عنوان المادة مطلوب.',
        ];
    }
}
