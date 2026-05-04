<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkshopRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'requester_name' => 'required|string|max:255',
            'requester_email' => 'required|email|max:255',
            'requester_phone' => 'nullable|string|max:50',
            'requester_department' => 'nullable|string|max:255',
            'program_name' => 'required|string|max:255',
            'speaker_name' => 'required|string|max:255',
            'speaker_job_title' => 'required|string|max:255',
            'speaker_photo' => 'required|image|max:10240',
            'topics' => 'required|string|max:5000',
            'categories' => 'required|array|min:1',
            'categories.*' => 'required|string|max:255',
            'target_audience' => 'required|string|max:2000',
            'proposed_date' => 'required|date',
            'proposed_time' => 'required|string|max:255',
            'location_types' => 'required|array|min:1',
            'location_types.*' => 'required|string|max:255',
            'price_type' => 'required|in:free,paid',
            'price_amount' => 'required_if:price_type,paid|nullable|numeric|min:0',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'requester_name.required' => 'اسم المتقدم مطلوب',
            'requester_name.string' => 'اسم المتقدم يجب أن يكون نصاً',
            'requester_name.max' => 'اسم المتقدم يجب ألا يتجاوز 255 حرف',

            'requester_email.required' => 'البريد الإلكتروني مطلوب',
            'requester_email.email' => 'البريد الإلكتروني غير صحيح',
            'requester_email.max' => 'البريد الإلكتروني يجب ألا يتجاوز 255 حرف',

            'requester_phone.string' => 'رقم الهاتف يجب أن يكون نصاً',
            'requester_phone.max' => 'رقم الهاتف يجب ألا يتجاوز 50 حرف',

            'requester_department.string' => 'القسم يجب أن يكون نصاً',
            'requester_department.max' => 'القسم يجب ألا يتجاوز 255 حرف',

            'program_name.required' => 'اسم البرنامج مطلوب',
            'program_name.string' => 'اسم البرنامج يجب أن يكون نصاً',
            'program_name.max' => 'اسم البرنامج يجب ألا يتجاوز 255 حرف',

            'speaker_name.required' => 'اسم المتحدث مطلوب',
            'speaker_name.string' => 'اسم المتحدث يجب أن يكون نصاً',
            'speaker_name.max' => 'اسم المتحدث يجب ألا يتجاوز 255 حرف',

            'speaker_job_title.required' => 'المسمى الوظيفي للمتحدث مطلوب',
            'speaker_job_title.string' => 'المسمى الوظيفي يجب أن يكون نصاً',
            'speaker_job_title.max' => 'المسمى الوظيفي يجب ألا يتجاوز 255 حرف',

            'speaker_photo.required' => 'صورة المتحدث مطلوبة',
            'speaker_photo.image' => 'صورة المتحدث يجب أن تكون صورة',
            'speaker_photo.max' => 'حجم صورة المتحدث يجب ألا يتجاوز 10 ميجابايت',

            'topics.required' => 'محاور البرنامج مطلوبة',
            'topics.string' => 'محاور البرنامج يجب أن تكون نصاً',
            'topics.max' => 'محاور البرنامج يجب ألا تتجاوز 5000 حرف',

            'categories.required' => 'تصنيفات البرنامج مطلوبة',
            'categories.array' => 'تصنيفات البرنامج يجب أن تكون مصفوفة',
            'categories.min' => 'يجب اختيار تصنيف واحد على الأقل',
            'categories.*.required' => 'كل تصنيف مطلوب',
            'categories.*.string' => 'كل تصنيف يجب أن يكون نصاً',
            'categories.*.max' => 'كل تصنيف يجب ألا يتجاوز 255 حرف',

            'target_audience.required' => 'الفئة المستهدفة مطلوبة',
            'target_audience.string' => 'الفئة المستهدفة يجب أن تكون نصاً',
            'target_audience.max' => 'الفئة المستهدفة يجب ألا تتجاوز 2000 حرف',

            'proposed_date.required' => 'التاريخ المقترح مطلوب',
            'proposed_date.date' => 'التاريخ المقترح يجب أن يكون تاريخاً صحيحاً',

            'proposed_time.required' => 'التوقيت المقترح مطلوب',
            'proposed_time.string' => 'التوقيت المقترح يجب أن يكون نصاً',
            'proposed_time.max' => 'التوقيت المقترح يجب ألا يتجاوز 255 حرف',

            'location_types.required' => 'نوع المكان/المنصة مطلوب',
            'location_types.array' => 'نوع المكان/المنصة يجب أن تكون مصفوفة',
            'location_types.min' => 'يجب اختيار نوع مكان واحد على الأقل',
            'location_types.*.required' => 'كل نوع مكان مطلوب',
            'location_types.*.string' => 'كل نوع مكان يجب أن يكون نصاً',
            'location_types.*.max' => 'كل نوع مكان يجب ألا يتجاوز 255 حرف',

            'price_type.required' => 'نوع الرسوم مطلوب',
            'price_type.in' => 'نوع الرسوم يجب أن يكون مجاني أو مدفوع',

            'price_amount.required_if' => 'قيمة الرسوم مطلوبة للبرامج المدفوعة',
            'price_amount.numeric' => 'قيمة الرسوم يجب أن تكون رقماً',
            'price_amount.min' => 'قيمة الرسوم يجب أن تكون أكبر من أو تساوي صفر',
        ];
    }
}
