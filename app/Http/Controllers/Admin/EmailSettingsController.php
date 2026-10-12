<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\EmailSetting;
use App\Services\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailSettingsController extends Controller
{
    /**
     * Every entry here is a real EmailService::dispatch() key already gating
     * an actual mail send somewhere in the app (see NotificationChannelPolicy-
     * style audit trail) — this list only controls what shows up as a
     * toggle in the admin UI; EmailSetting::isEnabled() already treats any
     * key not yet in the table as enabled=true, so adding a key here never
     * changes existing behaviour until an admin explicitly flips it off.
     */
    private const LABELS = [
        // ── Users ─────────────────────────────────────────────────────────────
        'welcome'                            => ['label' => 'بريد الترحيب (تسجيل ذاتي)',      'description' => 'يُرسل للمستخدم عند إنشاء حسابه بنفسه لأول مرة',                          'category' => 'users'],
        'account_created'                    => ['label' => 'حساب جديد (إنشاء إداري)',        'description' => 'يُرسل للمستخدم عند إنشاء حسابه من قِبل مشرف مع كلمة مرور مؤقتة',           'category' => 'users'],
        'temporary_password'                 => ['label' => 'كلمة المرور المؤقتة',            'description' => 'يُرسل عند إرسال كلمة مرور مؤقتة للمستخدم',                                'category' => 'users'],
        'password_reset'                     => ['label' => 'إعادة تعيين كلمة المرور',        'description' => 'يُرسل للمستخدم عند طلب إعادة تعيين كلمة المرور',                          'category' => 'users'],
        // ── Courses ───────────────────────────────────────────────────────────
        'course_enrollment'                  => ['label' => 'تسجيل في دورة',                  'description' => 'يُرسل للطالب عند تسجيله في دورة تدريبية',                                'category' => 'courses'],
        'learning_path_enrollment'           => ['label' => 'تسجيل في مسار',                  'description' => 'يُرسل للطالب عند انضمامه لمسار تعليمي',                                  'category' => 'courses'],
        'course_completion'                  => ['label' => 'إتمام دورة',                     'description' => 'يُرسل للطالب عند اجتيازه دورة بالكامل',                                   'category' => 'courses'],
        'learning_path_completion'           => ['label' => 'إتمام مسار',                     'description' => 'يُرسل للطالب عند إكماله المسار التعليمي كاملاً',                          'category' => 'courses'],
        'assignment_submitted'               => ['label' => 'تسليم واجب (للمدرب)',            'description' => 'يُرسل للمدرب عندما يسلّم طالب واجبه',                                     'category' => 'courses'],
        'assignment_graded'                  => ['label' => 'تقييم واجب (للطالب)',            'description' => 'يُرسل للطالب بعد تقييم المدرب لواجبه',                                    'category' => 'courses'],
        'admin_new_registration'             => ['label' => 'تسجيل جديد (للإدارة)',           'description' => 'يُرسل للمشرفين عند كل تسجيل طالب جديد',                                   'category' => 'courses'],
        // ── Calendar ──────────────────────────────────────────────────────────
        'session_reminder'                   => ['label' => 'تذكير بالجلسة',                  'description' => 'يُرسل قبل 24 ساعة وساعة واحدة من موعد الجلسة',                            'category' => 'calendar'],
        // ── Payments ──────────────────────────────────────────────────────────
        'payment_confirmed'                  => ['label' => 'تأكيد الدفع',                    'description' => 'يُرسل للطالب عند تأكيد الدفع وإتمام التسجيل في الدورة',                   'category' => 'payments'],
        // ── Support ───────────────────────────────────────────────────────────
        'contact_confirmation'               => ['label' => 'تأكيد استلام نموذج التواصل',     'description' => 'يُرسل لمرسل نموذج التواصل تأكيداً باستلام رسالته مع رقم التذكرة',          'category' => 'support'],
        'contact_admin_notification'         => ['label' => 'إشعار الإدارة برسالة تواصل جديدة','description' => 'يُرسل لفريق الدعم عند ورود رسالة جديدة من نموذج التواصل',                  'category' => 'support'],
        'support_ticket_reply'               => ['label' => 'إرسال رد تذكرة الدعم للعميل',    'description' => 'يُرسل للزائر عند رد فريق الدعم على تذكرته من لوحة التحكم',                 'category' => 'support'],
        'ticket_resolved'                    => ['label' => 'حل بلاغ (لمقدّم البلاغ)',        'description' => 'يُرسل لمقدّم البلاغ عند تحويل بلاغه إلى «تم الحل»',                         'category' => 'support'],
        'suggestion_approved'                => ['label' => 'اعتماد مقترح (لمقدّم المقترح)',  'description' => 'يُرسل لمقدّم المقترح عند اعتماده من قبل الإدارة',                           'category' => 'support'],
        'suggestion_implemented'             => ['label' => 'إضافة مقترح إلى النظام',          'description' => 'يُرسل لمقدّم المقترح عند إضافة مقترحه إلى النظام',                          'category' => 'support'],
        // ── Applications ──────────────────────────────────────────────────────
        'volunteer_confirmation'             => ['label' => 'تأكيد طلب التطوع (للمتقدم)',     'description' => 'يُرسل لمقدم طلب التطوع تأكيداً باستلام طلبه',                             'category' => 'applications'],
        'volunteer_admin_notification'       => ['label' => 'طلب تطوع جديد (للإدارة)',        'description' => 'يُرسل للمشرفين وفريق الموارد البشرية عند ورود طلب تطوع جديد',              'category' => 'applications'],
        'workshop_request_confirmation'      => ['label' => 'تأكيد استلام طلب البرنامج',      'description' => 'يُرسل لمقدم الطلب عند استلام طلب البرنامج التدريبي',                      'category' => 'applications'],
        'workshop_request_submitted'         => ['label' => 'طلب برنامج لفريق التصميم',       'description' => 'يُرسل لفريق التصميم عند ورود طلب برنامج تدريبي جديد',                     'category' => 'applications'],
        'workshop_request_admin_notification'=> ['label' => 'طلب برنامج للإدارة',             'description' => 'يُرسل للمشرفين الإداريين عند ورود طلب برنامج تدريبي جديد',                'category' => 'applications'],
        'ambassador_confirmation'            => ['label' => 'تأكيد طلب السفراء (للمتقدم)',     'description' => 'يُرسل لمقدم طلب السفراء تأكيداً باستلام طلبه',                            'category' => 'applications'],
        'ambassador_admin_notification'      => ['label' => 'طلب سفراء جديد (للإدارة)',       'description' => 'يُرسل للقسم المختص عند ورود طلب انضمام سفير جديد',                        'category' => 'applications'],
        'ambassador_status_changed'          => ['label' => 'تحديث حالة طلب السفراء',         'description' => 'يُرسل لمقدم الطلب عند قبول أو رفض طلب انضمامه كسفير',                     'category' => 'applications'],
        // ── Certificates ──────────────────────────────────────────────────────
        'certificate_ready'                  => ['label' => 'شهادة جاهزة للتحميل',            'description' => 'يُرسل للطالب عند اكتمال إصدار الشهادة وتوفر ملف PDF',                     'category' => 'certificates'],
        'certificate_rejected'               => ['label' => 'رفض الشهادة',                    'description' => 'يُرسل للطالب عند رفض طلب اعتماد شهادته',                                  'category' => 'certificates'],
        'certificate_revoked'                => ['label' => 'إلغاء الشهادة',                  'description' => 'يُرسل للطالب عند إلغاء شهادة سبق إصدارها',                                'category' => 'certificates'],
        'certificate_needs_approval'         => ['label' => 'شهادة تنتظر الاعتماد',           'description' => 'يُرسل للمشرفين ومديري البرامج عند إنشاء شهادة جديدة',                     'category' => 'certificates'],
        'certificate_pdf_failed'             => ['label' => 'فشل إنشاء PDF للشهادة',          'description' => 'يُرسل للمشرفين التقنيين عند فشل توليد ملف الشهادة بعد كل المحاولات',       'category' => 'certificates'],
        // ── Oral Assessment ───────────────────────────────────────────────────
        'oral_assessment_booking_confirmed'    => ['label' => 'تأكيد حجز المقابلة الشفوية',      'description' => 'يُرسل للطالب والمدرب فور تأكيد حجز موعد المقابلة، مرفقاً بدعوة تقويم',        'category' => 'oral_assessment'],
        'oral_assessment_meeting_link_updated' => ['label' => 'تحديث رابط اجتماع المقابلة',      'description' => 'يُرسل للطالب عند إضافة أو تغيير أو حذف رابط اجتماع المقابلة الشفوية',          'category' => 'oral_assessment'],
        'oral_assessment_instructor_message'   => ['label' => 'رسالة من المدرب حول الموعد',      'description' => 'يُرسل للطالب عند إرسال المدرب رسالة بخصوص موعد المقابلة الشفوية',              'category' => 'oral_assessment'],
    ];

    private const CATEGORY_LABELS = [
        'applications'     => 'الطلبات',
        'support'          => 'الدعم الفني',
        'courses'          => 'الدورات',
        'certificates'     => 'الشهادات',
        'users'            => 'المستخدمون',
        'calendar'         => 'التقويم',
        'oral_assessment'  => 'المقابلة الشفوية',
        'payments'         => 'المدفوعات',
    ];

    public function index(): JsonResponse
    {
        $rows = EmailSetting::whereIn('key', array_keys(self::LABELS))->get()->keyBy('key');

        $settings = [];
        foreach (self::LABELS as $key => $meta) {
            $row = $rows->get($key);
            $settings[$key] = [
                'key'          => $key,
                'label'        => $meta['label'],
                'description'  => $meta['description'],
                'category'     => $meta['category'],
                'category_label' => self::CATEGORY_LABELS[$meta['category']] ?? $meta['category'],
                'enabled'      => $row ? (bool) $row->enabled : true,
                'updated_at'   => $row?->updated_at?->toIso8601String(),
            ];
        }

        $globalName  = EmailSetting::where('key', 'global_sender_name')->first();
        $globalEmail = EmailSetting::where('key', 'global_sender_email')->first();

        return response()->json([
            'data' => [
                'settings'     => $settings,
                'sender_name'  => $globalName?->sender_name ?? config('mail.from.name'),
                'sender_email' => $globalEmail?->sender_email ?? config('mail.from.address'),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings'               => ['sometimes', 'array'],
            'settings.*.enabled'     => ['sometimes', 'boolean'],
            'sender_name'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'sender_email'           => ['sometimes', 'nullable', 'email', 'max:150'],
        ]);

        if (!empty($data['settings'])) {
            foreach ($data['settings'] as $key => $values) {
                if (!array_key_exists($key, self::LABELS)) {
                    continue;
                }
                EmailSetting::updateOrCreate(
                    ['key' => $key],
                    ['enabled' => (bool) ($values['enabled'] ?? true)]
                );
            }
        }

        if (array_key_exists('sender_name', $data)) {
            EmailSetting::updateOrCreate(['key' => 'global_sender_name'], ['sender_name' => $data['sender_name']]);
        }
        if (array_key_exists('sender_email', $data)) {
            EmailSetting::updateOrCreate(['key' => 'global_sender_email'], ['sender_email' => $data['sender_email']]);
        }

        EmailSetting::clearAllCache();

        return response()->json(['message' => 'تم حفظ الإعدادات بنجاح.']);
    }

    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user?->email) {
            return response()->json(['message' => 'لا يوجد بريد إلكتروني لحسابك.'], 422);
        }

        EmailService::dispatch(
            'welcome',
            $user->email,
            $user->name,
            $user->id,
            new WelcomeMail($user->name, $user->email, null),
            'بريد تجريبي من منصة EMC'
        );

        return response()->json(['message' => "تم إرسال بريد تجريبي إلى {$user->email}"]);
    }
}
