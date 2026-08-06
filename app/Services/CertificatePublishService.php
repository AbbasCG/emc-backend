<?php

namespace App\Services;

use App\Mail\CertificateReadyMail;
use App\Models\Certificate;
use App\Services\Certificate\CertificateRenderService;
use App\Services\EmailService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CertificatePublishService
{
    public function __construct(
        private readonly CertificateRenderService $renderService,
    ) {}

    /**
     * Generate the real PDF, store it, and mark the certificate as issued.
     * Called automatically when all approvals are received.
     */
    public function publish(Certificate $certificate): Certificate
    {
        try {
            Log::info('[Certificate] Publishing certificate', [
                'certificate_id'   => $certificate->id,
                'certificate_code' => $certificate->certificate_code,
            ]);

            $path = $this->renderService->storePdf($certificate);

            $certificate->update([
                'status'    => 'issued',
                'issued_at' => now(),
                'pdf_path'  => $path,
            ]);

            Log::info('[Certificate] Published successfully', [
                'certificate_id'   => $certificate->id,
                'certificate_code' => $certificate->certificate_code,
                'pdf_path'         => $path,
            ]);

            if ($certificate->user) {
                $this->notifyStudent($certificate);
            }

        } catch (\Throwable $e) {
            Log::error('[Certificate] Publish failed', [
                'certificate_id' => $certificate->id,
                'error'          => $e->getMessage(),
            ]);
            throw $e;
        }

        return $certificate->fresh();
    }

    /**
     * Revoke a certificate (admin action).
     */
    public function revoke(Certificate $certificate, string $reason): Certificate
    {
        if ($certificate->pdf_path) {
            Storage::disk('local')->delete($certificate->pdf_path);
        }

        $certificate->update([
            'status'        => 'revoked',
            'revoked_at'    => now(),
            'revoke_reason' => $reason,
            'pdf_path'      => null,
        ]);

        Log::info('[Certificate] Revoked', [
            'certificate_id' => $certificate->id,
            'reason'         => $reason,
        ]);

        return $certificate->fresh();
    }

    /**
     * Regenerate the certificate PDF (e.g., after template change).
     */
    public function regenerate(Certificate $certificate): Certificate
    {
        if ($certificate->pdf_path && Storage::disk('local')->exists($certificate->pdf_path)) {
            Storage::disk('local')->delete($certificate->pdf_path);
        }

        $certificate->update(['pdf_path' => null]);

        Log::info('[Certificate] Regenerating certificate PDF', [
            'certificate_id' => $certificate->id,
        ]);

        return $this->publish($certificate);
    }

    private function notifyStudent(Certificate $certificate): void
    {
        $user = $certificate->user;

        // In-app notification
        try {
            app(NotificationService::class)->certificateIssued(
                studentUserId:    $certificate->user_id,
                certificateId:    $certificate->id,
                courseTitle:      $certificate->course?->title ?? $certificate->title ?? 'الدورة',
                verificationCode: $certificate->verification_code ?? '',
            );
        } catch (\Throwable) {
            // never block publish
        }

        // Email — only once PDF is confirmed stored (called after storePdf succeeds)
        if ($user?->email) {
            try {
                EmailService::dispatch(
                    'certificate_ready',
                    $user->email,
                    $user->name,
                    $user->id,
                    CertificateReadyMail::fromCertificate($certificate->fresh()),
                    "شهادتك جاهزة للتحميل: " . ($certificate->course?->title ?? $certificate->title ?? 'شهادتك'),
                );
            } catch (\Throwable) {
                // never block publish
            }
        }
    }
}
