<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePartnershipRequestRequest extends FormRequest
{
    /** Organization types — kept as English slugs (DB column has no enum constraint; this `in:` list is the only enforcement). */
    public const ORGANIZATION_TYPES = [
        'academic', 'university_college', 'school', 'company',
        'nonprofit', 'government', 'community', 'other',
    ];

    /** What kind of partnership is being proposed — distinct from organization type. */
    public const PARTNERSHIP_TYPES = [
        'education_partnership', 'courses_workshops', 'sponsorship', 'media_marketing',
        'trainers_experts', 'tech_partnership', 'funding_support', 'other',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_name'            => ['required', 'string', 'min:2', 'max:255'],
            'type'                    => ['required', 'string', 'in:' . implode(',', self::ORGANIZATION_TYPES)],
            'type_other'              => ['required_if:type,other', 'nullable', 'string', 'max:255'],
            'contact_name'            => ['required', 'string', 'min:2', 'max:255'],
            'email'                   => ['required', 'email:rfc', 'max:255'],
            'phone'                   => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'country'                 => ['required', 'string', 'max:100'],
            'city'                    => ['nullable', 'string', 'max:100'],
            'website'                 => ['nullable', 'url', 'max:255'],
            'partnership_type'        => ['required', 'string', 'in:' . implode(',', self::PARTNERSHIP_TYPES)],
            'partnership_type_other'  => ['required_if:partnership_type,other', 'nullable', 'string', 'max:255'],
            'message'                 => ['required', 'string', 'min:10', 'max:5000'],
            'privacy_accepted'        => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'partner_name.required'   => 'اسم المؤسسة مطلوب.',
            'partner_name.min'        => 'اسم المؤسسة قصير جداً.',
            'type.required'           => 'نوع المؤسسة مطلوب.',
            'type.in'                 => 'نوع المؤسسة المختار غير صالح.',
            'type_other.required_if'  => 'يرجى توضيح نوع المؤسسة.',
            'contact_name.required'   => 'اسم الشخص المسؤول مطلوب.',
            'contact_name.min'        => 'اسم الشخص المسؤول قصير جداً.',
            'email.required'          => 'البريد الإلكتروني مطلوب.',
            'email.email'             => 'صيغة البريد الإلكتروني غير صحيحة.',
            'phone.required'          => 'رقم الجوال مطلوب.',
            'phone.regex'             => 'رقم الجوال غير صالح — أدخل رقماً دولياً صحيحاً (مثال: ‎+31612345678).',
            'country.required'        => 'الدولة مطلوبة.',
            'website.url'             => 'رابط الموقع الإلكتروني غير صحيح.',
            'partnership_type.required' => 'نوع الشراكة المطلوبة مطلوب.',
            'partnership_type.in'     => 'نوع الشراكة المختار غير صالح.',
            'partnership_type_other.required_if' => 'يرجى توضيح نوع الشراكة.',
            'message.required'       => 'الرسالة أو المقترح مطلوب.',
            'message.min'            => 'يرجى كتابة 10 أحرف على الأقل لوصف طلبكم.',
            'message.max'            => 'الرسالة طويلة جداً (الحد الأقصى 5000 حرف).',
            'privacy_accepted.accepted' => 'يجب الموافقة على سياسة الخصوصية لإرسال الطلب.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $textFields = ['partner_name', 'contact_name', 'country', 'city', 'type_other', 'partnership_type_other', 'message'];
        $sanitized = [];
        foreach ($textFields as $field) {
            if ($this->filled($field)) {
                $sanitized[$field] = preg_replace('/\s+/', ' ', trim(strip_tags((string) $this->input($field))));
            } else {
                // Empty optional strings become null instead of "" so they
                // don't get stored as blank values or trip `nullable` rules
                // inconsistently.
                $sanitized[$field] = null;
            }
        }

        if ($this->filled('email')) {
            $sanitized['email'] = mb_strtolower(trim((string) $this->input('email')));
        }

        if ($this->filled('website')) {
            $website = trim((string) $this->input('website'));
            // A bare "example.com" is a common, valid user input but fails
            // the `url` rule without a scheme — add one rather than reject it.
            if ($website !== '' && !preg_match('#^https?://#i', $website)) {
                $website = 'https://' . $website;
            }
            $sanitized['website'] = $website !== '' ? $website : null;
        }

        if ($this->filled('phone')) {
            // Normalize common spacing/punctuation before the regex check —
            // "06 81234567", "+31 (6) 812-34567" etc. should not be rejected
            // purely for formatting. `00` international prefix → `+`.
            $phone = preg_replace('/[\s\-\(\)]/', '', (string) $this->input('phone'));
            if (str_starts_with($phone, '00')) {
                $phone = '+' . substr($phone, 2);
            }
            $sanitized['phone'] = $phone;
        }

        $this->merge($sanitized);
    }
}
