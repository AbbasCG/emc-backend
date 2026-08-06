<?php

namespace App\Jobs\AI;

use App\AI\Services\AiInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate platform-wide AI insights on a schedule.
 *
 * Usage:
 *   GenerateAiInsightsJob::dispatch();
 *   // or via scheduler: Schedule::job(new GenerateAiInsightsJob)->weekly();
 */
class GenerateAiInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300;

    public function handle(AiInsightService $service): void
    {
        try {
            $insights = $service->generatePlatformInsights();
            Log::info('GenerateAiInsightsJob completed', ['count' => count($insights)]);
        } catch (\Throwable $e) {
            Log::error('GenerateAiInsightsJob failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
