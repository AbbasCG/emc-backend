<?php

namespace App\Http\Requests;

use App\Support\FakePaymentEnvironment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'exists:courses,id'],
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_provider' => ['nullable', 'in:stripe,paypal,fake'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (($this->input('payment_provider')) === 'fake' && ! FakePaymentEnvironment::allowed()) {
                $v->errors()->add(
                    'payment_provider',
                    'The fake payment provider is only available in the local application environment.'
                );
            }
        });
    }
}
