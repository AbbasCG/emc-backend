<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|max:255|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
            // Public registration is limited to student/teacher.
            // Admin accounts are created by seeder or existing admin (Phase 3+).
            'role'                  => 'nullable|in:student,teacher',

            // Keep public signup short. Profile details are completed later,
            // at the point where a certificate, payment or contact flow needs them.
            'country_code'          => 'nullable|string|max:5',
            'phone_country_code'    => 'nullable|string|max:10',
            'phone'                 => 'nullable|string|max:30',
            'city'                  => 'nullable|string|max:100',
            'gender'                => 'nullable|string|in:male,female',
            'how_did_you_hear_about_us' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                  => 'الاسم مطلوب',
            'email.required'                 => 'البريد الإلكتروني مطلوب',
            'email.email'                    => 'البريد الإلكتروني غير صالح',
            'email.unique'                   => 'البريد الإلكتروني مستخدم بالفعل',
            'password.required'              => 'كلمة المرور مطلوبة',
            'password.min'                   => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
            'password.confirmed'             => 'تأكيد كلمة المرور غير متطابق',
            'password_confirmation.required' => 'تأكيد كلمة المرور مطلوب',
            'role.in'                        => 'الدور يجب أن يكون student أو teacher',

            'country_code.required'          => 'رمز الدولة مطلوب',
            'country_code.max'               => 'رمز الدولة يجب ألا يتجاوز 5 أحرف',
            'phone_country_code.required'    => 'رمز هاتف الدولة مطلوب',
            'phone_country_code.max'         => 'رمز هاتف الدولة يجب ألا يتجاوز 10 أحرف',
            'phone.required'                 => 'رقم الهاتف مطلوب',
            'phone.max'                      => 'رقم الهاتف يجب ألا يتجاوز 30 رقماً',
            'city.required'                  => 'المدينة مطلوبة',
            'city.max'                       => 'اسم المدينة يجب ألا يتجاوز 100 حرف',
            'gender.required'                => 'الجنس مطلوب',
            'gender.in'                      => 'الجنس يجب أن يكون male أو female',
        ];
    }
}
