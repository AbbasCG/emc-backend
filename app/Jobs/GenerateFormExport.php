<?php

namespace App\Jobs;

use App\Models\FormExport;
use App\Services\Forms\FormExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Builds one export file (FormExportService::generate). Carries only the export's id: filters and columns are read from the record. */
class GenerateFormExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** One attempt only: a second run of the same export would just build the same file again. */
    public int $tries = 1;

    /**
     * Kept under the connections' `retry_after` (360 s in config/queue.php) so the queue never hands a still-running export to a
     * second worker; the worker (`--timeout=90` in docs/supervisor.conf) agrees. The slowest measured export (30,000 responses to
     * CSV) takes about 8 s; a job that overruns is failed with a message instead of being retried.
     */
    public int $timeout = 80;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $exportId) {}

    public function handle(FormExportService $exports): void
    {
        $export = FormExport::query()->find($this->exportId);
        if ($export !== null && $export->status === FormExport::QUEUED) {
            $exports->generate($export);
        }
    }

    public function failed(?\Throwable $e): void
    {
        FormExport::query()->whereKey($this->exportId)->whereIn('status', [FormExport::QUEUED, FormExport::PROCESSING])
            ->update(['status' => FormExport::FAILED, 'error' => 'تعذّر إنشاء ملف التصدير. حاول مرة أخرى.']);
    }
}
