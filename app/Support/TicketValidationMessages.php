<?php

namespace App\Support;

/**
 * Arabic validation messages for the ticket action endpoints, so no English framework message ever reaches the UI.
 *
 * Merge it UNDER an endpoint's own, more specific messages: `[...TicketValidationMessages::all(), 'x.required' => '...']`.
 */
class TicketValidationMessages
{
    /** @return array<string, string> */
    public static function all(): array
    {
        $short = 'قيمة غير صالحة.';

        return [
            // action / bulk
            'action.required' => 'حدّد نوع العملية.',
            'action.in' => 'نوع العملية غير صالح.',
            'ticket_ids.required' => 'اختر تذكرة واحدة على الأقل.',
            'ticket_ids.array' => 'قائمة التذاكر غير صالحة.',
            'ticket_ids.min' => 'اختر تذكرة واحدة على الأقل.',
            'ticket_ids.*.integer' => 'معرّف تذكرة غير صالح.',
            'ticket_ids.*.min' => 'معرّف تذكرة غير صالح.',
            'ticket_ids.*.distinct' => 'تذكرة مكرَّرة في القائمة.',

            // submission (store)
            'title.required' => 'عنوان التذكرة مطلوب.',
            'title.string' => 'عنوان التذكرة غير صالح.',
            'title.max' => 'عنوان التذكرة طويل جدًا؛ الحد الأقصى 255 حرفًا.',
            'description.required' => 'وصف التذكرة مطلوب.',
            'description.string' => 'وصف التذكرة غير صالح.',
            'ticket_category.required' => 'اختر نوع التذكرة (مشكلة أو مقترح).',
            'ticket_category.in' => 'نوع التذكرة غير صالح.',
            'department_id.required' => 'اختر الإدارة المعنية.',
            'department_id.exists' => 'الإدارة المحددة غير موجودة.',
            'created_by_name.required' => 'اسم مقدّم التذكرة مطلوب.',
            'created_by_name.string' => 'اسم مقدّم التذكرة غير صالح.',
            'created_by_name.max' => 'اسم مقدّم التذكرة طويل جدًا؛ الحد الأقصى 150 حرفًا.',
            'created_by_email.email' => 'البريد الإلكتروني غير صالح.',
            'created_by_email.max' => 'البريد الإلكتروني طويل جدًا؛ الحد الأقصى 150 حرفًا.',
            'attachments.*.file' => 'المرفق غير صالح.',
            'attachments.*.mimes' => 'نوع المرفق غير مسموح.',
            'attachments.*.max' => 'المرفق أكبر من الحد المسموح.',
            'attachments.*.uploaded' => 'تعذّر رفع المرفق.',

            // routing
            'assigned_to_id.required' => 'اختر المكلف.',
            'assigned_to_id.integer' => 'المكلف غير صالح.',
            'assigned_to_id.exists' => 'المستخدم المحدد غير موجود.',
            'unit_id.integer' => 'الوحدة غير صالحة.',
            'unit_id.exists' => 'الوحدة المحددة غير موجودة.',
            'sla_hours.required' => 'حدّد المهلة الزمنية للـ SLA.',
            'sla_hours.integer' => 'المهلة الزمنية يجب أن تكون عددًا صحيحًا من الساعات.',
            'sla_hours.min' => 'المهلة الزمنية لا تقل عن ساعة واحدة.',
            'sla_hours.max' => 'المهلة الزمنية لا تتجاوز 720 ساعة.',
            'priority.required' => 'الأولوية مطلوبة.',
            'priority.in' => 'الأولوية غير صالحة.',

            // free text
            'reason.string' => 'السبب غير صالح.',
            'reason.max' => 'السبب طويل جدًا؛ الحد الأقصى 500 حرف.',
            'internal_notes.string' => 'الملاحظات الداخلية غير صالحة.',
            'comment.required' => 'نص التعقيب مطلوب.',
            'comment.string' => 'نص التعقيب غير صالح.',
            'comment.max' => 'التعقيب طويل جدًا؛ الحد الأقصى 2000 حرف.',
            'is_internal.boolean' => $short,

            // decisions
            'admin_rejection_reason.required' => 'حقل سبب الرفض إجباري عند رفض التذكرة من قبل الإدارة',
            'admin_rejection_reason.string' => 'سبب الرفض غير صالح.',
            'admin_rejection_reason.min' => 'يجب كتابة سبب الرفض بتفصيل لا يقل عن 5 حروف',
            'assignee_rejection_reason.required' => 'يلزم تدوين مبررات اعتذار/رفض استلام المهمة',
            'assignee_rejection_reason.string' => 'مبررات الاعتذار غير صالحة.',
            'assignee_rejection_reason.min' => 'مبررات الاعتذار قصيرة؛ اكتب 5 أحرف على الأقل.',
            'assignee_rejection_reason.max' => 'مبررات الاعتذار طويلة جدًا؛ الحد الأقصى 1000 حرف.',

            // completion
            'resolution_type.required' => 'اختر نتيجة الإغلاق (تم الحل أو تعذر الحل).',
            'resolution_type.in' => 'نتيجة الإغلاق غير صالحة.',
            'resolution_summary.required_if' => 'حقل (ماذا تم لحل المشكلة) إجباري عند اختيار "تم الحل"',
            'resolution_summary.string' => 'ملخص الحل غير صالح.',
            'resolution_summary.min' => 'ملخص الحل قصير؛ اكتب 5 أحرف على الأقل.',
            'unresolved_reason.required_if' => 'حقل (أسباب عدم التمكن من الحل والعوائق) إجباري عند اختيار "تعذر الحل"',
            'unresolved_reason.string' => 'سبب تعذر الحل غير صالح.',
            'unresolved_reason.min' => 'سبب تعذر الحل قصير؛ اكتب 5 أحرف على الأقل.',
            'proof_files.*.file' => 'ملف الإثبات غير صالح.',
            'proof_files.*.mimes' => 'نوع ملف الإثبات غير مسموح (صور، فيديو mp4/mov أو PDF).',
            'proof_files.*.max' => 'ملف الإثبات أكبر من الحد المسموح (50 ميغابايت).',
            'proof_files.*.uploaded' => 'تعذّر رفع ملف الإثبات.',
        ];
    }
}
