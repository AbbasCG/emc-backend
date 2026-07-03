<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued certificate generation and issuance.
 *
 * Marks the certificate as 'issued' and optionally generates a PDF.
 * Integrates with CertificateService when available.
 *
 * Usage:
 *   IssueCertificateJob::dispatch($certificate);
 */
class IssueCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly Certificate $certificate,
    ) {}

    public function handle(): void
    {
        try {
            Log::info('[Certificate] IssueCertificateJob: generating PDF', [
                'certificate_id' => $this->certificate->id,
            ]);

            app(\App\Services\Certificate\CertificateRenderService::class)->storePdf($this->certificate);

            $this->certificate->update([
                'status'    => 'issued',
                'issued_at' => now(),
            ]);

            $user = $this->certificate->user;
            if ($user) {
                SendNotificationJob::dispatch($user, 'certificate_issued', [
                    'title'      => 'Your certificate is ready',
                    'message'    => 'Congratulations! Your certificate has been issued.',
                    'send_email' => true,
                ]);
            }

            Log::info('[Certificate] IssueCertificateJob: issued', [
                'certificate_id' => $this->certificate->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Certificate] IssueCertificateJob: failed', [
                'certificate_id' => $this->certificate->id,
                'error'          => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
