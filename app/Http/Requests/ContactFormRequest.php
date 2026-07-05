<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\s\'\-]+$/u'],
            'email'    => ['required', 'email', 'max:150'],
            'phone'    => ['nullable', 'string', 'regex:/^\+?[\d\s\-]+$/', 'max:20'],
            'category' => ['nullable', 'string', 'in:general,partnership,volunteer,tech,technical'],
            'type'     => ['nullable', 'string', 'in:general,partnership,volunteer,tech,technical'],
            'subject'  => ['required', 'string', 'min:3', 'max:150'],
            'message'  => ['required', 'string', 'min:10', 'max:2000'],
            '_honey'   => ['nullable', 'max:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'الاسم مطلوب.',
            'name.min'         => 'يجب أن يكون الاسم حرفين على الأقل.',
            'name.max'         => 'يجب ألا يتجاوز الاسم 100 حرف.',
            'name.regex'       => 'يجب أن يحتوي الاسم على أحرف فقط.',
            'email.required'   => 'البريد الإلكتروني مطلوب.',
            'email.email'      => 'يرجى إدخال بريد إلكتروني صحيح.',
            'email.max'        => 'البريد الإلكتروني طويل جداً.',
            'phone.regex'      => 'رقم الهاتف يجب أن يحتوي على أرقام فقط.',
            'phone.max'        => 'رقم الهاتف طويل جداً.',
            'category.in'      => 'نوع الطلب غير صحيح.',
            'type.in'          => 'نوع الطلب غير صحيح.',
            'subject.required' => 'الموضوع مطلوب.',
            'subject.min'      => 'يجب أن يكون الموضوع 3 أحرف على الأقل.',
            'subject.max'      => 'يجب ألا يتجاوز الموضوع 150 حرفاً.',
            'message.required' => 'الرسالة مطلوبة.',
            'message.min'      => 'يجب ألا تقل الرسالة عن 10 أحرف.',
            'message.max'      => 'يجب ألا تتجاوز الرسالة 2000 حرف.',
            '_honey.max'       => 'طلب مرفوض.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('name')) {
            $merge['name'] = trim(strip_tags((string) $this->input('name')));
        }
        if ($this->has('email')) {
            $merge['email'] = strtolower(trim((string) $this->input('email')));
        }
        if ($this->has('phone')) {
            $raw = trim((string) $this->input('phone', ''));
            $merge['phone'] = $raw !== '' ? preg_replace('/[^\d+\s\-]/', '', $raw) : null;
        }
        if ($this->has('subject')) {
            $merge['subject'] = trim(strip_tags((string) $this->input('subject')));
        }
        if ($this->has('message')) {
            $msg = strip_tags((string) $this->input('message', ''));
            $merge['message'] = trim(preg_replace('/[ \t]{2,}/', ' ', $msg) ?? $msg);
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }
}
