<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What HR may change in a volunteer's HR profile. An explicit whitelist: anything not listed here is ignored, so the review
 * workflow (status, reviewer, approval), the account link (user_id), the team link, the volunteer's own consents and statement of
 * motivation, and the uploaded files can never be changed through this form.
 *
 * Every field is `sometimes`: the screen sends the whole form, but a client sending only some fields changes only those.
 */
class UpdateVolunteerHrProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked by the controller (VolunteerHrProfilePolicy::hrUpdate)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name'                 => ['sometimes', 'required', 'string', 'max:255'],
            'email'                     => ['sometimes', 'required', 'email', 'max:255'],
            'phone'                     => ['sometimes', 'required', 'string', 'max:30'],
            'phone_country_code'        => ['sometimes', 'nullable', 'string', 'max:10'],
            'country'                   => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code'              => ['sometimes', 'nullable', 'string', 'max:5'],
            'city'                      => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_of_birth'             => ['sometimes', 'nullable', 'date', 'before:today'],
            // male/female, or the value already stored (rows from before the picker may hold another spelling: resending it is fine).
            'gender'                    => ['sometimes', 'nullable', Rule::in(array_values(array_filter(['male', 'female', $this->route('volunteerHrProfile')?->gender])))],
            'nationality'               => ['sometimes', 'nullable', 'string', 'max:100'],
            'department_id'             => ['sometimes', 'required', Rule::exists('departments', 'id')->where('is_active', true)],
            'job_title'                 => ['sometimes', 'required', 'string', 'max:255'],
            'employment_type'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'join_date'                 => ['sometimes', 'required', 'date'],
            'availability'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'weekly_hours'              => ['sometimes', 'nullable', 'integer', 'min:0', 'max:168'],
            'skills'                    => ['sometimes', 'nullable', 'string', 'max:2000'],
            'languages'                 => ['sometimes', 'nullable', 'array', 'max:20'],
            'languages.*'               => ['string', 'max:50', 'distinct'],
            'education'                 => ['sometimes', 'nullable', 'string', 'max:2000'],
            'university_specialization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'experience'                => ['sometimes', 'nullable', 'string', 'max:4000'],
            'professional_bio'          => ['sometimes', 'nullable', 'string', 'max:500'],
            'linkedin_url'              => ['sometimes', 'nullable', 'url', 'max:255'],
            'portfolio_url'             => ['sometimes', 'nullable', 'url', 'max:255'],
            'emergency_contact_name'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_phone'   => ['sometimes', 'nullable', 'string', 'max:30'],
            // The profile as the editor loaded it: a save from a stale copy is refused (409) instead of overwriting.
            'expected_updated_at'       => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'full_name.required'     => 'الاسم الكامل مطلوب.',
            'email.required'         => 'البريد الإلكتروني مطلوب.',
            'email.email'            => 'صيغة البريد الإلكتروني غير صحيحة.',
            'phone.required'         => 'رقم الهاتف مطلوب.',
            'phone.max'              => 'رقم الهاتف أطول من المسموح.',
            'date_of_birth.before'   => 'تاريخ الميلاد يجب أن يكون قبل اليوم.',
            'date_of_birth.date'     => 'تاريخ الميلاد غير صالح.',
            'gender.in'              => 'اختر الجنس من القائمة.',
            'department_id.required' => 'القسم مطلوب.',
            'department_id.exists'   => 'القسم المختار غير موجود أو غير نشط.',
            'job_title.required'     => 'المسمى الوظيفي مطلوب.',
            'join_date.required'     => 'تاريخ الانضمام مطلوب.',
            'join_date.date'         => 'تاريخ الانضمام غير صالح.',
            'weekly_hours.integer'   => 'عدد الساعات الأسبوعية يجب أن يكون رقماً صحيحاً.',
            'weekly_hours.max'       => 'عدد الساعات الأسبوعية لا يتجاوز 168.',
            'weekly_hours.min'       => 'عدد الساعات الأسبوعية لا يقل عن صفر.',
            'linkedin_url.url'       => 'رابط LinkedIn غير صالح.',
            'portfolio_url.url'      => 'رابط معرض الأعمال غير صالح.',
            'professional_bio.max'   => 'النبذة المهنية لا تتجاوز 500 حرف.',
            'languages.*.distinct'   => 'لا تكرر اللغة نفسها.',
            '*.max'                  => 'القيمة أطول من المسموح.',
        ];
    }
}
