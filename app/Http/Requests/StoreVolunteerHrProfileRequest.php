<?php

namespace App\Http\Requests;

use App\Models\VolunteerHrProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVolunteerHrProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $existingId = $this->route('volunteerHrProfile')?->id;

        return [
            'full_name'   => 'required|string|max:255',
            'email'       => 'required|email|max:255',
            // Canonical value is dialCode+local, built the same way as signup
            // (e.g. "+31612345678") — the frontend's shared CountrySelector/
            // PhoneInput are responsible for that concatenation.
            'phone'               => 'required|string|max:30',
            'phone_country_code'  => 'nullable|string|max:10',
            'country'             => 'nullable|string|max:100',
            'country_code'        => 'nullable|string|max:5',
            'city'        => 'nullable|string|max:100',
            'date_of_birth' => 'nullable|date|before:today',
            'gender'      => 'nullable|string|max:20',
            'nationality' => 'nullable|string|max:100',

            'department_id'   => ['required', Rule::exists('departments', 'id')->where('is_active', true)],
            'job_title'       => 'required|string|max:255',
            // 'canonical' (chosen from the department's job-title list — validated
            // below to actually belong to that department) or 'custom' (free text,
            // matches the pre-existing "أخرى" behavior, not FK-checked).
            'job_title_source' => 'nullable|string|in:canonical,custom',
            'employment_type' => 'nullable|string|max:50',
            'join_date'       => 'required|date',
            'availability'    => 'nullable|string|max:100',
            'weekly_hours'    => 'nullable|integer|min:0|max:168',

            'skills'      => 'nullable|string',
            'languages'   => 'nullable|array',
            'languages.*' => 'string|max:50|distinct',
            'education'   => 'nullable|string',
            'university_specialization' => 'nullable|string|max:255',
            'experience'  => 'nullable|string',
            'motivation'  => 'nullable|string',
            'professional_bio' => ['nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                if ($value !== null && strip_tags($value) !== $value) {
                    $fail('لا يُسمح بإدخال وسوم HTML في هذا الحقل.');
                }
            }],

            'cv_file'        => ($existingId ? 'nullable' : 'required') . '|file|mimes:pdf,doc,docx|max:5120',
            'profile_photo'  => 'nullable|file|image|max:3072',
            'linkedin_url'   => 'nullable|url|max:255',
            'portfolio_url'  => 'nullable|url|max:255',

            // Explicit choice required on every submission — null/omitted is
            // rejected, not silently defaulted, so consent is never inferred.
            'photo_publication_consent'    => 'required|boolean',
            'professional_profile_consent' => 'required|boolean',

            'confirmed' => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'cv_file.required'         => 'يرجى رفع السيرة الذاتية',
            'cv_file.mimes'            => 'نوع الملف غير مدعوم',
            'cv_file.max'              => 'حجم الملف أكبر من الحد المسموح',
            'job_title.required'       => 'يرجى اختيار المسمى الوظيفي',
            'department_id.required'   => 'يرجى اختيار القسم',
            'department_id.exists'     => 'القسم المحدد غير صالح',
            'languages.*.distinct'     => 'لا يمكن اختيار نفس اللغة أكثر من مرة',
            'confirmed.accepted'       => 'يجب تأكيد صحة المعلومات المدخلة',
            'professional_bio.max'     => 'النبذة التعريفية يجب ألا تتجاوز 500 حرف',
            'photo_publication_consent.required'    => 'يرجى تحديد موافقتك على استخدام الصورة الشخصية',
            'professional_profile_consent.required' => 'يرجى تحديد موافقتك على عرض البيانات المهنية',
        ];
    }

    /** Cross-field check: a 'canonical' job title must actually belong to the selected department. */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            // Only enforce the department/job-title match when the caller
            // explicitly opted into the canonical dropdown — absent/older
            // callers (or the free-text "أخرى" flow) are not FK-checked,
            // matching the pre-existing behavior this replaces.
            $source = $this->input('job_title_source', 'custom');
            if ($source !== 'canonical') {
                return;
            }
            $departmentId = $this->input('department_id');
            $jobTitle     = $this->input('job_title');
            if (! $departmentId || ! $jobTitle) {
                return;
            }
            $belongs = \App\Models\JobTitle::where('department_id', $departmentId)
                ->where('name_ar', $jobTitle)
                ->where('is_active', true)
                ->exists();
            if (! $belongs) {
                $validator->errors()->add('job_title', 'المسمى الوظيفي المحدد لا يتبع القسم المختار');
            }
        });
    }
}
