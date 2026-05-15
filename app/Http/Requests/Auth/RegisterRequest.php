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
        ];
    }
}
