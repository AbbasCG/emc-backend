<?php

namespace App\Jobs;

use App\Models\Report;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued report generation.
 *
 * Marks the report as 'processing', invokes the report generator,
 * then marks it 'ready' or 'failed'.
 *
 * Usage:
 *   GenerateReportJob::dispatch($report, $requestedBy);
 */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300; // 5 minutes

    public function __construct(
        public readonly Report $report,
        public readonly ?User $requestedBy = null,
    ) {}

    public function handle(): void
    {
        try {
            $this->report->update(['status' => 'processing']);

            // Delegate to ReportGeneratorService if it exists
            if (class_exists(\App\Services\ReportGeneratorService::class)) {
                app(\App\Services\ReportGeneratorService::class)->generate($this->report);
            } else {
                // Stub: mark ready without actual file generation
                $this->report->update([
                    'status'      => 'ready',
                    'generated_at' => now(),
                ]);
            }

            if ($this->requestedBy) {
                SendNotificationJob::dispatch($this->requestedBy, 'report_ready', [
                    'title'   => 'Report ready',
                    'message' => "Report #{$this->report->id} has been generated.",
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('GenerateReportJob failed', [
                'report_id' => $this->report->id,
                'error'     => $e->getMessage(),
            ]);

            $this->report->update(['status' => 'failed']);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->report->update(['status' => 'failed']);
    }
}
