<?php

namespace App\Listeners\Certificate;

use App\Events\Certificate\CertificatePdfGenerated;
use App\Mail\CertificateReadyMail;
use App\Models\PlatformNotification;
use App\Services\EmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendCertificateReadyNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue   = 'notifications';
    public int    $tries   = 3;
    public int    $timeout = 30;

    public function handle(CertificatePdfGenerated $event): void
    {
        $cert = $event->certificate->loadMissing('user', 'course', 'workshop', 'track');
        $user = $cert->user;

        if (! $user) {
            return;
        }

        $entityName = $cert->course?->title
            ?? $cert->workshop?->title
            ?? $cert->track?->name
            ?? $cert->title
            ?? 'شهادتك';

        // ── In-app notification (idempotent) ─────────────────────────────────
        try {
            $exists = PlatformNotification::where('user_id', $user->id)
                ->where('type', 'certificate_ready')
                ->where('entity_type', \App\Models\Certificate::class)
                ->where('entity_id', $cert->id)
                ->exists();

            if (! $exists) {
                PlatformNotification::create([
                    'user_id'     => $user->id,
                    'type'        => 'certificate_ready',
                    'title'       => 'شهادتك جاهزة للتحميل',
                    'message'     => "تهانينا! شهادة «{$entityName}» جاهزة للتحميل.",
                    'entity_type' => \App\Models\Certificate::class,
                    'entity_id'   => $cert->id,
                    'action_url'  => '/dashboard/certificates',
                    'icon'        => 'certificate',
                    'color'       => 'green',
                    'is_read'     => false,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[CertificateNotification] In-app notification failed', [
                'certificate_id' => $cert->id,
                'error'          => $e->getMessage(),
            ]);
        }

        // ── Email via centralized EmailService (logged + toggleable) ──────────
        if (! $user->email) {
            return;
        }

        EmailService::dispatch(
            'certificate_ready',
            $user->email,
            $user->name,
            $user->id,
            CertificateReadyMail::fromCertificate($cert),
            "شهادتك جاهزة للتحميل: {$entityName}",
        );
    }
}
