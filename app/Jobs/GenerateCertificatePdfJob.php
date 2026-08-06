<?php

namespace App\Jobs;

use App\Events\Certificate\CertificatePdfFailed;
use App\Events\Certificate\CertificatePdfGenerated;
use App\Models\Certificate;
use App\Models\CertificateGenerationBatch;
use App\Services\ActivityLogger;
use App\Services\Certificate\CertificateRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateCertificatePdfJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Attempts and timeout are driven by config/certificates.php so operators can
    // tune them without a code deploy.  uniqueFor is deliberately static: it must
    // exceed the worst-case wall time of all attempts + backoffs so the unique lock
    // is never released while a sibling job is still running.
    public int $uniqueFor = 1800; // 30 min — covers 120s × 3 attempts + 30+90+270s backoffs

    public function __construct(
        public readonly Certificate $certificate,
        public readonly ?int        $batchId = null,
        public readonly bool        $force   = false,
    ) {
        $this->tries   = config('certificates.max_pdf_attempts', 3);
        $this->timeout = config('certificates.pdf_timeout', 120);
        $this->onQueue(config('certificates.queue', 'certificates'));
    }

    public function uniqueId(): string
    {
        return 'cert-pdf-' . $this->certificate->id;
    }

    /** Exponential backoff: 30s → 90s → 270s */
    public function backoff(): array
    {
        return [30, 90, 270];
    }

    public function handle(CertificateRenderService $renderService): void
    {
        $fresh = $this->certificate->fresh();

        if (! $fresh) {
            Log::warning('[CertificatePdfJob] Certificate not found, skipping', [
                'certificate_id' => $this->certificate->id,
            ]);
            return;
        }

        // Idempotency: skip if already issued with a valid PDF (unless forced).
        // This check runs before the claim so a completed certificate never gets re-queued.
        if (! $this->force && $fresh->status === 'issued' && $fresh->pdf_path) {
            Log::info('[CertificatePdfJob] Skipping — already issued with PDF', [
                'certificate_id' => $fresh->id,
            ]);
            if ($this->batchId) {
                CertificateGenerationBatch::where('id', $this->batchId)->increment('generated_count');
                $this->checkBatchCompletion();
            }
            return;
        }

        // Atomically claim generation ownership with a single UPDATE.
        // Non-forced: only succeeds when pdf_generation_status != 'generating'.
        // Forced (admin retry): claims unconditionally so a stuck 'generating' state can be recovered.
        // If $claimed === 0 another worker already owns this generation — exit cleanly.
        $claimed = Certificate::where('id', $fresh->id)
            ->when(! $this->force, fn ($q) => $q->where('pdf_generation_status', '!=', 'generating'))
            ->update([
                'status'                => 'pending_generation',
                'pdf_generation_status' => 'generating',
                'pdf_last_error'        => null,
            ]);

        if ($claimed === 0) {
            Log::info('[CertificatePdfJob] Skipping — another worker already owns PDF generation', [
                'certificate_id' => $fresh->id,
            ]);
            return;
        }

        $fresh->refresh();

        try {
            $startedAt = microtime(true);

            Log::info('[CertificatePdfJob] Generating PDF', [
                'certificate_id'   => $fresh->id,
                'certificate_code' => $fresh->certificate_code,
                'batch_id'         => $this->batchId,
                'attempt'          => $this->attempts(),
                'force'            => $this->force,
            ]);

            $storedPath    = $renderService->storePdf($fresh);
            $generationMs  = (int) round((microtime(true) - $startedAt) * 1000);
            $fileSizeBytes = Storage::disk('local')->exists($storedPath)
                ? Storage::disk('local')->size($storedPath)
                : null;

            $fresh->update([
                'status'                => 'issued',
                'issued_at'             => now(),
                'pdf_generation_status' => 'ready',
                'pdf_generated_at'      => now(),
                'pdf_file_size'         => $fileSizeBytes,
                'template_id_used'      => $fresh->certificate_template_id,
                'pdf_last_error'        => null,
            ]);

            if ($this->batchId) {
                CertificateGenerationBatch::where('id', $this->batchId)->increment('generated_count');
                $this->checkBatchCompletion();
            }

            // Notify student (in-app) — also handled by SendCertificateReadyNotification via event
            SendCertificateNotificationJob::dispatch($fresh->fresh());

            // Fire event so listeners (email, audit, monitoring) can react
            event(new CertificatePdfGenerated($fresh->fresh()));

            ActivityLogger::log('certificate.pdf_generated', $fresh, [], null, null, [
                'attempt'         => $this->attempts(),
                'file_size'       => $fileSizeBytes,
                'template_id'     => $fresh->certificate_template_id,
                'generation_ms'   => $generationMs,
            ]);

            Log::info('[CertificatePdfJob] PDF generated successfully', [
                'certificate_id'  => $fresh->id,
                'file_size_bytes' => $fileSizeBytes,
                'generation_ms'   => $generationMs,
                'batch_id'        => $this->batchId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[CertificatePdfJob] PDF generation failed', [
                'certificate_id' => $fresh->id,
                'error'          => $e->getMessage(),
                'attempt'        => $this->attempts(),
                'batch_id'       => $this->batchId,
            ]);

            $fresh->update([
                'status'                => 'generation_failed',
                'pdf_generation_status' => 'failed',
                'pdf_last_error'        => mb_substr($e->getMessage(), 0, 1000),
            ]);

            if ($this->batchId) {
                CertificateGenerationBatch::where('id', $this->batchId)->increment('failed_count');
                $this->checkBatchCompletion();
            }

            // Fire failure event (NotifyAdminCertificateFailure listener reacts on final attempt)
            event(new CertificatePdfFailed($fresh->fresh(), $e, $this->attempts()));

            throw $e; // let the queue retry
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[CertificatePdfJob] Permanently failed after all retries', [
            'certificate_id' => $this->certificate->id,
            'batch_id'       => $this->batchId,
            'error'          => $exception->getMessage(),
        ]);

        // Ensure failed status persists even if handle() threw before the update
        $this->certificate->fresh()?->update([
            'status'                => 'generation_failed',
            'pdf_generation_status' => 'failed',
            'pdf_last_error'        => mb_substr($exception->getMessage(), 0, 1000),
        ]);

        // Final failure event with maxAttempts so NotifyAdmin definitely fires
        event(new CertificatePdfFailed(
            $this->certificate->fresh() ?? $this->certificate,
            $exception,
            $this->tries,
        ));
    }

    private function checkBatchCompletion(): void
    {
        if (! $this->batchId) {
            return;
        }

        // Read current counters from DB (increments are atomic SQL operations)
        $batch = CertificateGenerationBatch::find($this->batchId);
        if (! $batch || $batch->status !== 'processing') {
            return;
        }

        $done = $batch->generated_count + $batch->failed_count;
        if ($done < $batch->total_recipients) {
            return;
        }

        $newStatus = match (true) {
            $batch->failed_count > 0 && $batch->generated_count === 0 => 'failed',
            $batch->failed_count > 0                                   => 'completed_with_errors',
            default                                                     => 'completed',
        };

        // Atomic: only the first job that wins this UPDATE will proceed.
        // WHERE status = 'processing' prevents double-completion by concurrent jobs.
        $affected = CertificateGenerationBatch::where('id', $this->batchId)
            ->where('status', 'processing')
            ->update(['status' => $newStatus, 'completed_at' => now()]);

        if ($affected === 0) {
            // Another worker already marked this batch complete — nothing to do.
            return;
        }

        Log::info('[CertificatePdfJob] Batch complete', [
            'batch_id'        => $this->batchId,
            'status'          => $newStatus,
            'generated_count' => $batch->generated_count,
            'failed_count'    => $batch->failed_count,
        ]);

        if ($batch->failed_count > 0) {
            $this->notifyBatchAdmins($batch->fresh(), $newStatus);
        }
    }

    private function notifyBatchAdmins(\App\Models\CertificateGenerationBatch $batch, string $status): void
    {
        $label   = $status === 'failed' ? 'فشل' : 'اكتمل مع أخطاء';
        $message = "الإصدار الجماعي {$batch->batch_code} {$label}: "
            . "{$batch->generated_count} ناجح، {$batch->failed_count} فاشل.";

        $admins = \App\Models\User::whereIn('role', ['super_admin', 'admin', 'tech_admin'])
            ->where('is_active', true)
            ->pluck('id');

        foreach ($admins as $adminId) {
            try {
                \App\Models\PlatformNotification::firstOrCreate(
                    [
                        'user_id'     => $adminId,
                        'type'        => 'batch_completed_with_errors',
                        'entity_type' => \App\Models\CertificateGenerationBatch::class,
                        'entity_id'   => $batch->id,
                    ],
                    [
                        'title'      => 'اكتمال الإصدار الجماعي',
                        'message'    => $message,
                        'action_url' => '/dashboard/admin/certificates/batches',
                        'icon'       => $status === 'failed' ? 'alert' : 'warning',
                        'color'      => $status === 'failed' ? 'red' : 'orange',
                        'is_read'    => false,
                    ],
                );
            } catch (\Throwable) {
                // non-fatal
            }
        }
    }
}
