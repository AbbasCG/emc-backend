<?php

namespace App\Jobs;

use App\Models\Report;
use App\Models\User;
use App\Services\NotificationService;
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
 *
 * Ticket 8 canonical-architecture audit note: as of this pass, no live code
 * path dispatches this job (verified via a full-codebase grep for
 * `GenerateReportJob::dispatch` — only this docblock references it). Kept
 * for any future caller, updated to route its own notification through the
 * new canonical `NotificationService::reportReady()` scenario rather than
 * the deprecated `SendNotificationJob` — see
 * docs/architecture/ADR_NOTIFICATION_ARCHITECTURE.md.
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
                NotificationService::reportReady($this->requestedBy->id, $this->report->id);
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
