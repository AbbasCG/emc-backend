<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAmbassadorApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $hasOrganizedEvents = filter_var($this->input('has_organized_events', false), FILTER_VALIDATE_BOOLEAN);

        return [
            // ── Step 1: Personal Information ─────────────────────────────────
            'full_name'        => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\s\-\.\']+$/u'],
            'email'            => ['required', 'email:rfc,dns', 'max:255'],
            'mobile_phone'     => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'nationality'      => ['required', 'string', 'max:100'],
            'gender'           => ['required', 'string', 'in:male,female'],
            'date_of_birth'    => ['nullable', 'date', 'before:today', 'after:1950-01-01'],
            'country'          => ['required', 'string', 'max:100'],
            'city'             => ['required', 'string', 'max:100'],

            // ── Step 2: University Information ───────────────────────────────
            'university_name'          => ['required', 'string', 'max:150'],
            'university_type'          => ['nullable', 'string', 'in:public,private,international,other'],
            'faculty'                  => ['nullable', 'string', 'max:100'],
            'major'                    => ['nullable', 'string', 'max:100'],
            'study_year'               => ['nullable', 'string', 'max:30'],
            'student_number'           => ['nullable', 'string', 'max:60'],
            'student_status'           => ['nullable', 'string', 'in:student,graduate'],
            'expected_graduation_date' => ['nullable', 'date', 'before:' . now()->addYears(10)->toDateString()],

            // ── Step 3: Ambassador Information ───────────────────────────────
            'motivation_why'     => ['required', 'string', 'min:50', 'max:2000'],
            'contribution_what'  => ['required', 'string', 'min:50', 'max:2000'],
            'expected_gain'      => ['required', 'string', 'min:20', 'max:2000'],
            'biggest_achievement' => ['nullable', 'string', 'max:2000'],
            'biggest_challenge'   => ['nullable', 'string', 'max:2000'],
            'has_volunteer_experience'           => ['nullable', 'boolean'],
            'has_event_organization_experience'  => ['nullable', 'boolean'],
            'has_teaching_experience'            => ['nullable', 'boolean'],
            'has_student_club_involvement'       => ['nullable', 'boolean'],
            'social_instagram'  => ['nullable', 'url', 'max:255'],
            'social_linkedin'   => ['nullable', 'url', 'max:255'],
            'social_facebook'   => ['nullable', 'url', 'max:255'],
            'social_tiktok'     => ['nullable', 'url', 'max:255'],
            'social_github'     => ['nullable', 'url', 'max:255'],
            'website_url'       => ['nullable', 'url', 'max:255'],
            'followers_count'   => ['nullable', 'integer', 'min:0', 'max:100000000'],

            // ── Step 4: Interests ─────────────────────────────────────────────
            'interests'   => ['nullable', 'array', 'max:20'],
            'interests.*' => ['string', 'max:80'],

            // ── Step 5: Skills ────────────────────────────────────────────────
            'skills'   => ['nullable', 'array', 'max:20'],
            'skills.*' => ['string', 'max:80'],

            // ── Step 6: Leadership ────────────────────────────────────────────
            'has_led_team'               => ['nullable', 'boolean'],
            'has_organized_events'       => ['nullable', 'boolean'],
            'events_attendees_count'     => [$hasOrganizedEvents ? 'required' : 'nullable', 'integer', 'min:0', 'max:1000000'],
            'has_represented_university' => ['nullable', 'boolean'],

            // ── Step 7: Availability ──────────────────────────────────────────
            'weekly_hours_available'       => ['nullable', 'integer', 'min:1', 'max:168'],
            'can_attend_university_events' => ['nullable', 'boolean'],
            'can_represent_emc_outside'    => ['nullable', 'boolean'],
            'can_travel'                   => ['nullable', 'boolean'],
            'owns_laptop'                  => ['nullable', 'boolean'],
            'has_stable_internet'          => ['nullable', 'boolean'],
            'can_attend_weekly_meetings'   => ['nullable', 'boolean'],

            // ── Step 8: Volunteer Experience Types ────────────────────────────
            'volunteer_experience_types'   => ['nullable', 'array', 'max:15'],
            'volunteer_experience_types.*' => ['string', 'max:80'],

            // ── Step 9: Certifications ────────────────────────────────────────
            'certifications'   => ['nullable', 'array', 'max:20'],
            'certifications.*' => ['string', 'max:80'],

            // ── Step 10: Uploads ──────────────────────────────────────────────
            'cv_file'          => ['required', 'file', 'mimes:pdf', 'max:5120'],
            'student_id_file'  => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'photo_file'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'certificate_files'   => ['nullable', 'array', 'max:10'],
            'certificate_files.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],

            // Terms
            'agree_terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            // Step 1
            'full_name.required'       => 'الاسم الكامل مطلوب.',
            'full_name.min'            => 'الاسم قصير جداً.',
            'full_name.regex'          => 'الاسم يجب أن يحتوي على أحرف فقط.',
            'email.required'           => 'البريد الإلكتروني مطلوب.',
            'email.email'              => 'البريد الإلكتروني غير صحيح.',
            'mobile_phone.required'    => 'رقم الهاتف مطلوب.',
            'mobile_phone.regex'       => 'رقم الهاتف غير صالح (مثال: +966501234567).',
            'nationality.required'     => 'الجنسية مطلوبة.',
            'gender.required'          => 'الجنس مطلوب.',
            'gender.in'                => 'القيمة المختارة للجنس غير صالحة.',
            'country.required'         => 'الدولة مطلوبة.',
            'city.required'            => 'المدينة مطلوبة.',
            // Step 2
            'university_name.required' => 'اسم الجامعة مطلوب.',
            'university_type.in'       => 'نوع الجامعة غير صالح.',
            'student_status.in'        => 'حالة الطالب غير صالحة.',
            'expected_graduation_date.before' => 'تاريخ التخرج المتوقع غير منطقي، يرجى إدخال تاريخ أقرب.',
            // Step 3
            'motivation_why.required'    => 'حقل الدوافع مطلوب.',
            'motivation_why.min'         => 'يجب أن تكتب 50 حرفاً على الأقل في حقل الدوافع.',
            'contribution_what.required' => 'حقل المساهمة مطلوب.',
            'contribution_what.min'      => 'يجب أن تكتب 50 حرفاً على الأقل في حقل المساهمة.',
            'expected_gain.required'     => 'حقل التوقعات مطلوب.',
            'expected_gain.min'          => 'يجب أن تكتب 20 حرفاً على الأقل في حقل التوقعات.',
            // Step 6
            'events_attendees_count.required' => 'عدد الحضور مطلوب عند تنظيم فعاليات.',
            'events_attendees_count.integer'  => 'عدد الحضور يجب أن يكون رقماً صحيحاً.',
            // Step 7
            'weekly_hours_available.integer'  => 'الساعات الأسبوعية يجب أن تكون رقماً صحيحاً.',
            'weekly_hours_available.min'      => 'الحد الأدنى للساعات هو 1.',
            'weekly_hours_available.max'      => 'الحد الأقصى للساعات هو 168 أسبوعياً.',
            // Step 10
            'cv_file.required' => 'السيرة الذاتية (PDF) مطلوبة.',
            'cv_file.mimes'    => 'يُقبل ملف PDF فقط للسيرة الذاتية.',
            'cv_file.max'      => 'حجم ملف السيرة الذاتية يجب ألا يتجاوز 5 ميغابايت.',
            'photo_file.mimes' => 'الصورة الشخصية: يُقبل JPG أو PNG أو WEBP فقط.',
            'photo_file.max'   => 'حجم الصورة الشخصية يجب ألا يتجاوز 2 ميغابايت.',
            // Step 11
            'agree_terms.accepted' => 'يجب الموافقة على الشروط والأحكام.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Sanitize text fields: strip HTML tags, trim, collapse spaces
        $textFields = [
            'full_name', 'nationality', 'country', 'city', 'university_name', 'faculty', 'major',
            'student_number', 'motivation_why', 'contribution_what', 'expected_gain',
            'biggest_achievement', 'biggest_challenge',
        ];

        $sanitized = [];
        foreach ($textFields as $field) {
            if ($this->filled($field)) {
                $sanitized[$field] = preg_replace('/\s+/', ' ', trim(strip_tags($this->input($field))));
            }
        }

        // Normalize boolean fields from "0"/"1"/true/false form values
        $boolFields = [
            'has_volunteer_experience', 'has_event_organization_experience',
            'has_teaching_experience', 'has_student_club_involvement',
            'has_led_team', 'has_organized_events', 'has_represented_university',
            'can_attend_university_events', 'can_represent_emc_outside', 'can_travel',
            'owns_laptop', 'has_stable_internet', 'can_attend_weekly_meetings',
        ];
        foreach ($boolFields as $field) {
            $sanitized[$field] = filter_var($this->input($field, false), FILTER_VALIDATE_BOOLEAN);
        }

        $this->merge($sanitized);
    }
}
