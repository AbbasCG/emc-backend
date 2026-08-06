<?php

namespace App\Jobs;

use App\Models\CertificateGenerationBatch;
use App\Services\CertificateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Legacy/fallback job — certificate rows are created synchronously in issueBulk().
 * If this job runs (e.g. old queued payload), it ensures records exist and PDF jobs are queued.
 */
class BulkCertificateIssueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public readonly array $userIds,
        public readonly array $params,
        public readonly int $batchId,
    ) {}

    public function handle(CertificateService $certificateService): void
    {
        $batch = CertificateGenerationBatch::find($this->batchId);
        if (! $batch) {
            Log::error('BulkCertificateIssueJob: batch not found', ['batch_id' => $this->batchId]);
            return;
        }

        $result = $certificateService->createBulkCertificates($this->userIds, $this->params, $batch);

        if ($result['created'] === 0) {
            $batch->update(['status' => 'failed']);
            return;
        }

        $batch->update([
            'status'           => 'processing',
            'total_recipients' => $result['created'],
            'failed_count'     => ($batch->failed_count ?? 0) + $result['skipped'],
        ]);

        foreach ($result['certificates'] as $cert) {
            GenerateCertificatePdfJob::dispatch($cert, $this->batchId);
        }
    }
}
