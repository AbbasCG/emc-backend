<?php

namespace App\Listeners\Certificate;

use App\Events\LearningPath\LearningPathCompleted;
use App\Services\Certificate\CertificateAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class IssueCertificateAfterLearningPathCompletion implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue   = 'certificates';
    public int    $tries   = 3;
    public int    $timeout = 60;

    public function handle(LearningPathCompleted $event): void
    {
        try {
            app(CertificateAutomationService::class)->issueForLearningPathCompletion($event->user, $event->learningPath);
        } catch (\Throwable $e) {
            Log::error('[CertificateListener] LearningPathCompleted → automation failed', [
                'user_id'          => $event->user->id,
                'learning_path_id' => $event->learningPath->id,
                'error'            => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
