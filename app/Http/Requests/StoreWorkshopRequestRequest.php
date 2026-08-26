<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkshopRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'requester_name'       => 'required|string|max:255',
            'requester_email'      => 'required|email|max:255',
            'requester_phone'      => 'nullable|string|max:50',
            'requester_department' => 'nullable|string|max:255',

            'program_name'         => 'required|string|max:255',
            'speaker_name'         => 'required|string|max:255',
            'speaker_job_title'    => 'required|string|max:255',
            'speaker_photo'        => 'required|image|max:10240',

            'topics'               => 'required|string|max:5000',
            'categories'           => 'required|array|min:1',
            'categories.*'         => 'required|string|max:255',
            'target_audience'      => 'required|string|max:2000',

            // Three required date/time options
            'proposed_date_1'      => 'required|date',
            'proposed_time_1'      => 'required|string|max:20',
            'proposed_date_2'      => 'required|date',
            'proposed_time_2'      => 'required|string|max:20',
            'proposed_date_3'      => 'required|date',
            'proposed_time_3'      => 'required|string|max:20',

            // Legacy fields optional for backward compat
            'proposed_date'        => 'nullable|date',
            'proposed_time'        => 'nullable|string|max:255',

            'location_types'       => 'required|array|min:1',
            'location_types.*'     => 'required|string|max:255',
            'price_type'           => 'required|in:free,paid',
            'price_amount'         => 'required_if:price_type,paid|nullable|numeric|gt:0',
        ];
    }

    public function messages(): array
    {
        return [
            'requester_name.required'    => 'اسم المتقدم مطلوب',
            'requester_name.max'         => 'اسم المتقدم يجب ألا يتجاوز 255 حرف',
            'requester_email.required'   => 'البريد الإلكتروني مطلوب',
            'requester_email.email'      => 'البريد الإلكتروني غير صحيح',
            'requester_phone.max'        => 'رقم الهاتف يجب ألا يتجاوز 50 حرف',
            'requester_department.max'   => 'القسم يجب ألا يتجاوز 255 حرف',

            'program_name.required'      => 'اسم البرنامج مطلوب',
            'speaker_name.required'      => 'اسم المتحدث مطلوب',
            'speaker_job_title.required' => 'المسمى الوظيفي للمتحدث مطلوب',
            'speaker_photo.required'     => 'صورة المتحدث مطلوبة',
            'speaker_photo.image'        => 'يجب أن يكون الملف صورة',
            'speaker_photo.max'          => 'حجم الصورة يجب ألا يتجاوز 10 ميجابايت',

            'topics.required'            => 'محاور البرنامج مطلوبة',
            'categories.required'        => 'تصنيفات البرنامج مطلوبة',
            'categories.min'             => 'يجب اختيار تصنيف واحد على الأقل',
            'target_audience.required'   => 'الفئة المستهدفة مطلوبة',

            'proposed_date_1.required'   => 'التاريخ المقترح للخيار الأول مطلوب',
            'proposed_date_1.date'       => 'تاريخ الخيار الأول غير صحيح',
            'proposed_time_1.required'   => 'الوقت المقترح للخيار الأول مطلوب',
            'proposed_date_2.required'   => 'التاريخ المقترح للخيار الثاني مطلوب',
            'proposed_date_2.date'       => 'تاريخ الخيار الثاني غير صحيح',
            'proposed_time_2.required'   => 'الوقت المقترح للخيار الثاني مطلوب',
            'proposed_date_3.required'   => 'التاريخ المقترح للخيار الثالث مطلوب',
            'proposed_date_3.date'       => 'تاريخ الخيار الثالث غير صحيح',
            'proposed_time_3.required'   => 'الوقت المقترح للخيار الثالث مطلوب',

            'location_types.required'    => 'نوع المكان/المنصة مطلوب',
            'location_types.min'         => 'يجب اختيار نوع مكان واحد على الأقل',
            'price_type.required'        => 'نوع الرسوم مطلوب',
            'price_type.in'              => 'نوع الرسوم يجب أن يكون مجاني أو مدفوع',
            'price_amount.required_if'   => 'قيمة الرسوم مطلوبة للبرامج المدفوعة',
            'price_amount.numeric'       => 'قيمة الرسوم يجب أن تكون رقماً',
            'price_amount.gt'            => 'قيمة الرسوم يجب أن تكون أكبر من صفر',
        ];
    }
}
