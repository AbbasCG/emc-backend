<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\User;
use App\Services\NotificationService;
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
 *
 * Ticket 8 canonical-architecture audit note: as of this pass, no live code
 * path dispatches this job (verified via a full-codebase grep for
 * `IssueCertificateJob::dispatch` — only this docblock references it; the
 * actual live certificate-issuance notification path is
 * `CertificateService`/`CertificateAutomationService` calling
 * `NotificationService::certificateIssued()` directly). Kept for any future
 * caller, updated to route its own notification through the canonical
 * `NotificationService::certificateIssued()` scenario rather than the
 * deprecated `SendNotificationJob` (which wrote to the disjoint, unused
 * Laravel built-in `notifications` table) — see
 * docs/architecture/ADR_NOTIFICATION_ARCHITECTURE.md.
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
                NotificationService::certificateIssued(
                    $user->id,
                    $this->certificate->id,
                    $this->certificate->course?->title ?? '',
                    $this->certificate->verification_code ?? '',
                );
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
