<?php

namespace App\Jobs\AI;

use App\AI\Services\AiMeetingService;
use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Usage:
 *   GenerateMeetingSummaryJob::dispatch($meeting->id, $requestedByUserId);
 */
class GenerateMeetingSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 90;

    public function __construct(
        public readonly int $meetingId,
        public readonly ?int $userId = null,
    ) {}

    public function handle(AiMeetingService $service): void
    {
        $meeting = Meeting::query()->find($this->meetingId);

        if (! $meeting) {
            return;
        }

        try {
            $service->summarizeMeeting($meeting, $this->userId);
        } catch (\Throwable $e) {
            Log::error('GenerateMeetingSummaryJob failed', ['meeting_id' => $this->meetingId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
