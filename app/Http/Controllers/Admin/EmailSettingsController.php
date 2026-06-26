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
    private const LABELS = [
        'welcome'                  => ['label' => 'بريد الترحيب', 'description' => 'يُرسل للمستخدم عند إنشاء حسابه لأول مرة'],
        'course_enrollment'        => ['label' => 'تسجيل في دورة', 'description' => 'يُرسل للطالب عند تسجيله في دورة تدريبية'],
        'learning_path_enrollment' => ['label' => 'تسجيل في مسار', 'description' => 'يُرسل للطالب عند انضمامه لمسار تعليمي'],
        'course_completion'        => ['label' => 'إتمام دورة', 'description' => 'يُرسل للطالب عند اجتيازه دورة بالكامل'],
        'learning_path_completion' => ['label' => 'إتمام مسار', 'description' => 'يُرسل للطالب عند إكماله المسار التعليمي كاملاً'],
        'assignment_submitted'     => ['label' => 'تسليم واجب (للمدرب)', 'description' => 'يُرسل للمدرب عندما يسلّم طالب واجبه'],
        'assignment_graded'        => ['label' => 'تقييم واجب (للطالب)', 'description' => 'يُرسل للطالب بعد تقييم المدرب لواجبه'],
        'session_reminder'         => ['label' => 'تذكير بالجلسة', 'description' => 'يُرسل قبل 24 ساعة وساعة واحدة من موعد الجلسة'],
        'certificate_issued'       => ['label' => 'إصدار شهادة', 'description' => 'يُرسل للطالب عند إصدار شهادة إتمامه'],
        'admin_new_registration'   => ['label' => 'تسجيل جديد (للإدارة)', 'description' => 'يُرسل للمشرفين عند كل تسجيل طالب جديد'],
        'certificate_issued'       => ['label' => 'إصدار شهادة', 'description' => 'يُرسل للطالب عند إصدار شهادة إتمامه'],
        'payment_confirmation'     => ['label' => 'تأكيد الدفع', 'description' => 'يُرسل للطالب عند تأكيد الدفع وإتمام التسجيل في الدورة'],
    ];

    public function index(): JsonResponse
    {
        $rows = EmailSetting::whereIn('key', array_keys(self::LABELS))->get()->keyBy('key');

        $settings = [];
        foreach (self::LABELS as $key => $meta) {
            $row = $rows->get($key);
            $settings[$key] = [
                'key'         => $key,
                'label'       => $meta['label'],
                'description' => $meta['description'],
                'enabled'     => $row ? (bool) $row->enabled : true,
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
