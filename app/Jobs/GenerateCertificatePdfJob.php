<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\CertificateGenerationBatch;
use App\Services\CertificatePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateCertificatePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly Certificate $certificate,
        public readonly ?int $batchId = null,
    ) {}

    public function handle(CertificatePdfService $pdfService): void
    {
        try {
            $pdfService->generate($this->certificate);

            $this->certificate->update([
                'status'    => 'issued',
                'issued_at' => now(),
            ]);

            if ($this->batchId) {
                CertificateGenerationBatch::where('id', $this->batchId)
                    ->increment('generated_count');

                $this->checkBatchCompletion();
            }

            // Notify student
            SendCertificateNotificationJob::dispatch($this->certificate);

            Log::info('Certificate PDF generated', ['certificate_id' => $this->certificate->id]);
        } catch (\Throwable $e) {
            Log::error('GenerateCertificatePdfJob failed', [
                'certificate_id' => $this->certificate->id,
                'error'          => $e->getMessage(),
            ]);

            if ($this->batchId) {
                CertificateGenerationBatch::where('id', $this->batchId)
                    ->increment('failed_count');
                $this->checkBatchCompletion();
            }

            $this->certificate->update(['status' => 'generation_failed']);

            throw $e;
        }
    }

    private function checkBatchCompletion(): void
    {
        if (! $this->batchId) {
            return;
        }

        $batch = CertificateGenerationBatch::find($this->batchId);
        if (! $batch) {
            return;
        }

        $done = $batch->generated_count + $batch->failed_count;

        if ($done >= $batch->total_recipients) {
            $batch->update([
                'status' => $batch->failed_count > 0 && $batch->generated_count === 0
                    ? 'failed'
                    : 'completed',
            ]);
        }
    }
}
