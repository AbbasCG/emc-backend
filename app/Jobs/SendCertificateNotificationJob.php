<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\PlatformNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCertificateNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Certificate $certificate,
    ) {}

    public function handle(): void
    {
        $certificate = $this->certificate;
        $certificate->loadMissing('user', 'course');

        $user = $certificate->user;
        if (! $user) {
            return;
        }

        try {
            PlatformNotification::create([
                'user_id'     => $user->id,
                'type'        => 'certificate_issued',
                'title'       => 'تم إصدار شهادتك',
                'message'     => 'تهانينا، تم إصدار شهادتك ويمكنك تحميلها من صفحة الشهادات.',
                'entity_type' => Certificate::class,
                'entity_id'   => $certificate->id,
                'action_url'  => '/dashboard/certificates',
                'icon'        => 'certificate',
                'color'       => 'green',
                'is_read'     => false,
            ]);

            Log::info('Certificate notification sent', [
                'user_id'        => $user->id,
                'certificate_id' => $certificate->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendCertificateNotificationJob failed', [
                'certificate_id' => $certificate->id,
                'error'          => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
