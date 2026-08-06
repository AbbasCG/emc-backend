<?php

namespace App\Listeners\Certificate;

use App\Events\Workshop\WorkshopCompleted;
use App\Services\Certificate\CertificateAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class IssueCertificateAfterWorkshopCompletion implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue   = 'certificates';
    public int    $tries   = 3;
    public int    $timeout = 60;

    public function handle(WorkshopCompleted $event): void
    {
        try {
            app(CertificateAutomationService::class)->issueForWorkshopCompletion($event->user, $event->workshop);
        } catch (\Throwable $e) {
            Log::error('[CertificateListener] WorkshopCompleted → automation failed', [
                'user_id'     => $event->user->id,
                'workshop_id' => $event->workshop->id,
                'error'       => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
