<?php

namespace App\Jobs\AI;

use App\AI\Services\AiInsightService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Usage:
 *   GenerateStudentRecommendationsJob::dispatch($user->id);
 */
class GenerateStudentRecommendationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 90;

    public function __construct(public readonly int $userId) {}

    public function handle(AiInsightService $service): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        try {
            $service->generateStudentRecommendations($user);
        } catch (\Throwable $e) {
            Log::error('GenerateStudentRecommendationsJob failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
