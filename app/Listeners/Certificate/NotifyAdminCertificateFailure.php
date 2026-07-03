<?php

namespace App\Listeners\Certificate;

use App\Events\Certificate\CertificatePdfFailed;
use App\Mail\CertificatePdfFailedMail;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class NotifyAdminCertificateFailure implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue   = 'notifications';
    public int    $tries   = 3;
    public int    $timeout = 30;

    // Only notify after all retries are exhausted
    private const NOTIFY_ON_ATTEMPT = 3;

    public function handle(CertificatePdfFailed $event): void
    {
        if ($event->attempt < self::NOTIFY_ON_ATTEMPT) {
            return;
        }

        $cert       = $event->certificate;
        $errorShort = mb_substr($event->exception->getMessage(), 0, 200);

        $message = "فشل إنشاء ملف الشهادة #{$cert->certificate_code} "
            . "للطالب «{$cert->recipient_name}» بعد {$event->attempt} محاولات. "
            . "السبب: {$errorShort}";

        $admins = User::where(function ($q) {
                $q->where('role', 'super_admin')
                  ->orWhere('role', 'admin')
                  ->orWhere('role', 'tech_admin');
            })
            ->where('is_active', true)
            ->get(['id', 'name', 'email']);

        foreach ($admins as $admin) {
            // In-app notification (idempotent)
            try {
                PlatformNotification::firstOrCreate(
                    [
                        'user_id'     => $admin->id,
                        'type'        => 'certificate_pdf_failed',
                        'entity_type' => \App\Models\Certificate::class,
                        'entity_id'   => $cert->id,
                    ],
                    [
                        'title'      => 'فشل إنشاء شهادة',
                        'message'    => $message,
                        'action_url' => '/dashboard/admin/certificates/' . $cert->id,
                        'icon'       => 'alert',
                        'color'      => 'red',
                        'is_read'    => false,
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('[CertificateFailureNotification] Admin in-app notification failed', [
                    'admin_id'       => $admin->id,
                    'certificate_id' => $cert->id,
                    'error'          => $e->getMessage(),
                ]);
            }

            // Email notification via EmailService (logged + toggleable)
            if ($admin->email) {
                EmailService::dispatch(
                    'certificate_pdf_failed',
                    $admin->email,
                    $admin->name,
                    $admin->id,
                    CertificatePdfFailedMail::fromCertificate(
                        $cert,
                        $event->exception->getMessage(),
                        $event->attempt,
                        $admin->name,
                    ),
                    'تنبيه: فشل إنشاء شهادة — ' . ($cert->certificate_code ?? $cert->id),
                );
            }
        }

        Log::error('[CertificateAutomation] PDF generation permanently failed', [
            'certificate_id'   => $cert->id,
            'certificate_code' => $cert->certificate_code,
            'recipient'        => $cert->recipient_name,
            'attempt'          => $event->attempt,
            'error'            => $event->exception->getMessage(),
        ]);
    }
}
