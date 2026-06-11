<?php

namespace App\Jobs;

use App\Models\UploadRecord;
use App\Services\CompressedUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessLargeUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int    $recordId,
        public readonly string $tmpPath
    ) {}

    public function handle(CompressedUploadService $service): void
    {
        $service->processQueued($this->recordId, $this->tmpPath);
    }

    public function failed(\Throwable $e): void
    {
        UploadRecord::find($this->recordId)?->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
